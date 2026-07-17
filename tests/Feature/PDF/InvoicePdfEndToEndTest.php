<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;
use Smalot\PdfParser\Parser;

/**
 * AID-508 — the test that would have caught all of it. The consumer's test asserted
 * $result['success'] === true and file_exists($result['pdf_path']): green over a
 * document with a blank number, an invented line, €0.00 totals and a fantasy issuer.
 *
 * This one reads the PDF that dompdf actually produces. It asserts PRESENCE of the
 * data, never order or layout of the extracted text — that would be brittle.
 */
it('produces a PDF whose text carries the real invoice', function () {
    $config = CompanyFiscalConfig::factory()->create([
        'business_name' => 'Castris Conformance SL',
        'tax_id'        => 'ESB12345678',
        'address'       => 'Calle Real 1',
        'city'          => 'Granada',
        'zip_code'      => '18001',
        'country_code'  => 'ES',
    ]);

    $invoice = Invoice::factory()->create([
        'fiscal_number'            => 'CASTRIS-2026-000001',
        'serie'                    => InvoiceSerieType::INVOICE->value,
        'status'                   => InvoiceStatus::SENT->value,
        'user_id'                  => TestCase::USER_UUID_1,
        'invoice_date'             => '2026-07-16',
        'company_fiscal_config_id' => $config->id,
        'taxable_amount'           => cents(10000),
        'total_tax_amount'         => cents(2100),
        'total_amount'             => cents(12100),
    ]);
    $invoice->snapshotFiscalConfigs();
    $invoice->save();

    InvoiceItem::factory()->create([
        'invoice_id'       => $invoice->id,
        'description'      => 'Hosting anual Probe',
        'quantity'         => cents(100),
        'unit_price'       => cents(10000),
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
        'taxes_applied'    => [['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100]],
    ]);

    $result = (new PDFService)->generatePDF($invoice->fresh());

    expect($result['success'])->toBeTrue();

    $text = (new Parser)->parseFile($result['pdf_path'])->getText();

    expect($text)->toContain('CASTRIS-2026-000001')     // the number, blank before AID-508
        ->and($text)->toContain('Hosting anual Probe')   // the real line, «Servicio 1» before
        ->and($text)->toContain('121.00')                // the real total, 0.00 before
        ->and($text)->toContain('100.00')                // the real base
        ->and($text)->toContain('21.00')                 // the real tax
        ->and($text)->toContain('Castris Conformance SL')
        ->and($text)->toContain('ESB12345678')
        ->and($text)->not->toContain('Servicio 1')
        ->and($text)->not->toContain('Mi Empresa')
        ->and($text)->not->toContain('12345678A')
        ->and($text)->not->toContain('info@empresa.com');
});
