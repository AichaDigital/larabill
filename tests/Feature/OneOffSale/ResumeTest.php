<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\SaleReservationStage;
use AichaDigital\Larabill\Exceptions\FiscalConfigChangedException;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\SaleChargeMismatchException;
use AichaDigital\Larabill\Exceptions\SaleIntegrityException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\PaymentAllocation;
use AichaDigital\Larabill\Models\SaleReservation;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\OneOffSaleService;
use AichaDigital\Larabill\Services\PaymentService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\SaleSettlementRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * AID-2063: settle() promises four RESUMABLE stages (spec §4.3). A sale whose
 * S1 committed (stage Paid) or whose S3 marker committed (stage Issuing) must
 * resume forward under the same key and the same request: S1 re-validates
 * the request against the frozen expectation and the locked proforma, then
 * returns the recorded payment WITHOUT writing; S2-S4 complete. A mismatching
 * payload stays a zero-write refusal.
 */

beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
    $this->service  = app(OneOffSaleService::class);

    $this->profile = UserTaxProfile::query()->create([
        'owner_user_id'        => $this->customer->id,
        'fiscal_name'          => 'Resume Customer S.L.',
        'tax_id'               => 'B12345678',
        'country_code'         => 'ES',
        'is_company'           => true,
        'is_eu_vat_registered' => true,
        'is_active'            => true,
        'valid_from'           => now()->subDay(),
    ]);
    $this->customer->update(['current_tax_profile_id' => $this->profile->id]);

    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id'    => $this->customer->id,
        'user_tax_profile_id' => (string) $this->profile->id,
        'items'               => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);
    $this->proforma = $proforma->fresh();
    $this->service->reserve((string) $this->proforma->id, 'resume-op');
    $this->receivedAt = now()->subMinute()->startOfSecond();
});

function aid2063Request(object $test, ?FixedDecimal $amount = null): SaleSettlementRequest
{
    return new SaleSettlementRequest(
        'resume-op',
        $test->customer->id,
        $amount ?? $test->proforma->total_amount,
        'EUR',
        $test->receivedAt,
        PaymentMethod::Card,
        'pi_resume',
        'the-operator',
    );
}

/** Settle once with S2 failing on a critical fiscal change; leaves the sale at Paid. */
function aid2063LeaveAtPaid(object $test): GroupedPayment
{
    $test->profile->forceFill(['tax_id' => 'B87654321'])->saveQuietly();

    try {
        $test->service->settle(aid2063Request($test));
        throw new RuntimeException('S2 must fail on the critical fiscal change');
    } catch (FiscalConfigChangedException) {
        // expected: S1 committed, S2 rolled back
    }

    $reservation = SaleReservation::query()->where('operation_key', 'resume-op')->firstOrFail();
    expect($reservation->stage)->toBe(SaleReservationStage::Paid)
        ->and(GroupedPayment::where('idempotency_key', 'resume-op')->count())->toBe(1)
        ->and(PaymentAllocation::query()->count())->toBe(0);

    $test->profile->forceFill(['tax_id' => 'B12345678'])->saveQuietly();

    return GroupedPayment::where('idempotency_key', 'resume-op')->firstOrFail();
}

