<?php

declare(strict_types=1);
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DefaultPDFConnector;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;

beforeEach(function () {
    $this->pdfService = new PDFService;
});

it('can be instantiated', function () {
    expect($this->pdfService)->toBeInstanceOf(PDFService::class);
});

it('can get available connectors', function () {
    $connectors = $this->pdfService->getAvailableConnectors();

    expect($connectors)->toBeArray();
    expect($connectors)->toHaveKey('local');
    expect($connectors['local']['type'])->toBe('local');
});

it('can get default connector', function () {
    $connector = $this->pdfService->getConnector();

    expect($connector)->toBeInstanceOf(DefaultPDFConnector::class);
    expect($connector->getConnectorType())->toBe('local');
});

it('can get specific connector', function () {
    $connector = $this->pdfService->getConnector('local');

    expect($connector)->toBeInstanceOf(DefaultPDFConnector::class);
    expect($connector->getConnectorType())->toBe('local');
});

it('returns null for non-existent connector', function () {
    $connector = $this->pdfService->getConnector('non-existent');

    expect($connector)->toBeNull();
});

it('can get configuration', function () {
    $config = $this->pdfService->getConfiguration();

    expect($config)->toBeArray();
    expect($config)->toHaveKey('default_connector');
    expect($config)->toHaveKey('fallback_to_local');
    expect($config['default_connector'])->toBe('local');
});

it('can update configuration', function () {
    $newConfig = ['cache_ttl' => 7200];
    $this->pdfService->updateConfiguration($newConfig);

    $config = $this->pdfService->getConfiguration();
    expect($config['cache_ttl'])->toBe(7200);
});

it('can register new connector', function () {
    $mockConnector = new DefaultPDFConnector;
    $this->pdfService->registerConnector('test', $mockConnector);

    $connector = $this->pdfService->getConnector('test');
    expect($connector)->toBeInstanceOf(DefaultPDFConnector::class);
});

it('can generate PDF for invoice', function () {
    // Create a test invoice with factory
    $invoice = Invoice::factory()->create([
        'fiscal_number'     => 'TEST-001',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'status'            => InvoiceStatus::DRAFT->value,
        'user_id'           => TestCase::USER_UUID_1,
        'taxable_amount'    => cents((int) 100.0),
        'total_tax_amount'  => cents((int) 21.0),
        'total_amount'      => cents((int) 121.0),
    ]);

    $result = $this->pdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue();
    expect($result)->toHaveKey('pdf_path');
    expect($result)->toHaveKey('qr_data');
    expect($result['connector_used'])->toBe('local');
});

it('uses the persisted fiscal verification QR when generating fiscal PDFs', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'                 => 'TEST-FISCAL-QR',
        'serie'                         => InvoiceSerieType::INVOICE->value,
        'status'                        => InvoiceStatus::DRAFT->value,
        'user_id'                       => TestCase::USER_UUID_1,
        'taxable_amount'                => cents(10000),
        'total_tax_amount'              => cents(2100),
        'total_amount'                  => cents(12100),
        'fiscal_verification_qr'        => '<svg data-testid="verifactu-qr"></svg>',
        'fiscal_verification_metadata'  => [
            'qr_url' => 'https://prewww2.aeat.es/qr?id=REG-000001',
        ],
    ]);

    $result = $this->pdfService->generatePDF($invoice);

    expect($result['success'])->toBeTrue()
        ->and($result['qr_data']['source'])->toBe('fiscal_verification')
        ->and($result['qr_data']['qr_svg'])->toBe('<svg data-testid="verifactu-qr"></svg>')
        ->and($result['qr_data']['qr_url'])->toBe('https://prewww2.aeat.es/qr?id=REG-000001');
});

it('can handle PDF generation errors gracefully', function () {
    // Create an invoice with minimal fields
    $invoice = Invoice::factory()->create([
        'fiscal_number'        => 'TEST-ERROR',
        'serie'                => InvoiceSerieType::INVOICE->value,
        'status'               => InvoiceStatus::DRAFT->value,
        'user_id'              => TestCase::USER_UUID_1,
        'taxable_amount'       => cents(10000),
        'total_tax_amount'     => cents(2100),
        'total_amount'         => cents(12100),
    ]);

    $result = $this->pdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue(); // Service handles missing fields gracefully
    expect($result)->toHaveKey('pdf_path');
    expect($result)->toHaveKey('connector_used');
});

it('can cache PDF results', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'          => 'TEST-002',
        'serie'                  => InvoiceSerieType::INVOICE->value,
        'status'                 => InvoiceStatus::DRAFT->value,
        'user_id'                => TestCase::USER_UUID_1,
        'taxable_amount'         => cents((int) 100.0),
        'total_tax_amount'       => cents((int) 21.0),
        'total_amount'           => cents((int) 121.0),
    ]);

    $result = $this->pdfService->generatePDF($invoice);

    expect($result['success'])->toBeTrue();

    // Cache is disabled by default in tests (no cache repository provided)
    $cached = $this->pdfService->getCachedPDFResult($invoice);
    expect($cached)->toBeNull();
});

it('can clear PDF cache', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'        => 'TEST-003',
        'serie'                => InvoiceSerieType::INVOICE->value,
        'status'               => InvoiceStatus::DRAFT->value,
        'user_id'              => TestCase::USER_UUID_1,
        'taxable_amount'       => cents(10000),
        'total_tax_amount'     => cents(2100),
        'total_amount'         => cents(12100),
    ]);

    $this->pdfService->generatePDF($invoice);
    $this->pdfService->clearPDFCache($invoice);

    $cached = $this->pdfService->getCachedPDFResult($invoice);
    expect($cached)->toBeNull();
});
