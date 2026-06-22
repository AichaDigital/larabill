<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;

/**
 * Real fiscal QR shape emitted by lara-verifactu: an <svg> root preceded by an
 * XML declaration. The previous detection only matched a bare "<svg" prefix, so
 * this string used to be misclassified as a PNG and dumped as escaped text.
 */
const VERIFACTU_QR_SVG = '<?xml version="1.0" encoding="UTF-8"?>'
    .'<svg xmlns="http://www.w3.org/2000/svg" width="35mm" height="35mm" data-testid="verifactu-qr">'
    .'<rect width="10" height="10"/></svg>';

/**
 * Build a registered fiscal invoice the way production does: real model with its
 * enum casts and the persisted Veri*Factu QR/metadata.
 */
function realRouteFiscalInvoice(): Invoice
{
    return Invoice::factory()->create([
        'fiscal_number'                => 'FAC-'.now()->year.'-090909',
        'serie'                        => InvoiceSerieType::INVOICE->value,
        'status'                       => InvoiceStatus::SENT->value,
        'user_id'                      => TestCase::USER_UUID_1,
        'taxable_amount'               => cents(1000),
        'total_tax_amount'             => cents(210),
        'total_amount'                 => cents(1210),
        'fiscal_verification_qr'       => VERIFACTU_QR_SVG,
        'fiscal_verification_metadata' => ['qr_url' => 'https://prewww2.aeat.es/qr?id=REG-20260618-000001'],
    ]);
}

/**
 * Render the fiscal PDF blade through the *production* data-prep path — no injected
 * structure and no string status. Real model (enum cast) -> fiscalVerificationQrResult
 * -> prepareTemplateData -> renderTemplate, exactly as DomPDFService::generatePDF does.
 */
function renderRealFiscalHtml(Invoice $invoice): string
{
    $pdfService = new PDFService;

    $qrMethod = new ReflectionMethod(PDFService::class, 'fiscalVerificationQrResult');
    $qrMethod->setAccessible(true);
    $qrData = $qrMethod->invoke($pdfService, $invoice);

    $dom     = $pdfService->getDomPDFService();
    $prepare = new ReflectionMethod(DomPDFService::class, 'prepareTemplateData');
    $prepare->setAccessible(true);
    $data = $prepare->invoke($dom, $invoice, $qrData, true);

    $render = new ReflectionMethod(DomPDFService::class, 'renderTemplate');
    $render->setAccessible(true);

    return (string) $render->invoke($dom, 'larabill::pdf.invoice.fiscal', $data);
}

it('classifies a verifactu QR carrying an <?xml preamble as SVG, not PNG', function () {
    $invoice = realRouteFiscalInvoice();

    $result = (new PDFService)->generatePDF($invoice);

    expect($result['success'])->toBeTrue()
        ->and($result['qr_data']['source'])->toBe('fiscal_verification')
        ->and($result['qr_data']['qr_svg'] ?? null)->toBe(VERIFACTU_QR_SVG)
        ->and($result['qr_data'])->not->toHaveKey('qr_png');
});

it('renders the real fiscal blade for a registered invoice without the mock stub', function () {
    $invoice = realRouteFiscalInvoice();

    $html = renderRealFiscalHtml($invoice);

    expect($html)
        ->not->toContain('TEST-001')                  // never the silent generateMockHTML() stub
        ->toContain('<svg')                           // the persisted Veri*Factu QR is embedded inline (not escaped)
        ->not->toContain('&lt;svg')                   // ...and definitely not dumped as escaped text
        ->toContain(InvoiceStatus::SENT->label())     // status rendered via label(), not ucfirst(enum)
        ->toContain('sede electrónica de la AEAT')    // Veri*Factu legend present
        ->toContain('prewww2.aeat.es');               // AEAT verification URL from fiscal metadata
});

it('surfaces a template render failure instead of returning a plausible mock stub', function () {
    $dom    = (new PDFService)->getDomPDFService();
    $render = new ReflectionMethod(DomPDFService::class, 'renderTemplate');
    $render->setAccessible(true);

    // A genuine render failure (here: a missing template) must propagate, not be
    // swallowed into a plausible mock. Catch \Throwable so both Error and Exception count.
    $surfaced = false;
    try {
        $render->invoke($dom, 'larabill::pdf.invoice.does-not-exist', ['invoice' => null]);
    } catch (Throwable $e) {
        $surfaced = true;
    }

    expect($surfaced)->toBeTrue();
});
