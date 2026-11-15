<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\AllocationStatus;
use AichaDigital\Larabill\Enums\GroupedPaymentStatus;
use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\SaleReservationStage;
use AichaDigital\Larabill\Exceptions\SaleChargeMismatchException;
use AichaDigital\Larabill\Exceptions\SaleReservationNotFoundException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\OneOffSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\SaleSettlementRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * C3/C4 — settle() (AID-1997 spec §4.3, D4/D7): four resumable stages over
 * the reserved proforma — S1 record the charge, S2 convert, S3 issue, S4
 * collect through the C1 transition and ONE allocation. A mismatching
 * charge is refused with ZERO writes (D4); each stage is resumable by the
 * same key; a Settled reservation is a replay proving the evidence, not
 * today's state (D7).
 */

beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
    $this->service  = app(OneOffSaleService::class);
});

function aid1997sSettled(string $customerId, ?OneOffSaleService $service = null): array
{
    $service ??= app(OneOffSaleService::class);
    $proforma    = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customerId,
        'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);
    $service->reserve((string) $proforma->id, 'settle-op');

    $result = $service->settle(new SaleSettlementRequest(
        'settle-op',
        $customerId,
        FixedDecimal::ofUnscaled(10000, 2),
        'EUR',
        now()->subMinute()->startOfSecond(),
        PaymentMethod::Card,
        'pi_settle',
        'the-operator',
    ));

    return [$proforma->fresh(), $result];
}

it('settles a reserved sale: payment, invoice, allocation, C1 transition, Settled', function () {
    [$proforma, $result] = aid1997sSettled($this->customer->id);

    $invoice = $result->invoice;

    expect($result->reservation->stage->value)->toBe(SaleReservationStage::Settled->value)
        ->and($invoice->sale_reservation_id)->toBe($result->reservation->id)
        ->and($invoice->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($invoice->status)->toBe(InvoiceStatus::PAID)
        ->and($result->payment->status)->toBe(GroupedPaymentStatus::POSTED)
        ->and($result->allocation->status)->toBe(AllocationStatus::ACTIVE)
        // The money went to the sale and nowhere else: zero credit movements for the payment.
        ->and(DB::table('credit_movements')->where('grouped_payment_id', $result->payment->id)->count())->toBe(0)
        // The proforma is frozen forever (D6) and CONVERTED.
        ->and($proforma->is_immutable)->toBeTrue()
        ->and($proforma->status)->toBe(InvoiceStatus::CONVERTED);
});

it('refuses a mismatching charge with zero writes (D4)', function () {
    [$proforma, $reservation] = [
        (function () {
            $p = app(InvoiceService::class)->createProforma([
                'billable_user_id' => $this->customer->id,
                'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
            ]);
            $p->update(['status' => InvoiceStatus::SENT->value]);

            return $p->fresh();
        })(),
        null,
    ];
    $this->service->reserve((string) $proforma->id, 'settle-op');

    $before = DB::table('grouped_payments')->count();

    try {
        $this->service->settle(new SaleSettlementRequest(
            'settle-op',
            $this->customer->id,
            FixedDecimal::ofUnscaled(5000, 2), // WRONG: the expectation froze 10000
            'EUR',
            now()->subMinute()->startOfSecond(),
            PaymentMethod::Card,
            'pi_settle',
            'the-operator',
        ));
        $this->fail('a mismatching charge must refuse');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(SaleChargeMismatchException::class);
    }

    // Zero writes: no payment, no invoice, the reservation still Reserved.
    expect(DB::table('grouped_payments')->count())->toBe($before)
        ->and(DB::table('invoices')->where('sale_reservation_id', '!=', null)->count())->toBe(0)
        ->and($this->service->diagnose('settle-op')->stage->value)->toBe(SaleReservationStage::Reserved->value);
});

it('replays a Settled sale proving the evidence, not today\'s state (D7)', function () {
    [$proforma, $first] = aid1997sSettled($this->customer->id);

    $second = $this->service->settle(new SaleSettlementRequest(
        'settle-op',
        $this->customer->id,
        FixedDecimal::ofUnscaled(10000, 2),
        'EUR',
        now()->subMinute()->startOfSecond(),
        PaymentMethod::Card,
        'pi_settle',
        'the-operator',
    ));

    expect($second->reservation->id)->toBe($first->reservation->id)
        ->and($second->payment->id)->toBe($first->payment->id)
        ->and($second->invoice->id)->toBe($first->invoice->id)
        ->and($second->allocation->id)->toBe($first->allocation->id)
        // Still exactly one of each.
        ->and(GroupedPayment::where('idempotency_key', 'settle-op')->count())->toBe(1);
});

it('refuses settle of a non-reserved or released key', function () {
    $this->service->settle(new SaleSettlementRequest(
        'no-such-key',
        $this->customer->id,
        FixedDecimal::ofUnscaled(10000, 2),
        'EUR',
        now()->subMinute()->startOfSecond(),
        PaymentMethod::Card,
        'pi_x',
        'the-operator',
    ));
})->throws(SaleReservationNotFoundException::class);
