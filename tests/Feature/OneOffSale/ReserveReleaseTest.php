<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Enums\SaleReservationStage;
use AichaDigital\Larabill\Exceptions\IdempotencyConflictException;
use AichaDigital\Larabill\Exceptions\SaleNotReservableException;
use AichaDigital\Larabill\Exceptions\SaleReservedException;
use AichaDigital\Larabill\Exceptions\SaleStageException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\SaleReservation;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\OneOffSaleService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * C2 — the reservation of a one-off sale (AID-1997 spec §4.1/§4.2, D1-D4):
 * reserve() freezes the proforma under the reservation's authorization,
 * writes the frozen expectation and the immutable freeze (checked writes);
 * release() reopens an abandoned checkout and spends the key. One LIVE
 * reservation per proforma under the proforma lock; the released history
 * never blocks a new reservation; key reuse after release is refused.
 */

beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);
    $this->customer = TestUser::factory()->create();
});

function aid1997rProforma(string $customerId, int $basePriceCents = 10000): Invoice
{
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $customerId,
        'items'            => [['description' => 'One-off service', 'quantity' => 100, 'base_price' => $basePriceCents]],
    ]);
    $proforma->update(['status' => InvoiceStatus::SENT->value]);

    return $proforma->fresh();
}

it('reserves a proforma, freezing it with the frozen expectation', function () {
    $proforma = aid1997rProforma($this->customer->id);

    $reservation = app(OneOffSaleService::class)->reserve($proforma->id, 'op-key-1');

    expect($reservation->stage->value)->toBe(SaleReservationStage::Reserved->value)
        ->and($reservation->expected_amount->unscaledValue())->toBe(10000) // the fixture's tax-free total (no tax group)
        ->and($reservation->expected_currency)->toBe('EUR')
        ->and($reservation->expected_billable_user_id)->toBe($this->customer->id)
        ->and($proforma->fresh()->is_immutable)->toBeTrue();
});

it('replays the same key on the same proforma', function () {
    $proforma = aid1997rProforma($this->customer->id);
    $service  = app(OneOffSaleService::class);

    $first  = $service->reserve($proforma->id, 'op-key-1');
    $second = $service->reserve($proforma->id, 'op-key-1');

    expect($second->id)->toBe($first->id);
});

it('refuses the same key on another proforma', function () {
    $a       = aid1997rProforma($this->customer->id);
    $b       = aid1997rProforma($this->customer->id);
    $service = app(OneOffSaleService::class);

    $service->reserve($a->id, 'op-key-1');
    $service->reserve($b->id, 'op-key-1');
})->throws(IdempotencyConflictException::class);

it('refuses a second live reservation of the same proforma with another key', function () {
    $proforma = aid1997rProforma($this->customer->id);
    $service  = app(OneOffSaleService::class);

    $service->reserve($proforma->id, 'op-key-1');
    $service->reserve($proforma->id, 'op-key-2');
})->throws(SaleReservedException::class);

it('refuses a proforma outside {DRAFT, SENT}', function () {
    $proforma = aid1997rProforma($this->customer->id);
    $proforma->update(['status' => InvoiceStatus::OVERDUE->value]);

    app(OneOffSaleService::class)->reserve($proforma->fresh()->id, 'op-key-1');
})->throws(SaleNotReservableException::class);

it('refuses a proforma claimed by a voucher sale', function () {
    $proforma = aid1997rProforma($this->customer->id);
    DB::table('invoices')->where('id', $proforma->id)->update(['sale_claim_id' => '0193b58e-0000-7000-8000-000000000001']);

    app(OneOffSaleService::class)->reserve($proforma->fresh()->id, 'op-key-1');
})->throws(SaleNotReservableException::class);

it('releases a Reserved checkout, spending the key and reopening the proforma', function () {
    $proforma = aid1997rProforma($this->customer->id);
    $service  = app(OneOffSaleService::class);

    $reservation = $service->reserve($proforma->id, 'op-key-1');
    $released    = $service->release('op-key-1', 'the-operator');

    expect($released->stage->value)->toBe(SaleReservationStage::Released->value)
        ->and($released->released_by)->toBe('the-operator')
        ->and($released->released_at)->not->toBeNull()
        ->and($proforma->fresh()->is_immutable)->toBeFalse();

    // The key is spent: reuse on the same proforma is a conflict.
    $service->reserve($proforma->fresh()->id, 'op-key-1');
})->throws(SaleNotReservableException::class);

it('lets a released history coexist with a new reservation', function () {
    $proforma = aid1997rProforma($this->customer->id);
    $service  = app(OneOffSaleService::class);

    $service->reserve($proforma->id, 'op-key-1');
    $service->release('op-key-1', 'the-operator');

    $second = $service->reserve($proforma->fresh()->id, 'op-key-2');

    expect($second->stage->value)->toBe(SaleReservationStage::Reserved->value)
        ->and(SaleReservation::where('proforma_id', $proforma->id)->count())->toBe(2);
});

it('refuses release at Paid and later', function () {
    $proforma = aid1997rProforma($this->customer->id);
    $service  = app(OneOffSaleService::class);

    $reservation = $service->reserve($proforma->id, 'op-key-1');
    $reservation->update(['stage' => SaleReservationStage::Paid->value]);

    $service->release('op-key-1', 'the-operator');
})->throws(SaleStageException::class);
