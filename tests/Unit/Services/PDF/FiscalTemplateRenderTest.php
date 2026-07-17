<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;

// AID-508/AID-328: getCompanyData() now reads the invoice's frozen issuer
// snapshot instead of a hardcoded fantasy. Without an active CompanyFiscalConfig
// for Invoice::boot()'s creating() hook to auto-snapshot against, the templates'
// header block crashes on a missing key before these render-smoke tests reach
// the reverse-charge/exempt routing they actually exercise.
beforeEach(function () {
    CompanyFiscalConfig::factory()->create();
});

/**
 * AID-245: fixing isReverseCharge()/isExemptInvoice() to read the real fiscal
 * sources finally routes ROI / exempt invoices to the reverse-charge / exempt
 * templates — which had never rendered before and still carried the
 * `ucfirst($invoice->status)` enum bug fixed in fiscal.blade.php (2026-06-18).
 * These render-smoke tests keep both templates renderable.
 */
it('renders the reverse-charge template for an ROI invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'RC-RENDER',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'is_roi_taxed'     => true,
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(0),
        'total_amount'     => cents(10000),
    ]);

    expect((new PDFService)->generatePDF($invoice)['success'])->toBeTrue();
});

it('renders the exempt template for a VAT-exempt recipient', function () {
    $profile = UserTaxProfile::factory()->create(['is_exempt_vat' => true]);

    $invoice = Invoice::factory()->create([
        'fiscal_number'       => 'EX-RENDER',
        'serie'               => InvoiceSerieType::INVOICE->value,
        'status'              => InvoiceStatus::DRAFT->value,
        'user_id'             => TestCase::USER_UUID_1,
        'is_roi_taxed'        => false,
        'user_tax_profile_id' => $profile->id,
        'taxable_amount'      => cents(10000),
        'total_tax_amount'    => cents(0),
        'total_amount'        => cents(10000),
    ]);

    expect((new PDFService)->generatePDF($invoice)['success'])->toBeTrue();
});
