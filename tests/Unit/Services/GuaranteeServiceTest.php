<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\GuaranteeOperationPendingException;
use AichaDigital\Larabill\Exceptions\IllegalAgreementTransitionException;
use AichaDigital\Larabill\Exceptions\InvalidSettlementAmountException;
use AichaDigital\Larabill\Exceptions\InvoiceNotRectifiableException;
use AichaDigital\Larabill\Models\AgreementGuaranteeOperation;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\GuaranteeService;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-953 slice 2 (ADR-015 §2.6 + §3.4) — the GUARANTEE feature.
 *
 * Per-product refund window with a transient `guarantee` state, an
 * operations registry, and rectificative wiring through the AID-971
 * settlement service. Two-phase collision-and-adoption settlement (gate
 * rounds 10–13): the UNIQUE `settlement_operation_key` index on invoices is
 * the serialisation point — no interleaving can issue two fiscal
 * corrections for one claim, and a Phase-2 failure after a committed Phase 1
 * is adopted on retry (the interleaving fork proof lives in
 * tests/Concurrency/GuaranteeSettlementConcurrencyTest.php).
 *
 * Registry-only stance (ADR-015 §2.6): larabill records; the consumer
 * decides client policy — in particular, eligibility BY DATE is never
 * enforced inside `openOperation()`; `guaranteeWindowEndsAt()` only exposes
 * the window math.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->service = app(GuaranteeService::class);
});

/**
 * A tax-free sealed SENT invoice (the recurring-emission precedent,
 * AID-836): €100.00 base, no tax group → zero tax. Enough for the
 * rectificative path to clone a single-rate line. Helper name UNIQUE across
 * the suite (AID-502) — file-level Pest helpers share the global namespace
 * of a combined run.
 */
function guaranteeSourceInvoiceFor(TestUser $billable, Article $article): Invoice
{
    return app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $billable->id,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            [
                'article_id'  => $article->id,
                'quantity'    => 100,
                'base_price'  => 10000,
                'description' => 'Hosting mensual',
            ],
        ],
    ], ['make_immutable' => true]);
}

/**
 * A guarantee-carrying product with an ACTIVE agreement for the customer,
 * plus its paid source invoice. Returns [agreement, originalInvoice].
 *
 * @return array{0: ArticleServiceStatus, 1: Invoice}
 */
function guaranteeFixtureFor(TestUser $billable, int $productDays = 14, int $snapshotDays = 14): array
{
    $article = Article::factory()
        ->withGuarantee($productDays)
        ->create();

    $agreement = ArticleServiceStatus::factory()
        ->forCustomer($billable->id)
        ->forArticle($article)
        ->create([
            'guarantee_days' => $snapshotDays,
            'started_at'     => Carbon::parse('2026-03-01'),
        ]);

    return [$agreement, guaranteeSourceInvoiceFor($billable, $article)];
}

it('openOperation records a pending registry row and moves the agreement into the guarantee state', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));

    $operation = $this->service->openOperation($agreement, $original, 5000);

    expect($operation->status)->toBe(AgreementGuaranteeOperation::STATUS_PENDING)
        ->and($operation->pending_slot)->toBe(AgreementGuaranteeOperation::PENDING_SLOT)
        ->and($operation->agreement_id)->toBe($agreement->id)
        ->and($operation->original_invoice_id)->toBe($original->id)
        ->and($operation->amount->unscaledValue())->toBe(5000)
        ->and($operation->rectifies_invoice_id)->toBeNull();

    expect($agreement->fresh()->status->value)->toBe('guarantee');
});

it('openOperation rejects a concurrent duplicate with the typed exception — one pending operation per agreement', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));

    $this->service->openOperation($agreement, $original, 5000);

    $this->service->openOperation($agreement, $original, 5000);
})->throws(GuaranteeOperationPendingException::class);

