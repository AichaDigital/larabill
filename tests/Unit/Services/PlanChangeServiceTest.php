<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\AgreementStatus;
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\IllegalAgreementTransitionException;
use AichaDigital\Larabill\Exceptions\MissingContractPriceException;
use AichaDigital\Larabill\Exceptions\PlanChangeNotAllowedException;
use AichaDigital\Larabill\Models\AgreementCause;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\PlanChangeService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-953 slice 3 (ADR-015 §3.5) — the plan change policy.
 *
 * The contract under test:
 *
 * - Eligibility: only ACTIVE agreements with NO `close_scheduled_at` are
 *   plan-changed (gate rounds 6 P2 / 7 P1 #4 / 10 P2). `suspended` and
 *   `guarantee` refuse typed; a scheduled close refuses typed (the route is
 *   `::cancelScheduledClose()` first, §3.2); `align_end` requires a
 *   closed-term predecessor and is NEVER silently reinterpreted.
 * - Atomic/idempotent boundary (gate round 6 P1): re-invocation against an
 *   already-closed row is an idempotent no-op returning null — the forked
 *   interleaving proof lives in tests/Concurrency/PlanChangeConcurrencyTest.php.
 * - Fiscal artifact PINNED (ADR-014:43-46, 74-76; spec §3.5): the abono is
 *   an ordinary F1 invoice with a negative line and `rectifies_invoice_id`
 *   NULL — implementing it through `rectify()` would reclassify R1 and is
 *   a defect.
 * - Successor contract (gate round 7 S1, now decided): a NEW contract with
 *   its own `started_at` and its OWN guarantee snapshot taken from the
 *   product's CURRENT `guarantee_days` — the predecessor's window does not
 *   transfer.
 * - Config (AID-589): the default lives AT THE READ POINT
 *   (`config('larabill.agreements.plan_change.policy', 'restart')`), and
 *   any value outside `restart`/`align_end` throws typed loud.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->service = app(PlanChangeService::class);
});

/**
 * The plan-change agreement fixture: an ACTIVE recurring agreement on a
 * tax-free article. Helper name UNIQUE across the suite (AID-502).
 */
function planChangeAgreement(array $attributes = []): ArticleServiceStatus
{
    $article = Article::factory()->service()->monthly(10000)->create();

    return ArticleServiceStatus::factory()->create(array_merge([
        'article_id' => $article->id,
    ], $attributes));
}

/**
 * The NEW plan: a service article carrying a deterministic catalogue price
 * for the monthly cadence (the successor's contractual price resolves from
 * it unless the caller passes one explicitly).
 */
function planChangeNewPlan(array $attributes = []): Article
{
    return Article::factory()->service()->monthly(5000)->create($attributes);
}

/**
 * The emitted invoice whose unconsumed proportion is liquidated by the plan
 * change. Tax-free article: the abono line carries zero tax, so the pinning
 * test stays focused on the artifact shape, not the tax math.
 */
function planChangeSourceInvoice(Article $article, TestUser|string $customer): Invoice
{
    return app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $customer instanceof TestUser ? $customer->id : $customer,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            [
                'article_id'  => $article->id,
                'quantity'    => 100,
                'base_price'  => 10000,
                'description' => 'Old plan service period',
            ],
        ],
    ], ['make_immutable' => true]);
}

