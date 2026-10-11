<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * ADR-015 §2.5 — import support on the invoices side (AID-895 slice, spec §7 task 6).
 *
 * `is_historical` marks EVERY document migrated from the legacy system: plain,
 * visible, queryable. Default false on ordinary creation; the importer sets it
 * additively through `InvoiceService::createInvoice()` — the same additive-input
 * pattern as `rectifies_invoice_id` / `settlement_operation_key`.
 *
 * The zero-invoice route (gate round 6 P1): legacy zero invoices of sponsored
 * services import as 0-amount invoices with a traceability line through the
 * EXISTING non-fiscal route — `InvoiceSerieType::PROFORMA` via `InvoiceService`.
 * Fiscal quality IS the serie type; a series prefix cannot make a document
 * non-fiscal. larabill adds NO new serie type and NO new route — this test
 * proves the existing route already carries the import shape end-to-end.
 */

beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
    $this->service  = app(InvoiceService::class);
});

it('defaults is_historical to false on ordinary creation', function () {
    $user    = TestUser::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $user->id, 'billable_user_id' => $user->id]);

    // The DDL default (false) governs: the column reads false on a fresh
    // hydration, and the in-memory model reports false too once cast (the
    // boolean cast materialises a missing attribute as the column default).
    expect($invoice->fresh()->is_historical)->toBeFalse();
});

it('accepts is_historical as an additive input through createInvoice()', function () {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'is_historical'    => true,
        'items'            => [],
    ]);

    expect($invoice->is_historical)->toBeTrue()
        ->and($invoice->fresh()->is_historical)->toBeTrue();
});

it('leaves is_historical false when the additive input is absent (byte-for-byte unchanged path)', function () {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
    ]);

    expect($invoice->is_historical)->toBeFalse();
});

it('imports a legacy zero invoice as a 0-amount PROFORMA with a traceability line carrying is_historical (§2.5 route, end-to-end)', function () {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'type'             => 'proforma',
        'is_historical'    => true,
        'items'            => [
            [
                'description' => 'legacy error, originating from WHMCS',
                'unit_price'  => 0,
            ],
        ],
    ]);

    // The fiscal quality is the serie type: the existing PROFORMA route needs
    // no new path — the document is born non-fiscal and queryable as such.
    expect($invoice->serie)->toBe(InvoiceSerieType::PROFORMA)
        ->and($invoice->total_amount->unscaledValue())->toBe(0)
        ->and($invoice->total_tax_amount->unscaledValue())->toBe(0)
        ->and($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first()->description)->toBe('legacy error, originating from WHMCS')
        ->and($invoice->items->first()->unit_price->unscaledValue())->toBe(0)
        // Every document migrated from the legacy system carries the flag.
        ->and($invoice->is_historical)->toBeTrue()
        ->and($invoice->fresh()->is_historical)->toBeTrue();
});