it('openOperation rolls back the registry row when the agreement cannot enter the guarantee state', function () {
    // A SUSPENDED agreement has no edge to `guarantee` (§3.1 state machine).
    $agreement = ArticleServiceStatus::factory()->suspended()->create([
        'customer_id' => TestCase::USER_UUID_1,
    ]);
    $original = guaranteeSourceInvoiceFor(TestUser::find(TestCase::USER_UUID_1), $agreement->article);

    $this->service->openOperation($agreement, $original, 5000);
})->throws(IllegalAgreementTransitionException::class);

it('openOperation rejects an invoice outside the agreement billable context', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));

    $foreignOriginal = guaranteeSourceInvoiceFor(TestUser::find(TestCase::USER_UUID_2), $agreement->article);

    $this->service->openOperation($agreement, $foreignOriginal, 5000);
})->throws(InvalidArgumentException::class);

it('openOperation keeps the registry empty when the billable-context validation fails', function () {
    [$agreement]     = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));
    $foreignOriginal = guaranteeSourceInvoiceFor(TestUser::find(TestCase::USER_UUID_2), $agreement->article);

    try {
        $this->service->openOperation($agreement, $foreignOriginal, 5000);
        $this->fail('Expected an exception');
    } catch (InvalidArgumentException) {
        // expected
    }

    expect(AgreementGuaranteeOperation::query()->count())->toBe(0);
});

it('openOperation rejects a non-positive refund amount — zero and negative', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));

    try {
        $this->service->openOperation($agreement, $original, 0);
        $this->fail('Expected InvalidSettlementAmountException for zero');
    } catch (InvalidSettlementAmountException) {
        // expected
    }

    try {
        $this->service->openOperation($agreement, $original, -100);
        $this->fail('Expected InvalidSettlementAmountException for a negative amount');
    } catch (InvalidSettlementAmountException) {
        // expected
    }

    expect(AgreementGuaranteeOperation::query()->count())->toBe(0);
});

it('settle issues the rectificative stamped with the operation key and records it on the registry', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));
    $operation              = $this->service->openOperation($agreement, $original, 5000);

    $rectificative = $this->service->settle($operation);

    $expectedKey = 'guarantee_ops:'.$operation->getKey();

    expect($rectificative->serie->value)->toBe(3) // InvoiceSerieType::RECTIFICATIVE
        ->and($rectificative->rectifies_invoice_id)->toBe($original->id)
        ->and($rectificative->settlement_operation_key)->toBe($expectedKey)
        ->and($rectificative->total_amount->unscaledValue())->toBeLessThan(0);

    $operation->refresh();

    expect($operation->rectifies_invoice_id)->toBe($rectificative->id)
        // The refund is NOT confirmed yet: the registry stays pending and the
        // sentinel keeps guarding the agreement (Phase 2 records the link only).
        ->and($operation->status)->toBe(AgreementGuaranteeOperation::STATUS_PENDING)
        ->and($operation->pending_slot)->toBe(AgreementGuaranteeOperation::PENDING_SLOT)
        ->and($agreement->fresh()->status->value)->toBe('guarantee');
});

it('confirmRefund completes the registry and closes the agreement with the client_request cause', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));
    $operation              = $this->service->openOperation($agreement, $original, 5000);
    $rectificative          = $this->service->settle($operation);

    $this->service->confirmRefund($operation);

    $operation->refresh();

    expect($operation->status)->toBe(AgreementGuaranteeOperation::STATUS_REFUNDED)
        ->and($operation->refunded_at)->not->toBeNull()
        ->and($operation->pending_slot)->toBeNull()
        ->and($operation->rectifies_invoice_id)->toBe($rectificative->id);

    $closed = $agreement->fresh();

    expect($closed->status->value)->toBe('closed')
        ->and($closed->closureCause->key)->toBe('client_request');
});

it('an unconfirmed refund leaves the agreement in the guarantee state — no automatic closure', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));
    $operation              = $this->service->openOperation($agreement, $original, 5000);

    // The rectificative is issued, but the refund is never confirmed.
    $this->service->settle($operation);

    expect($agreement->fresh()->status->value)->toBe('guarantee');
});

