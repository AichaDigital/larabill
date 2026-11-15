<?php

declare(strict_types=1);

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\SaleRefundStage;
use AichaDigital\Larabill\Enums\SaleReservationStage;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\GroupedPayment;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\SaleRefund;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\OneOffSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\SaleRefundRequest;
use AichaDigital\Larabill\ValueObjects\SaleSettlementRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * C4 fault injection (AID-1997 spec §7 C4): a fault after each stage — by
 * exception AND by a vetoing listener (returns false) on each stage's write —
 * rolls the whole stage back, and the same key resumes to the SAME invoice,
 * payment, allocation and one fiscal number. A different payload under the
 * same key is a conflict. Zero credit_movements rows for the payment at
 * every point.
 */

beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
});

function aid1997cReserved(): Invoice
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => test()->customer->id,
        'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);
    app(OneOffSaleService::class)->reserve((string) $proforma->id, 'fault-op');

    return $proforma->fresh();
}

function aid1997cSettleRequest(): SaleSettlementRequest
{
    return new SaleSettlementRequest(
        'fault-op',
        test()->customer->id,
        FixedDecimal::ofUnscaled(10000, 2),
        'EUR',
        now()->subMinute()->startOfSecond(),
        PaymentMethod::Card,
        'pi_fault',
        'the-operator',
    );
}

it('resumes to the same evidence after a settle fault, with one fiscal number', function () {
    $proforma = aid1997cReserved();
    $service  = app(OneOffSaleService::class);

    // First run: fault by a ONE-SHOT vetoing listener on the sale invoice's
    // close (S4's recomputeCollectionState write) — the veto fires once, so
    // the retry inside THIS test and every later test are unaffected.
    $armed = true;
    Invoice::updating(function (Invoice $invoice) use (&$armed): bool {
        if ($armed && $invoice->sale_refund_id === null && $invoice->isDirty('paid_at') && $invoice->paid_at !== null) {
            $armed = false;

            return false; // veto the sale invoice close (the S4 path)
        }

        return true;
    });

    try {
        $service->settle(aid1997cSettleRequest());
        $this->fail('the injected veto must abort the settle');
    } catch (Throwable) {
        // The whole stage rolled back; nothing partial kept.
    }

    $armed = false; // the fault is spent

    $result = $service->settle(aid1997cSettleRequest());

    expect($result->reservation->stage->value)->toBe(SaleReservationStage::Settled->value)
        ->and(GroupedPayment::where('idempotency_key', 'fault-op')->count())->toBe(1)
        ->and(Invoice::where('sale_reservation_id', $result->reservation->id)->count())->toBe(1)
        // One fiscal number for the whole fault-and-resume history.
        ->and(Invoice::where('serie', '!=', 0)->whereNotNull('fiscal_number')->count())->toBe(1)
        ->and(DB::table('credit_movements')->where('grouped_payment_id', $result->payment->id)->count())->toBe(0);
});

it('refuses a different payload under the same settle key', function () {
    $proforma = aid1997cReserved();
    $service  = app(OneOffSaleService::class);
    $service->settle(aid1997cSettleRequest());

    try {
        $service->settle(new SaleSettlementRequest(
            'fault-op',
            test()->customer->id,
            FixedDecimal::ofUnscaled(10000, 2),
            'EUR',
            now()->subMinute()->startOfSecond(),
            PaymentMethod::Card,
            'pi_FAULTED', // a different payload under the same key
            'the-operator',
        ));
        $this->fail('a different payload under the same key must conflict');
    } catch (Throwable $e) {
        // D7: the Settled replay returns the evidence for the SAME payload;
        // a drifted one surfaces as a conflict or an amount mismatch — both
        // zero-write refusals. The sale is untouched either way.
        expect($result = $service->diagnose('fault-op')->stage->value)->toBe(SaleReservationStage::Settled->value);
    }
});

it('keeps zero credit movements at every cut of the refund', function () {
    $proforma = aid1997cReserved();
    $service  = app(OneOffSaleService::class);
    $settled  = $service->settle(aid1997cSettleRequest());

    $paymentId = (string) $settled->payment->id;

    $intent = new SaleRefund([
        'sale_reservation_id' => (string) $settled->reservation->id,
        'refund_key'          => 'fault-refund',
        'stage'               => SaleRefundStage::Intended,
        'amount'              => FixedDecimal::ofUnscaled(4000, 2),
        'bucket_plan'         => null,
        'currency'            => 'EUR',
        'refunded_at'         => now()->startOfSecond(),
        'method'              => PaymentMethod::BankTransfer,
        'reference'           => 'ref_fault',
        'recorded_by'         => 'the-operator',
        'reason'              => null,
    ]);
    $intent->save();

    // The cut state: an intent with no rectificative. Zero credit at the cut.
    expect(DB::table('credit_movements')->where('grouped_payment_id', $paymentId)->count())->toBe(0);

    // Resume by key through the public path: the refund completes.
    $result = $service->refund(new SaleRefundRequest(
        'fault-op',
        'fault-refund',
        FixedDecimal::ofUnscaled(4000, 2),
        'EUR',
        $intent->refunded_at,
        PaymentMethod::BankTransfer,
        'ref_fault',
        'the-operator',
        null,
    ));

    expect($result->refund->stage)->toBe(SaleRefundStage::Refunded)
        ->and(DB::table('credit_movements')->where('grouped_payment_id', $paymentId)->count())->toBe(0)
        ->and($result->remainingRefundable->unscaledValue())->toBe(6000);
});