describe('eligibility (ADR-015 §3.5, gate rounds 6 P2 / 7 P1 #4 / 10 P2)', function () {
    it('refuses a suspended agreement with a typed refusal and issues nothing', function () {
        $agreement = planChangeAgreement(['status' => AgreementStatus::SUSPENDED]);
        $newPlan   = planChangeNewPlan();

        expect(fn () => $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1)))
            ->toThrow(PlanChangeNotAllowedException::class);

        $fresh = $agreement->fresh();
        expect($fresh->status)->toBe(AgreementStatus::SUSPENDED)
            ->and(ArticleServiceStatus::query()->whereKeyNot($agreement->id)->count())->toBe(0)
            ->and(Invoice::query()->count())->toBe(0);
    });

    it('refuses an agreement inside its guarantee window with a typed refusal and issues nothing', function () {
        $agreement = planChangeAgreement(['status' => AgreementStatus::GUARANTEE]);
        $newPlan   = planChangeNewPlan();

        expect(fn () => $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1)))
            ->toThrow(PlanChangeNotAllowedException::class);

        $fresh = $agreement->fresh();
        expect($fresh->status)->toBe(AgreementStatus::GUARANTEE)
            ->and(ArticleServiceStatus::query()->whereKeyNot($agreement->id)->count())->toBe(0)
            ->and(Invoice::query()->count())->toBe(0);
    });

    it('refuses an agreement with a scheduled close and issues nothing — cancelScheduledClose() is the route first', function () {
        $agreement = planChangeAgreement(['close_scheduled_at' => now()->addDays(10)->toDateString()]);
        $newPlan   = planChangeNewPlan();

        try {
            $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1));
            $this->fail('Expected PlanChangeNotAllowedException for a scheduled close.');
        } catch (PlanChangeNotAllowedException $e) {
            expect($e->getMessage())->toContain('close scheduled')
                ->and($e->getMessage())->toContain('cancelScheduledClose');
        }

        $fresh = $agreement->fresh();
        expect($fresh->status)->toBe(AgreementStatus::ACTIVE)
            ->and($fresh->close_scheduled_at)->not->toBeNull()
            ->and(ArticleServiceStatus::query()->whereKeyNot($agreement->id)->count())->toBe(0)
            ->and(Invoice::query()->count())->toBe(0);
    });

    it('refuses a pending agreement as requires-active', function () {
        $agreement = planChangeAgreement(['status' => AgreementStatus::PENDING]);
        $newPlan   = planChangeNewPlan();

        expect(fn () => $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1)))
            ->toThrow(IllegalAgreementTransitionException::class);

        expect(ArticleServiceStatus::query()->whereKeyNot($agreement->id)->count())->toBe(0);
    });

    it('refuses align_end on an auto-renewing agreement — never silently reinterpreted (gate round 10 P2)', function () {
        $agreement = planChangeAgreement(['renewal' => 'auto', 'expires_at' => null]);
        $newPlan   = planChangeNewPlan();

        try {
            $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1), policy: 'align_end');
            $this->fail('Expected PlanChangeNotAllowedException for align_end on an auto agreement.');
        } catch (PlanChangeNotAllowedException $e) {
            expect($e->getMessage())->toContain('closed_term')
                ->and($e->getMessage())->toContain('auto');
        }

        // The refusal must not have closed or duplicated anything.
        $fresh = $agreement->fresh();
        expect($fresh->status)->toBe(AgreementStatus::ACTIVE)
            ->and($fresh->renewal)->toBe('auto')
            ->and(ArticleServiceStatus::query()->whereKeyNot($agreement->id)->count())->toBe(0);
    });

    it('runs the happy restart path: predecessor closed, successor created, successor runs its full term', function () {
        $customer  = TestUser::factory()->create();
        $agreement = planChangeAgreement(['customer_id' => $customer->id, 'renewal' => 'closed_term', 'expires_at' => now()->addMonths(8)]);
        $newPlan   = planChangeNewPlan();

        $successor = $this->service->changePlan(
            $agreement,
            $newPlan,
            new BillingInterval('month', 1),
            effectiveAt: now()->addDays(3),
        );

        expect($successor)->toBeInstanceOf(ArticleServiceStatus::class)
            ->and($successor->id)->not->toBe($agreement->id)
            ->and($agreement->fresh()->status)->toBe(AgreementStatus::CLOSED)
            ->and($agreement->fresh()->next_billing_date)->toBeNull()
            // restart = full term from the change date: no forced end, auto renewal.
            ->and($successor->renewal)->toBe('auto')
            ->and($successor->expires_at)->toBeNull()
            ->and($successor->status)->toBe(AgreementStatus::ACTIVE)
            ->and($successor->modality->value)->toBe('recurring')
            ->and($successor->billing_unit)->toBe('month')
            ->and($successor->billing_quantity)->toBe(1);
    });

    it('runs the happy align_end path: the successor ends at the predecessor term end', function () {
        $customer  = TestUser::factory()->create();
        $expiresAt = now()->addMonths(4)->startOfDay();
        $agreement = planChangeAgreement(['customer_id' => $customer->id, 'renewal' => 'closed_term', 'expires_at' => $expiresAt]);
        $newPlan   = planChangeNewPlan();

        $successor = $this->service->changePlan(
            $agreement,
            $newPlan,
            new BillingInterval('month', 1),
            policy: 'align_end',
            effectiveAt: now()->addDays(3),
        );

        expect($successor)->toBeInstanceOf(ArticleServiceStatus::class)
            ->and($successor->renewal)->toBe('closed_term')
            ->and($successor->expires_at?->toDateString())->toBe($expiresAt->toDateString())
            ->and($agreement->fresh()->status)->toBe(AgreementStatus::CLOSED);
    });

    it('re-invocation against an already-closed agreement is an idempotent no-op returning null (gate round 6 P1)', function () {
        $agreement = planChangeAgreement(['status' => AgreementStatus::CLOSED]);
        $newPlan   = planChangeNewPlan();

        expect($this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1)))->toBeNull()
            ->and(ArticleServiceStatus::query()->whereKeyNot($agreement->id)->count())->toBe(0)
            ->and(Invoice::query()->count())->toBe(0);
    });
});

