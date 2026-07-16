<?php

declare(strict_types=1);
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DefaultPDFConnector;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Services\PDF\PDFService;
use AichaDigital\Larabill\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->pdfService = new PDFService;
});

it('can be instantiated', function () {
    expect($this->pdfService)->toBeInstanceOf(PDFService::class);
});

it('accepts an injected DomPDF engine instead of hard-wiring one (AID-391)', function () {
    $engine = new class([]) extends DomPDFService {};

    $service = new PDFService([], null, $engine);

    expect($service->getDomPDFService())->toBe($engine);
});

it('persists PDFs through the File facade (AID-391)', function () {
    $engine = new DomPDFService([]);

    $invoice     = new Invoice;
    $invoice->id = (string) Str::uuid7();

    $save = new ReflectionMethod($engine, 'savePDF');
    $save->setAccessible(true);

    // Testing environment → the temp-dir branch; the write itself goes
    // through File::put either way.
    $path = $save->invoke($engine, $invoice, '%PDF-fake-content');

    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))->toBe('%PDF-fake-content');

    File::delete($path);
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
        // AID-508: the QR alone is not enough — the frontier requires a coherent
        // registration too (fiscal_verification_id + fiscal_verified_at).
        'fiscal_verification_id'        => 'REG-000001',
        'fiscal_verified_at'            => now(),
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
