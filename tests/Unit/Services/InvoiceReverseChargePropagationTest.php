<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-929 (D1/D2/D6) — the header fiscal qualification survives issuance.
 *
 * `is_roi_taxed` decides the reverse-charge treatment of the operation: it
 * gates the fail-loud check for an incomplete intra-EU recipient
 * (VerifactuAdapter), travels to the AEAT inside the submission metadata,
 * keeps the sale out of the distance-sales threshold (EuSalesThresholdService)
 * and selects the reverse-charge PDF template.
 *
 * Before this change no canonical path could issue with the flag on: the
 * conversion never cloned it, and `createInvoice()` built the row from an
 * explicit key list that did not contain it — so an `is_roi_taxed` key in
 * `$invoiceData` was silently ignored. The consumer had nowhere to declare it.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();
    $this->service  = app(InvoiceService::class);
});

it('carries is_roi_taxed from the proforma into the converted invoice', function () {
    // Declared through the canonical API, never poked into the model: a test
    // that sets the column by hand would pass against the broken code.
    $proforma = $this->service->createProforma([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => true,
        'items'            => [],
    ]);

    expect($proforma->is_roi_taxed)->toBeTrue();

    $invoice = $this->service->convertProformaToInvoice($proforma);

    expect($invoice->is_roi_taxed)->toBeTrue();
});

it('carries a false is_roi_taxed into the converted invoice', function () {
    $proforma = $this->service->createProforma([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => false,
        'items'            => [],
    ]);

    $invoice = $this->service->convertProformaToInvoice($proforma);

    expect($invoice->is_roi_taxed)->toBeFalse();
});

it('accepts is_roi_taxed on createProforma', function () {
    $proforma = $this->service->createProforma([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => true,
        'items'            => [],
    ]);

    expect($proforma->fresh()->is_roi_taxed)->toBeTrue();
});

it('accepts is_roi_taxed on createInvoice for direct issuance', function () {
    $invoice = $this->service->createInvoice([
        'billable_user_id' => $this->customer->id,
        'is_roi_taxed'     => true,
        'items'            => [],
    ]);

    expect($invoice->fresh()->is_roi_taxed)->toBeTrue();
});

it('defaults is_roi_taxed to false when the key is absent', function () {
    $invoice  = $this->service->createInvoice(['billable_user_id' => $this->customer->id, 'items' => []]);
    $proforma = $this->service->createProforma(['billable_user_id' => $this->customer->id, 'items' => []]);

    expect($invoice->is_roi_taxed)->toBeFalse()
        ->and($proforma->is_roi_taxed)->toBeFalse();
});