describe('fiscal artifact (ADR-014:43-46,74-76 — the abono is an ordinary F1, NOT an R1)', function () {
    it('issues the liquidation as an ordinary invoice with a negative first line and rectifies_invoice_id NULL', function () {
        $customer  = TestUser::factory()->create();
        $agreement = planChangeAgreement(['customer_id' => $customer->id]);
        $newPlan   = planChangeNewPlan();
        $source    = planChangeSourceInvoice($agreement->article, $customer);

        $successor = $this->service->changePlan(
            $agreement,
            $newPlan,
            new BillingInterval('month', 1),
            settleInvoice: $source,
            settleAmount: 5000,
        );

        expect($successor)->toBeInstanceOf(ArticleServiceStatus::class);

        $abonos = Invoice::query()->whereKeyNot($source->id)->get();
        expect($abonos)->toHaveCount(1);

        $abono = $abonos->first();
        // THE PINNED ARTIFACT: ordinary F1 invoice (serie INVOICE, type
        // 'invoice'), rectifies_invoice_id NULL — the ADR-014 discriminant.
        // A rectificative path here would reclassify it R1 and is a defect.
        expect($abono->serie)->toBe(InvoiceSerieType::INVOICE)
            ->and($abono->rectifies_invoice_id)->toBeNull()
            ->and($abono->status)->toBe(InvoiceStatus::SENT)
            // The credit line comes FIRST and is negative.
            ->and((int) $abono->items->first()->unit_price->unscaledValue())->toBe(-5000)
            // Current fiscal context on the abono line (ADR-014 §3): the
            // line's provenance travels in ITS metadata (§5), no frozen
            // amounts travel with it.
            ->and($abono->items->first()->metadata['settles_invoice_id'])->toBe($source->id);

        // The source invoice was NOT touched and carries no rectificative.
        expect($source->fresh()->status)->toBe(InvoiceStatus::SENT)
            ->and($source->rectificatives()->count())->toBe(0);
    });

    it('issues no artifact when the settlement amount is absent or zero — zero proportion, zero abono', function () {
        $customer  = TestUser::factory()->create();
        $agreement = planChangeAgreement(['customer_id' => $customer->id]);
        $newPlan   = planChangeNewPlan();
        $source    = planChangeSourceInvoice($agreement->article, $customer);

        // Null amount: the consumer decided no liquidation rides this change.
        $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1), settleInvoice: $source);

        expect(Invoice::query()->whereKeyNot($source->id)->count())->toBe(0);

        // A second plan change on the same fresh fixture, zero amount: same.
        $agreement2 = planChangeAgreement(['customer_id' => $customer->id]);
        $this->service->changePlan($agreement2, $newPlan, new BillingInterval('month', 1), settleInvoice: $source, settleAmount: 0);

        expect(Invoice::query()->whereKeyNot($source->id)->count())->toBe(0)
            ->and($agreement2->fresh()->status)->toBe(AgreementStatus::CLOSED);
    });

    it('refuses a settlement amount without the invoice to liquidate', function () {
        $agreement = planChangeAgreement();
        $newPlan   = planChangeNewPlan();

        expect(fn () => $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1), settleAmount: 5000))
            ->toThrow(InvalidArgumentException::class);

        expect($agreement->fresh()->status)->toBe(AgreementStatus::ACTIVE)
            ->and(Invoice::query()->count())->toBe(0);
    });
});

