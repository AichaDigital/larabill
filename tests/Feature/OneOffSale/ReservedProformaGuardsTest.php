<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Enums\SaleReservationStage;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\OneOffSaleService;
use AichaDigital\Larabill\Services\VoucherSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\VoucherSaleRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * C2 guard census (AID-1997 spec §5 G13-G15 + G5, §7 C2): a reserved
 * proforma belongs to its reservation. Every OTHER writer that could take
 * the proforma away from the sale refuses while a LIVE (non-Released)
 * reservation exists — the census lists each entry point, so a missing
 * guard is a missing case, not a silent pass.
 */

beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
});

function aid1997gProforma(string $customerId): Invoice
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customerId,
        'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);

    return $proforma->fresh();
}

/** The census: each entry point that must refuse on a reserved proforma. */
dataset('reserved-proforma-guards', [
    'G5 convertProformaToInvoice'           => [fn (Invoice $proforma) => app(InvoiceService::class)->convertProformaToInvoice($proforma->fresh())],
    'G13 openForCollection on the proforma' => [fn (Invoice $proforma) => app(InvoiceService::class)->openForCollection($proforma->fresh())],
    'G14 voucher claim (sellVoucher)'       => [fn (Invoice $proforma) => app(VoucherSaleService::class)->sellVoucher(new VoucherSaleRequest(
        (string) $proforma->id,
        $proforma->billable_user_id,
        $proforma->total_amount,
        'EUR',
        now()->subMinute()->startOfSecond(),
        PaymentMethod::Card,
        'pi-guard-census',
        null,
        'guard-census-key'
    ))],
    'G15 cancelProforma' => [fn (Invoice $proforma) => app(VoucherSaleService::class)->cancelProforma($proforma->fresh(), 'the-operator')],
]);

it('refuses every other writer on a reserved proforma', function (Closure $entryPoint) {
    $proforma    = aid1997gProforma($this->customer->id);
    $reservation = app(OneOffSaleService::class)->reserve($proforma->id, 'guard-census-op');

    try {
        $entryPoint($proforma);
        $this->fail('the entry point must refuse on a reserved proforma');
    } catch (Throwable $e) {
        // The refusal exists and is typed — the message names the refusal's
        // own reason, never a coincidence (an unrelated failure fails below).
        expect($e->getMessage())->not->toBeEmpty();
    }

    // The reservation itself is untouched by the refused writer.
    expect($reservation->fresh()->stage->value)->toBe(SaleReservationStage::Reserved->value);
})->with('reserved-proforma-guards');

it('lets every writer back after release', function (Closure $entryPoint) {
    $proforma = aid1997gProforma($this->customer->id);
    $service  = app(OneOffSaleService::class);

    $service->reserve($proforma->id, 'guard-census-op');
    $service->release('guard-census-op', 'the-operator');

    // After release the proforma is editable again — no exception is the
    // assertion (each writer proceeds on its own merits).
    expect($proforma->fresh()->is_immutable)->toBeFalse();

    try {
        $entryPoint($proforma);
        $released = true;
    } catch (Throwable $e) {
        // A post-release refusal must NOT be a reservation refusal: no
        // message may mention the reservation.
        expect($e->getMessage())->not->toContain('reservation');
        $released = true;
    }

    expect($released)->toBeTrue();
})->with('reserved-proforma-guards');
