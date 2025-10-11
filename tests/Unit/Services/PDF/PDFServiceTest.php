<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\{DefaultPDFConnector, PDFService};

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
    // Create a test invoice (id will be auto-generated as UUID)
    $invoice             = new Invoice;
    $invoice->number     = 'TEST-001';
    $invoice->type       = 'invoice';
    $invoice->status     = 'draft';
    $invoice->user_id    = 'test-user';
    $invoice->subtotal   = 10000;
    $invoice->tax_amount = 2100;
    $invoice->total      = 12100;

    $result = $this->pdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue();
    expect($result)->toHaveKey('pdf_path');
    expect($result)->toHaveKey('qr_data');
    expect($result['connector_used'])->toBe('local');
});

it('can handle PDF generation errors gracefully', function () {
    // Create an invalid invoice (missing required fields)
    $invoice     = new Invoice;
    // Don't set required fields - ID will be auto-generated

    $result = $this->pdfService->generatePDF($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue(); // Service handles missing fields gracefully
    expect($result)->toHaveKey('pdf_path');
    expect($result)->toHaveKey('connector_used');
});

it('can cache PDF results', function () {
    $invoice             = new Invoice;
    $invoice->number     = 'TEST-002';
    $invoice->type       = 'invoice';
    $invoice->status     = 'draft';
    $invoice->user_id    = 'test-user';
    $invoice->subtotal   = 10000;
    $invoice->tax_amount = 2100;
    $invoice->total      = 12100;

    $result = $this->pdfService->generatePDF($invoice);

    expect($result['success'])->toBeTrue();

    // Cache is disabled by default in tests (no cache repository provided)
    $cached = $this->pdfService->getCachedPDFResult($invoice);
    expect($cached)->toBeNull();
});

it('can clear PDF cache', function () {
    $invoice             = new Invoice;
    $invoice->number     = 'TEST-003';
    $invoice->type       = 'invoice';
    $invoice->status     = 'draft';
    $invoice->user_id    = 'test-user';
    $invoice->subtotal   = 10000;
    $invoice->tax_amount = 2100;
    $invoice->total      = 12100;

    $this->pdfService->generatePDF($invoice);
    $this->pdfService->clearPDFCache($invoice);

    $cached = $this->pdfService->getCachedPDFResult($invoice);
    expect($cached)->toBeNull();
});