describe('successor contract (gate round 7 S1 — decided: own window, own start)', function () {
    it('gives the successor its own started_at and next_billing_date at the change date', function () {
        $customer  = TestUser::factory()->create();
        $agreement = planChangeAgreement(['customer_id' => $customer->id, 'started_at' => now()->subMonths(2)]);
        $newPlan   = planChangeNewPlan();
        $changeAt  = now()->addDays(3)->startOfDay();

        $successor = $this->service->changePlan(
            $agreement,
            $newPlan,
            new BillingInterval('month', 1),
            effectiveAt: $changeAt,
        );

        expect($successor->started_at?->toDateString())->toBe($changeAt->toDateString())
            ->and($successor->next_billing_date?->toDateString())->toBe($changeAt->toDateString())
            ->and($successor->customer_id)->toBe($customer->id)
            ->and($successor->article_id)->toBe($newPlan->id);
    });

    it('gives the successor its OWN guarantee snapshot from the product CURRENT value — the predecessor window does not transfer', function () {
        $customer  = TestUser::factory()->create();
        // The predecessor contracted when the product carried a 7-day window.
        $oldPlan   = Article::factory()->service()->monthly(10000)->withGuarantee(7)->create();
        $agreement = ArticleServiceStatus::factory()->create([
            'customer_id'    => $customer->id,
            'article_id'     => $oldPlan->id,
            'guarantee_days' => 7,
        ]);
        // The new plan carries 14 days at contracting time...
        $newPlan = Article::factory()->service()->monthly(5000)->withGuarantee(14)->create();

        $successor = $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1));

        expect($successor->guarantee_days)->toBe(14)
            // ...and the predecessor's snapshot is untouched by the change.
            ->and($agreement->fresh()->guarantee_days)->toBe(7);

        // A product change AFTER contracting never moves an existing right:
        // the successor froze the value at ITS contracting.
        $newPlan->update(['guarantee_days' => 30]);
        expect($successor->fresh()->guarantee_days)->toBe(14);
    });

    it('resolves the successor contractual price from the explicit input, else the new plan catalogue', function () {
        $agreement = planChangeAgreement();
        $newPlan   = planChangeNewPlan();

        $successor = $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1));
        expect($successor->effective_price?->unscaledValue())->toBe(5000);

        $agreement2 = planChangeAgreement(['customer_id' => $agreement->customer_id]);
        $successor2 = $this->service->changePlan(
            $agreement2,
            $newPlan,
            new BillingInterval('month', 1),
            successorEffectivePrice: 6500,
        );
        expect($successor2->effective_price?->unscaledValue())->toBe(6500);
    });

    it('throws MissingContractPriceException when no explicit price and no catalogue price for the cadence', function () {
        $agreement = planChangeAgreement();
        // A plan whose price only exists for a yearly cadence.
        $yearlyPlan = Article::factory()->service()->yearly(120000)->create();

        expect(fn () => $this->service->changePlan($agreement, $yearlyPlan, new BillingInterval('month', 1)))
            ->toThrow(MissingContractPriceException::class);

        expect($agreement->fresh()->status)->toBe(AgreementStatus::ACTIVE);
    });

    it('records the plan-change provenance on the successor metadata', function () {
        $agreement = planChangeAgreement();
        $newPlan   = planChangeNewPlan();

        $successor = $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1));

        expect($successor->metadata['plan_change']['predecessor_agreement_id'])->toBe($agreement->id)
            ->and($successor->metadata['plan_change']['policy'])->toBe('restart');
    });

    it('closes the predecessor through the close/suspend path accepting an explicit closed-scope cause', function () {
        $agreement = planChangeAgreement();
        $newPlan   = planChangeNewPlan();
        $cause     = AgreementCause::findActive(AgreementCause::SCOPE_CLOSED, 'client_request');

        $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1), closureCause: $cause);

        $fresh = $agreement->fresh();
        expect($fresh->status)->toBe(AgreementStatus::CLOSED)
            ->and($fresh->closure_cause_id)->toBe($cause->id);
    });
});

