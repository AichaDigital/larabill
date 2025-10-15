<?php

declare(strict_types=1);
use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DomPDFService;

beforeEach(function () {
    // Reuse the same service instance to avoid reinitializing DomPDF
    if (! isset($this->dompdfService)) {
        $this->dompdfService = new DomPDFService;
    }
});

it('can be instantiated', function () {
    expect($this->dompdfService)->toBeInstanceOf(DomPDFService::class);
});

it('can get available templates', function () {
    $templates = $this->dompdfService->getAvailableTemplates();

    expect($templates)->toBeArray();
    expect($templates)->toHaveKey('fiscal');
    expect($templates)->toHaveKey('proforma');
    expect($templates)->toHaveKey('reverse_charge');
    expect($templates)->toHaveKey('exempt');
});

it('can get configuration', function () {
    $config = $this->dompdfService->getConfiguration();

    expect($config)->toBeArray();
    expect($config)->toHaveKey('paper_size');
    expect($config)->toHaveKey('default_font');
    expect($config['paper_size'])->toBe('A4');
});

it('can update configuration', function () {
    $newConfig = ['paper_size' => 'A3'];
    $this->dompdfService->updateConfiguration($newConfig);

    $config = $this->dompdfService->getConfiguration();
    expect($config['paper_size'])->toBe('A3');
});

it('can generate PDF for fiscal invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'FAC-001',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::PAID->value,
        'user_id'          => 'test-user',
        'taxable_amount'   => 10000,
        'tax_amount'       => 2100,
        'total_amount'     => 12100,
    ]);

    $qrData = [
        'success' => true,
        'qr_code' => 'QR123456',
        'qr_url'  => 'http://example.com/qr',
    ];

    $result = $this->dompdfService->generatePDF($invoice, $qrData);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue();
    expect($result)->toHaveKey('pdf_path');
    expect($result)->toHaveKey('template_used');
    expect($result['template_used'])->toBe('larabill::pdf.invoice.fiscal');
    expect($result['qr_included'])->toBeTrue();
});

it('can generate PDF for proforma invoice without QR', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'PRO-001',
        'serie'            => InvoiceSerieType::PROFORMA->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => 'test-user',
        'taxable_amount'   => 10000,
        'tax_amount'       => 2100,
        'total_amount'     => 12100,
    ]);

    $result = $this->dompdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue();
    expect($result)->toHaveKey('pdf_path');
    expect($result)->toHaveKey('template_used');
    expect($result['template_used'])->toBe('larabill::pdf.invoice.proforma');
    expect($result['qr_included'])->toBeFalse();
});

it('can detect reverse charge invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'     => 'FAC-003',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'status'            => InvoiceStatus::PAID->value,
        'user_id'           => 'test-user',
        'taxable_amount'    => 10000,
        'tax_amount'        => 0,
        'total_amount'      => 10000,
        'fiscal_data'       => ['reverse_charge' => true],
    ]);

    $result = $this->dompdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue();
    expect($result['template_used'])->toBe('larabill::pdf.invoice.reverse-charge');
});

it('can detect exempt invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'     => 'FAC-004',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'status'            => InvoiceStatus::PAID->value,
        'user_id'           => 'test-user',
        'taxable_amount'    => 10000,
        'tax_amount'        => 0,
        'total_amount'      => 10000,
        'fiscal_data'       => ['exempt' => true],
    ]);

    $result = $this->dompdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue();
    expect($result['template_used'])->toBe('larabill::pdf.invoice.exempt');
});

it('handles PDF generation errors gracefully', function () {
    // Create invoice with auto-generated UUID
    $invoice = Invoice::factory()->create([
        // Missing required fields intentionally for error testing
    ]);

    $result = $this->dompdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue(); // The service handles missing fields gracefully
    expect($result)->toHaveKey('pdf_path');
    expect($result)->toHaveKey('template_used');
});
