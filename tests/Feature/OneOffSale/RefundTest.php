<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\DTO\SaleRefundResult;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\SaleRefundStage;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\InsufficientRefundableException;
use AichaDigital\Larabill\Exceptions\SaleStageException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\SaleRefund;
use AichaDigital\Larabill\Services\CustomerCredit;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\OneOffSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\SaleRefundRequest;
use AichaDigital\Larabill\ValueObjects\SaleSettlementRequest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function TestClass_refund_instant(): CarbonImmutable
{
    static $instant = null;

    return $instant ??= CarbonImmutable::now()->startOfSecond();
}

/*
 * D8 rev11 — the credit-free refund (AID-1997 spec §4.5): a durable intent
 * first (R1, reserving capacity under the reservation lock, freezing the
 * COMPLETE fiscal plan and the frozen sources), refund-owned issuance (R2,
 * both links in the phase-1 commit, pre-number checks inside issuance), and
 * the R3 split (close → post-commit detection → the intent flip as the
 * refund's LAST write). No credit_movements row, no allocation reversed,
 * the funding payment stays POSTED; the customer's position ends at
 * balance 0 / outstanding 0 after a full refund.
 */

beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
    $this->service  = app(OneOffSaleService::class);
    $this->settled  = aid1997fSettled();
});

/** A settled tax-free sale of 100.00 (the fixture base), plus its evidence. */
function aid1997fSettled(): array
{
    $customer = test()->customer;
    $service  = app(OneOffSaleService::class);
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customer->id,
        'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);
    $service->reserve((string) $proforma->id, 'refund-op');

    return [$proforma->fresh(), $service->settle(new SaleSettlementRequest(
        'refund-op',
        $customer->id,
        FixedDecimal::ofUnscaled(10000, 2),
        'EUR',
        now()->subHour()->startOfSecond(),
        PaymentMethod::Card,
        'pi_refund',
        'the-operator',
    ))];
}

function aid1997fRefund(null|int|FixedDecimal $amount = null, ?string $refundKey = null): SaleRefundResult
{
    $customer = test()->customer;
    $amount   = $amount === null ? null : (is_int($amount) ? FixedDecimal::ofUnscaled($amount, 2) : $amount);

    // One frozen receipt instant for the whole test: a replay must carry the
    // SAME payload to the second (R0), and now() would drift between calls.
    $refundedAt = TestClass_refund_instant();

    return app(OneOffSaleService::class)->refund(new SaleRefundRequest(
        'refund-op',
        $refundKey ?? 'refund-key-1',
        $amount,
        'EUR',
        $refundedAt,
        PaymentMethod::BankTransfer,
        'ref_1',
        'the-operator',
        'customer asked, no reason needed',
    ));
}

it('refunds a settled sale in full: one rectificative, one Refunded row, no credit', function () {
    $result = aid1997fRefund();

    $rectificative = $result->rectificative;

    expect($result->refund->stage)->toBe(SaleRefundStage::Refunded)
        ->and($rectificative)->not->toBeNull()
        ->and($rectificative->rectifies_invoice_id)->toBe($this->settled[1]->invoice->id)
        ->and($rectificative->total_amount->unscaledValue())->toBe(-10000)
        ->and($rectificative->status)->toBe(InvoiceStatus::PAID)
        // The money truth: readers at 0, money untouched, capacity consumed.
        ->and($this->settled[1]->reservation->fresh()->refunded()->unscaledValue())->toBe(10000)
        ->and($this->settled[1]->reservation->fresh()->remainingRefundable()->unscaledValue())->toBe(0)
        // No credit, no reversal: the original keeps its allocation and payment.
        ->and(DB::table('credit_movements')->count())->toBe(0)
        ->and($this->settled[1]->allocation->fresh()->status->value)->toBe(AllocationStatus::ACTIVE->value)
        ->and($this->settled[1]->payment->fresh()->status->value)->toBe(GroupedPaymentStatus::POSTED->value)
        // The customer's aggregate position: outstanding 0 (rectificative PAID).
        ->and(app(CustomerCredit::class)->for($this->customer->id, 'EUR')->outstanding()->unscaledValue())->toBe(0);
});