describe('config policy (AID-589: the default lives at the read point)', function () {
    it('ships the restart default in the published config', function () {
        $shipped = require dirname(__DIR__, 3).'/config/larabill.php';

        expect($shipped['agreements']['plan_change']['policy'])->toBe('restart');
    });

    it('throws typed loud on a policy value outside the restart/align_end domain', function () {
        config()->set('larabill.agreements.plan_change.policy', 'prorate');
        $agreement = planChangeAgreement();
        $newPlan   = planChangeNewPlan();

        try {
            $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1));
            $this->fail('Expected PlanChangeNotAllowedException for an unknown policy value.');
        } catch (PlanChangeNotAllowedException $e) {
            expect($e->getMessage())->toContain('prorate')
                ->and($e->getMessage())->toContain('restart');
        }

        // Same refusal for an explicit override — no silent reinterpretation.
        expect(fn () => $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1), policy: 'whatever'))
            ->toThrow(PlanChangeNotAllowedException::class);

        expect($agreement->fresh()->status)->toBe(AgreementStatus::ACTIVE);
    });

    it('lets an explicit policy override win over the configured one', function () {
        config()->set('larabill.agreements.plan_change.policy', 'align_end');
        $expiresAt = now()->addMonths(2)->startOfDay();
        $agreement = planChangeAgreement(['renewal' => 'closed_term', 'expires_at' => $expiresAt]);
        $newPlan   = planChangeNewPlan();

        // Explicit restart overrides the configured align_end: full term.
        $successor = $this->service->changePlan(
            $agreement,
            $newPlan,
            new BillingInterval('month', 1),
            policy: 'restart',
        );

        expect($successor->renewal)->toBe('auto')
            ->and($successor->expires_at)->toBeNull();
    });

    it('falls back to restart at the read point when a consumer config predates the key', function () {
        // mergeConfigFrom is shallow (AID-589): a consumer whose published
        // config predates the key must still get the spec default. Simulate
        // by removing the whole subtree.
        config(['larabill.agreements.plan_change' => null]);

        $expiresAt = now()->addMonths(2)->startOfDay();
        $agreement = planChangeAgreement(['renewal' => 'closed_term', 'expires_at' => $expiresAt]);
        $newPlan   = planChangeNewPlan();

        $successor = $this->service->changePlan($agreement, $newPlan, new BillingInterval('month', 1));

        // Default restart: NOT aligned to the predecessor term.
        expect($successor->renewal)->toBe('auto')
            ->and($successor->expires_at)->toBeNull();
    });
});