it('confirmRefund records the refund without demanding a settlement — registry-only stance, consumer owns the flow order', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));
    $operation              = $this->service->openOperation($agreement, $original, 5000);

    $this->service->confirmRefund($operation);

    $operation->refresh();

    expect($operation->status)->toBe(AgreementGuaranteeOperation::STATUS_REFUNDED)
        ->and($operation->pending_slot)->toBeNull()
        ->and($operation->rectifies_invoice_id)->toBeNull()
        ->and($agreement->fresh()->status->value)->toBe('closed');
});

it('settle is an idempotent no-op once the operation is refunded', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));
    $operation              = $this->service->openOperation($agreement, $original, 5000);
    $rectificative          = $this->service->settle($operation);
    $this->service->confirmRefund($operation);

    $again = $this->service->settle($operation);

    expect($again?->id)->toBe($rectificative->id)
        ->and(Invoice::query()->where('rectifies_invoice_id', $original->id)->count())->toBe(1);
});

it('settle adopts a committed issuance instead of issuing a second correction — Phase-2 failure after a committed Phase 1', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));
    $operation              = $this->service->openOperation($agreement, $original, 5000);

    $key = $this->service->operationKey($operation);

    // Simulate the winner: Phase 1 ISSUED and committed, but the process died
    // before Phase 2 — the registry row is still pending with no link.
    $winner = app(SettlementService::class)->rectify($original, -5000, $key);
    expect($winner->settlement_operation_key)->toBe($key)
        ->and($operation->fresh()->rectifies_invoice_id)->toBeNull();

    // Retry: the loser's own issuance collides on the UNIQUE key; it adopts
    // the winner's artifact instead of issuing a second correction.
    $adopted = $this->service->settle($operation);

    expect($adopted?->id)->toBe($winner->id)
        ->and(Invoice::query()->where('settlement_operation_key', $key)->count())->toBe(1)
        ->and($operation->fresh()->rectifies_invoice_id)->toBe($winner->id);
});

it('settle refuses loud on an unrestorable original and leaves the registry untouched', function () {
    [$agreement, $original] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1));
    $operation              = $this->service->openOperation($agreement, $original, 5000);

    // The original was never emitted (draft): rectify() refuses it. The
    // status write goes through save() deliberately — the immutability
    // update() guard protects fiscal content, not collection/lifecycle state
    // (the markAsPaidViaGroupedPayment() precedent).
    $original->status = InvoiceStatus::DRAFT;
    $original->save();

    try {
        $this->service->settle($operation);
        $this->fail('Expected InvoiceNotRectifiableException');
    } catch (InvoiceNotRectifiableException) {
        // expected — nothing was issued
    }

    $operation->refresh();

    expect($operation->rectifies_invoice_id)->toBeNull()
        ->and($operation->status)->toBe(AgreementGuaranteeOperation::STATUS_PENDING);
});

it('the guarantee window is computed from the agreement frozen snapshot, never from the live product', function () {
    [$agreement] = guaranteeFixtureFor(TestUser::find(TestCase::USER_UUID_1), productDays: 14, snapshotDays: 14);

    // Gate round 6 P2: changing the product later never moves an existing right.
    $agreement->article->update(['guarantee_days' => 3]);

    $windowEnd = $this->service->guaranteeWindowEndsAt($agreement->fresh());

    expect($windowEnd?->toDateString())->toBe('2026-03-15'); // 2026-03-01 + 14 days
});

it('a NULL or zero snapshot means no guarantee window', function () {
    $article = Article::factory()->withGuarantee(14)->create();

    $nullSnapshot = ArticleServiceStatus::factory()
        ->forCustomer(TestCase::USER_UUID_1)
        ->forArticle($article)
        ->create(['guarantee_days' => null]);

    $zeroSnapshot = ArticleServiceStatus::factory()
        ->forCustomer(TestCase::USER_UUID_2)
        ->forArticle($article)
        ->create(['guarantee_days' => 0]);

    expect($this->service->guaranteeWindowEndsAt($nullSnapshot))->toBeNull()
        ->and($this->service->guaranteeWindowEndsAt($zeroSnapshot))->toBeNull();
});
