<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Tests\TestCase;

// AID-508/AID-328: getCompanyData() now reads the invoice's frozen issuer
// snapshot instead of a hardcoded fantasy. Without an active CompanyFiscalConfig
// for Invoice::boot()'s creating() hook to auto-snapshot against, the header
// block the templates all share (unrelated to the invoice-date row under test)
// crashes on a missing key.
beforeEach(function () {
    CompanyFiscalConfig::factory()->create();
});

/**
 * AID-439 — the printed invoice date is a COMPLIANCE field: RD 1619/2012
 * art. 6.1.b (7.1.b for simplified invoices) requires the invoice to show its
 * fecha de expedición, which this package stores as `invoice_date` («Legal
 * invoice date (appears on PDF)», create_invoices_table). The templates
 * printed `created_at` — a technical persistence timestamp that diverges on
 * imports, restores or ahead-of-time creation. `issued_at` does not substitute
 * either: the package reserves it for technical chronological validation.
 *
 * Regression criterion (Abkrim, 2026-07-12): render every template with
 * invoice_date, created_at and issued_at deliberately DIFFERENT and assert the
 * output shows exclusively invoice_date's formatted date.
 */
it('prints invoice_date — never created_at nor issued_at — as the expedition date', function (string $template) {
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'DATE-COMPLIANCE',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'invoice_date'     => '2026-02-14',
        'issued_at'        => '2026-04-25 10:00:00',
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(0),
        'total_amount'     => cents(10000),
    ]);

    // Force a divergent created_at (imports/restores shape) bypassing the
    // updating timestamps.
    Invoice::withoutTimestamps(
        fn () => $invoice->forceFill(['created_at' => '2026-03-03 09:00:00'])->saveQuietly()
    );
    $invoice->refresh();

    $engine  = new DomPDFService([]);
    $prepare = new ReflectionMethod($engine, 'prepareTemplateData');
    $render  = new ReflectionMethod($engine, 'renderTemplate');

    $html = $render->invoke($engine, $template, $prepare->invoke($engine, $invoice, null, false));

    expect($html)->toContain('Fecha de expedición')
        ->and($html)->toContain('14/02/2026')   // invoice_date
        ->and($html)->not->toContain('03/03/2026')   // created_at must never print
        ->and($html)->not->toContain('25/04/2026');  // issued_at does not substitute
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'proforma'       => 'larabill::pdf.invoice.proforma',
    'exempt'         => 'larabill::pdf.invoice.exempt',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
]);
