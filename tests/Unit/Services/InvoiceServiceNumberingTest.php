<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\InvoiceSeriesControl;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-390 PR2 — InvoiceService derives its numbering from the hardened
 * InvoiceNumberingService (single owner) instead of its legacy rand()-based
 * fiscal_number + MAX+1 series_number, which were non-correlative and could
 * collide.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
});

it('derives fiscal_number, prefix, series_number and fiscal_year from the series control', function () {
    $service = app(InvoiceService::class);

    $first  = $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => []]);
    $second = $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => []]);

    expect($first->fiscal_number)->toBe('FAC-'.now()->year.'-000001')
        ->and($first->prefix)->toBe('FAC')
        ->and($first->series_number)->toBe(1)
        ->and($second->fiscal_number)->toBe('FAC-'.now()->year.'-000002')
        ->and($second->series_number)->toBe(2);

    $control = InvoiceSeriesControl::where('prefix', 'FAC')->sole();
    expect($control->last_number)->toBe(2)
        ->and($control->user_id)->toBe(InvoiceSeriesControl::GLOBAL_SCOPE);
});

it('numbers proformas on their own PRO series', function () {
    $service = app(InvoiceService::class);

    $service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => []]);
    $proforma = $service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'type'             => 'proforma',
        'items'            => [],
    ]);

    expect($proforma->fiscal_number)->toBe('PRO-'.now()->year.'-000001')
        ->and($proforma->prefix)->toBe('PRO')
        ->and($proforma->series_number)->toBe(1);

    expect(InvoiceSeriesControl::count())->toBe(2);
});
