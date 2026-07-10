<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\BillingService;
use AichaDigital\Larabill\Tests\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-390 PR2 — BillingService (deprecated, removal target: AID-307 major)
 * must derive its numbering from the hardened InvoiceNumberingService instead
 * of its legacy non-atomic cache counter, which reset on cache:clear and
 * raced under concurrency.
 */
it('derives numbering from the shared series control instead of the cache counter', function () {
    $user    = User::factory()->create();
    $service = app(BillingService::class);

    $first  = $service->createInvoice(['user_id' => $user->id, 'items' => []]);
    $second = $service->createInvoice(['user_id' => $user->id, 'items' => []]);

    expect($first->fiscal_number)->toBe('FAC-'.now()->year.'-000001')
        ->and($first->series_number)->toBe(1)
        ->and($second->fiscal_number)->toBe('FAC-'.now()->year.'-000002')
        ->and($second->series_number)->toBe(2);

    // The legacy cache counter is gone for good.
    expect(cache()->has('invoice_counter_invoice_global'))->toBeFalse()
        ->and(InvoiceSeriesControl::where('prefix', 'FAC')->sole()->last_number)->toBe(2);
});
