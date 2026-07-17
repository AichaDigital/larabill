<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Tests\TestCase;

// AID-508/AID-328: getCompanyData() now reads the invoice's frozen issuer
// snapshot instead of a hardcoded fantasy. These invoices are INVOICE-serie,
// so Invoice::boot()'s creating() hook auto-snapshots against whatever active
// CompanyFiscalConfig exists — without one, the templates' header block
// (unrelated to the operation-date row under test) crashes on a missing key.
beforeEach(function () {
    CompanyFiscalConfig::factory()->create();
});

/**
 * AID-442 — the operation date is a CONDITIONAL compliance field: RD 1619/2012
 * art. 6.1.f requires the invoice to show the date the documented operations
 * were carried out (or the advance payment received) ONLY when it differs from
 * the expedition date. The package stores it as `service_date`; the expedition
 * date is `invoice_date` (AID-439).
 *
 * Fixed criteria (Abkrim, 2026-07-12, dictamen-confirmed):
 * - Label: «Fecha de operación» (the RD 1619/2012 / AEAT term).
 * - The row prints ONLY when `service_date` differs from `invoice_date` and is
 *   not null — with equal or missing dates the PDF stays exactly as before.
 * - The templates stay dumb: no proforma special-casing in any blade (the
 *   proforma exclusion belongs to the data layer, AID-444). The visibility
 *   rule lives in DomPDFService::prepareTemplateData(), not in the blades.
 */
function makeOperationDateInvoice(?string $serviceDate): Invoice
{
    return Invoice::factory()->create([
        'fiscal_number'    => 'OPERATION-DATE',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'invoice_date'     => '2026-02-14',
        'service_date'     => $serviceDate,
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(0),
        'total_amount'     => cents(10000),
    ]);
}

function renderOperationDateTemplate(string $template, Invoice $invoice): string
{
    $engine  = new DomPDFService([]);
    $prepare = new ReflectionMethod($engine, 'prepareTemplateData');
    $render  = new ReflectionMethod($engine, 'renderTemplate');

    return $render->invoke($engine, $template, $prepare->invoke($engine, $invoice, null, false));
}

it('prints «Fecha de operación» with service_date when it differs from invoice_date', function (string $template) {
    $invoice = makeOperationDateInvoice('2026-01-20');

    $html = renderOperationDateTemplate($template, $invoice);

    expect($html)->toContain('Fecha de operación')
        ->and($html)->toContain('20/01/2026')        // service_date, formatted
        ->and($html)->toContain('Fecha de expedición')
        ->and($html)->toContain('14/02/2026');       // invoice_date still prints
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'proforma'       => 'larabill::pdf.invoice.proforma',
    'exempt'         => 'larabill::pdf.invoice.exempt',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
]);

it('omits the operation date row when service_date equals invoice_date or is null', function (string $template, ?string $serviceDate) {
    $invoice = makeOperationDateInvoice($serviceDate);

    $html = renderOperationDateTemplate($template, $invoice);

    expect($html)->not->toContain('Fecha de operación')
        ->and($html)->toContain('Fecha de expedición'); // the PDF stays as before
})->with([
    'fiscal'         => 'larabill::pdf.invoice.fiscal',
    'fiscal-minimal' => 'larabill::pdf.invoice.fiscal-minimal',
    'fiscal-modern'  => 'larabill::pdf.invoice.fiscal-modern',
    'proforma'       => 'larabill::pdf.invoice.proforma',
    'exempt'         => 'larabill::pdf.invoice.exempt',
    'reverse-charge' => 'larabill::pdf.invoice.reverse-charge',
])->with([
    'same day' => '2026-02-14',
    'null'     => null,
]);