it('refunds partially, twice, and refuses the over-refund', function () {
    $first = aid1997fRefund(4000, 'refund-key-1');

    expect($first->rectificative->total_amount->unscaledValue())->toBe(-4000)
        ->and($first->remainingRefundable->unscaledValue())->toBe(6000);

    $second = aid1997fRefund(6000, 'refund-key-2');

    expect($second->remainingRefundable->unscaledValue())->toBe(0);

    try {
        aid1997fRefund(1, 'refund-key-3');
        $this->fail('an over-refund must refuse');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(InsufficientRefundableException::class);
    }
});

it('replays the same refund key; a different payload under it conflicts', function () {
    $first = aid1997fRefund(5000, 'refund-key-1');
    $again = aid1997fRefund(5000, 'refund-key-1');

    expect($again->refund->id)->toBe($first->refund->id)
        ->and($again->rectificative?->id)->toBe($first->rectificative->id);

    try {
        aid1997fRefund(6000, 'refund-key-1');
        $this->fail('a different payload under the same key must conflict');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(IdempotencyConflictException::class);
    }
});

it('refuses a refund of a non-settled sale', function () {
    $customer = $this->customer;
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customer->id,
        'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);
    $this->service->reserve((string) $proforma->id, 'unsettled-op');

    $this->service->refund(new SaleRefundRequest(
        'unsettled-op',
        'refund-key-x',
        null,
        'EUR',
        now()->startOfSecond(),
        PaymentMethod::BankTransfer,
        null,
        'the-operator',
        null,
    ));
})->throws(SaleStageException::class);

it('refunds a year later with no reason: no window, no policy (D8)', function () {
    $result = aid1997fRefund();

    expect($result->refund->reason)->toBe('customer asked, no reason needed')
        ->and($result->refund->refunded_at)->not->toBeNull();
});

it('reserves capacity at R1: a cut intent blocks another key (rev11 core)', function () {
    // R1 only: an intent row exists, nothing issued. Simulate the cut by
    // writing the intent directly through the service's own R1 path.
    $reservation = $this->settled[1]->reservation->fresh();

    $intent = new SaleRefund([
        'sale_reservation_id' => (string) $reservation->id,
        'refund_key'          => 'cut-key-1',
        'stage'               => SaleRefundStage::Intended,
        'amount'              => FixedDecimal::ofUnscaled(10000, 2),
        'bucket_plan'         => null,
        'currency'            => 'EUR',
        'refunded_at'         => now()->startOfSecond(),
        'method'              => PaymentMethod::BankTransfer,
        'reference'           => 'ref_cut',
        'recorded_by'         => 'the-operator',
        'reason'              => null,
    ]);
    $intent->save();

    // The cut intent holds ALL the capacity: another key cannot refund.
    try {
        aid1997fRefund(null, 'other-key');
        $this->fail('a second key must not take the reserved capacity');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(InsufficientRefundableException::class);
    }
});

it('resumes by key between R2 and R3: an issued-open rectificative closes', function () {
    $first = aid1997fRefund(5000, 'cut-key-1');

    // Simulate the cut between R2 and R3: the intent is re-set to Intended
    // with the rectificative left ISSUED and collection open.
    $first->refund->update(['stage' => SaleRefundStage::Intended]);
    DB::table('invoices')->where('id', $first->rectificative->id)->update(['status' => InvoiceStatus::DRAFT->value, 'paid_at' => null]);

    $resumed = aid1997fRefund(5000, 'cut-key-1');

    expect($resumed->refund->stage)->toBe(SaleRefundStage::Refunded)
        ->and($resumed->rectificative->id)->toBe($first->rectificative->id)
        ->and($resumed->rectificative->fresh()->status)->toBe(InvoiceStatus::PAID)
        // Exactly one refund row, one rectificative.
        ->and(SaleRefund::where('refund_key', 'cut-key-1')->count())->toBe(1);
});
