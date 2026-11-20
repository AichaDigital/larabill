<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\PaymentMethod;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\OneOffSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\ValueObjects\SaleSettlementRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * AID-2063 gate round 4: S4 dated the allocation (and so the invoice's
 * paid_at) from the request's UTC instant, while S1 stores the payment's
 * paid_at in the application timezone. Outside UTC the settlement was dated
 * off the payment by the zone offset. The allocation takes the locked
 * payment's paid_at, as the voucher sale does.
 */

beforeEach(function () {
    $this->previousTimezone = date_default_timezone_get();
    config(['app.timezone' => 'Europe/Madrid']);
    date_default_timezone_set('Europe/Madrid');
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
});

afterEach(function () {
    date_default_timezone_set($this->previousTimezone);
});

it('dates the allocation and the invoice paid_at at the payment instant outside UTC', function () {
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $this->customer->id,
        'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => 10000]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);
    $service = app(OneOffSaleService::class);
    $service->reserve((string) $proforma->id, 'tz-op');

    $result = $service->settle(new SaleSettlementRequest(
        'tz-op',
        $this->customer->id,
        $proforma->fresh()->total_amount,
        'EUR',
        new DateTimeImmutable('2026-11-19T00:30:00+01:00'),
        PaymentMethod::Card,
        'pi_tz',
        'the-operator',
    ));

    $payment    = $result->payment->fresh();
    $allocation = $result->allocation->fresh();
    $invoice    = $result->invoice->fresh();

    expect($payment->paid_at->copy()->utc()->format('c'))->toBe('2026-11-18T23:30:00+00:00')
        ->and($allocation->applied_at->copy()->utc()->format('c'))->toBe('2026-11-18T23:30:00+00:00')
        ->and($invoice->paid_at->copy()->utc()->format('c'))->toBe('2026-11-18T23:30:00+00:00');
});