function aid2063AssertSettled(object $test, string $paymentId): void
{
    $reservation = SaleReservation::query()->where('operation_key', 'resume-op')->firstOrFail();
    $invoices    = Invoice::query()->where('sale_reservation_id', $reservation->id)->get();
    $allocations = PaymentAllocation::query()->get();

    expect($reservation->stage)->toBe(SaleReservationStage::Settled)
        ->and((string) $reservation->grouped_payment_id)->toBe($paymentId)
        ->and(GroupedPayment::query()->count())->toBe(1)
        ->and(GroupedPayment::query()->firstOrFail()->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($invoices)->toHaveCount(1)
        ->and($invoices->first()->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($invoices->first()->status)->toBe(InvoiceStatus::PAID)
        ->and((string) $invoices->first()->proforma_id)->toBe((string) $test->proforma->id)
        ->and($allocations)->toHaveCount(1)
        ->and((string) $allocations->first()->invoice_id)->toBe((string) $invoices->first()->id)
        ->and($allocations->first()->applied_amount->toScale(2)->compareTo($test->proforma->total_amount->toScale(2)))->toBe(0)
        ->and(Invoice::query()->whereNotNull('fiscal_number')->where('serie', '!=', 0)->count())->toBe(1)
        // One correlative consumed on the counter itself, not only one numbered row (gate R1 P2-5).
        ->and((int) DB::table('invoice_series_control')->where('serie', '!=', 0)->sum('last_number'))->toBe(1)
        ->and(DB::table('credit_movements')->count())->toBe(0);
}

/** The raw grouped_payments rows, to prove a replay wrote nothing (gate R1 P2-5). */
function aid2063PaymentRows(): array
{
    return DB::table('grouped_payments')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

it('resumes a sale left at Paid to Settled with the same payment', function () {
    $payment = aid2063LeaveAtPaid($this);
    $rows    = aid2063PaymentRows();

    $result = $this->service->settle(aid2063Request($this));

    expect((string) $result->payment->id)->toBe((string) $payment->id)
        ->and(aid2063PaymentRows())->toEqual($rows);
    aid2063AssertSettled($this, (string) $payment->id);
});

it('resumes a sale left at Issuing to Settled with the same payment', function () {
    // Crash window before issue(): S1, S2 and the Issuing marker committed,
    // the number never committed. A one-shot veto on issue()'s numbering
    // write rolls issue() back and leaves the reservation at Issuing.
    $armed = true;
    Invoice::updating(function (Invoice $invoice) use (&$armed): bool {
        if ($armed && $invoice->isDirty('fiscal_number') && $invoice->fiscal_number !== null) {
            $armed = false;

            return false; // veto the numbering write of issue()
        }

        return true;
    });

    $thrown = null;

    try {
        $this->service->settle(aid2063Request($this));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    $reservation = SaleReservation::query()->where('operation_key', 'resume-op')->firstOrFail();
    expect($reservation->stage)->toBe(SaleReservationStage::Issuing)
        ->and(Invoice::query()->where('sale_reservation_id', $reservation->id)->firstOrFail()->invoice_document_status)
        ->toBe(InvoiceDocumentStatus::PREPARED);

    $payment = GroupedPayment::where('idempotency_key', 'resume-op')->firstOrFail();
    $rows    = aid2063PaymentRows();

    $result = $this->service->settle(aid2063Request($this));

    expect((string) $result->payment->id)->toBe((string) $payment->id)
        ->and(aid2063PaymentRows())->toEqual($rows);
    aid2063AssertSettled($this, (string) $payment->id);
});

it('refuses a different amount at Paid with zero writes', function () {
    aid2063LeaveAtPaid($this);

    $before = [
        'payments'    => DB::table('grouped_payments')->get()->toArray(),
        'reservation' => DB::table('sale_reservations')->get()->toArray(),
        'invoices'    => DB::table('invoices')->get()->toArray(),
        'allocations' => DB::table('grouped_payment_invoice')->count(),
    ];

    expect(fn () => $this->service->settle(aid2063Request($this, FixedDecimal::ofUnscaled(1, 2))))
        ->toThrow(SaleChargeMismatchException::class);

    expect(DB::table('grouped_payments')->get()->toArray())->toEqual($before['payments'])
        ->and(DB::table('sale_reservations')->get()->toArray())->toEqual($before['reservation'])
        ->and(DB::table('invoices')->get()->toArray())->toEqual($before['invoices'])
        ->and(DB::table('grouped_payment_invoice')->count())->toBe($before['allocations']);
});

it('refuses a different reference at Paid as a key conflict with zero writes', function () {
    $payment = aid2063LeaveAtPaid($this);
    $before  = DB::table('sale_reservations')->get()->toArray();

    $request = new SaleSettlementRequest(
        'resume-op',
        $this->customer->id,
        $this->proforma->total_amount,
        'EUR',
        $this->receivedAt,
        PaymentMethod::Card,
        'pi_OTHER',
        'the-operator',
    );

    expect(fn () => $this->service->settle($request))->toThrow(IdempotencyConflictException::class);

    expect(GroupedPayment::query()->count())->toBe(1)
        ->and(DB::table('sale_reservations')->get()->toArray())->toEqual($before)
        ->and(Invoice::query()->where('sale_reservation_id', '!=', null)->count())->toBe(0)
        ->and((string) GroupedPayment::query()->firstOrFail()->id)->toBe((string) $payment->id);
});

/** Settle once with issue()'s numbering write vetoed; leaves the sale at Issuing with a PREPARED invoice. */
function aid2063LeaveAtIssuing(object $test): void
{
    $armed = true;
    Invoice::updating(function (Invoice $invoice) use (&$armed): bool {
        if ($armed && $invoice->isDirty('fiscal_number') && $invoice->fiscal_number !== null) {
            $armed = false;

            return false;
        }

        return true;
    });

    try {
        $test->service->settle(aid2063Request($test));
    } catch (Throwable) {
        // expected: issue() rolled back, the Issuing marker committed
    }

    expect(SaleReservation::query()->where('operation_key', 'resume-op')->firstOrFail()->stage)
        ->toBe(SaleReservationStage::Issuing);
}

/** A OneOffSaleService that runs $between after S3 has returned and before S4 starts. */
function aid2063ServiceWithHookBeforeCollect(Closure $between): OneOffSaleService
{
    return new class(app(InvoiceService::class), app(PaymentService::class), $between) extends OneOffSaleService
    {
        public function __construct(InvoiceService $invoices, PaymentService $payments, private Closure $between)
        {
            parent::__construct($invoices, $payments);
        }

        protected function issueForSale(SaleReservation $reservation, Invoice $invoice): Invoice
        {
            $issued = parent::issueForSale($reservation, $invoice);
            ($this->between)();

            return $issued;
        }
    };
}

it('refuses to number a resumed sale invoice when the fiscal currency drifted (gate R1 P1-1)', function () {
    aid2063LeaveAtIssuing($this);
    CompanyFiscalConfig::query()->update(['currency' => 'USD']);
    $counterBefore = DB::table('invoice_series_control')->get()->toArray();

    $thrown = null;

    try {
        $this->service->settle(aid2063Request($this));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $reservation = SaleReservation::query()->where('operation_key', 'resume-op')->firstOrFail();
    $invoice     = Invoice::query()->where('sale_reservation_id', $reservation->id)->firstOrFail();

    expect($thrown)->toBeInstanceOf(SaleIntegrityException::class)
        ->and($thrown->getMessage())->toContain('currency')
        ->and($reservation->stage)->toBe(SaleReservationStage::Issuing)
        ->and($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::PREPARED)
        ->and($invoice->fiscal_number)->toBeNull()
        ->and(DB::table('invoice_series_control')->get()->toArray())->toEqual($counterBefore)
        ->and(DB::table('grouped_payment_invoice')->count())->toBe(0);
});

it('refuses to collect when the funding payment was reversed between S3 and S4 (gate R1 P1-2)', function () {
    $service = aid2063ServiceWithHookBeforeCollect(function (): void {
        $payment = GroupedPayment::where('idempotency_key', 'resume-op')->firstOrFail();
        app(PaymentService::class)->reverse($payment, 'chargeback before collection');
    });

    expect(fn () => $service->settle(aid2063Request($this)))->toThrow(SaleIntegrityException::class);

    $reservation = SaleReservation::query()->where('operation_key', 'resume-op')->firstOrFail();
    expect($reservation->stage)->toBe(SaleReservationStage::Issuing)
        ->and($reservation->allocation_id)->toBeNull()
        ->and(DB::table('grouped_payment_invoice')->count())->toBe(0)
        ->and(Invoice::query()->where('sale_reservation_id', $reservation->id)->firstOrFail()->status)->not->toBe(InvoiceStatus::PAID);
});

it('refuses to collect when the payment currency differs from the issued document (gate R1, S4 currency)', function () {
    $service = aid2063ServiceWithHookBeforeCollect(function (): void {
        DB::table('grouped_payments')->where('idempotency_key', 'resume-op')->update(['currency' => 'USD']);
    });

    expect(fn () => $service->settle(aid2063Request($this)))->toThrow(SaleIntegrityException::class);

    $reservation = SaleReservation::query()->where('operation_key', 'resume-op')->firstOrFail();
    expect($reservation->stage)->toBe(SaleReservationStage::Issuing)
        ->and(DB::table('grouped_payment_invoice')->count())->toBe(0);
});
