<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DefaultPDFConnector;

beforeEach(function () {
    $this->connector = new DefaultPDFConnector;
});

it('can be instantiated', function () {
    expect($this->connector)->toBeInstanceOf(DefaultPDFConnector::class);
});

it('returns correct connector type', function () {
    expect($this->connector->getConnectorType())->toBe('local');
});

it('is available by default', function () {
    expect($this->connector->isAvailable())->toBeTrue();
});

it('has no endpoint', function () {
    expect($this->connector->getEndpoint())->toBeNull();
});

it('has no authentication', function () {
    expect($this->connector->getAuthentication())->toBeArray();
    expect($this->connector->getAuthentication())->toBeEmpty();
});

it('returns required fields', function () {
    $fields = $this->connector->getRequiredFields();

    expect($fields)->toBeArray();
    expect($fields)->toContain('id');
    expect($fields)->toContain('number');
    expect($fields)->toContain('total');
    expect($fields)->toContain('status');
});

it('supports all countries', function () {
    $countries = $this->connector->getSupportedCountries();

    expect($countries)->toBeArray();
    expect($countries)->toContain('*');
});

it('returns metadata', function () {
    $metadata = $this->connector->getMetadata();

    expect($metadata)->toBeArray();
    expect($metadata)->toHaveKey('name');
    expect($metadata)->toHaveKey('version');
    expect($metadata)->toHaveKey('description');
    expect($metadata)->toHaveKey('type');
    expect($metadata['type'])->toBe('local');
});

it('can validate valid invoice', function () {
    $invoice = Invoice::factory()->make([
        'number'     => 'TEST-001',
        'type'       => 'invoice',
        'status'     => 'draft',
        'user_id'    => 1,
        'subtotal'   => 100.0,
        'tax_amount' => 21.0,
        'total'      => 121.0,
    ]);
    // Save to generate UUID
    $invoice->save();

    expect($this->connector->validateInvoice($invoice))->toBeTrue();
});

it('rejects invalid invoice', function () {
    $invoice     = new Invoice;
    // Missing required fields - ID will be auto-generated

    expect($this->connector->validateInvoice($invoice))->toBeFalse();
});

it('can generate QR for valid invoice', function () {
    $invoice = Invoice::factory()->create([
        'number'     => 'TEST-001',
        'type'       => 'invoice',
        'status'     => 'draft',
        'user_id'    => 1,
        'subtotal'   => 100.0,
        'tax_amount' => 21.0,
        'total'      => 121.0,
    ]);

    $result = $this->connector->generateQR($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeTrue();
    expect($result)->toHaveKey('qr_code');
    expect($result)->toHaveKey('qr_url');
    expect($result)->toHaveKey('qr_data');
    expect($result['connector_type'])->toBe('local');
});

it('handles QR generation errors gracefully', function () {
    $invoice     = new Invoice;
    // Missing required fields - ID will be auto-generated

    $result = $this->connector->generateQR($invoice);

    expect($result)->toBeArray();
    expect($result['success'])->toBeFalse();
    expect($result)->toHaveKey('error');
    expect($result['connector_type'])->toBe('local');
});

it('includes invoice data in QR', function () {
    $invoice = Invoice::factory()->create([
        'number'     => 'TEST-001',
        'type'       => 'invoice',
        'status'     => 'draft',
        'user_id'    => 1,
        'subtotal'   => 100.0,
        'tax_amount' => 21.0,
        'total'      => 121.0,
    ]);

    $result = $this->connector->generateQR($invoice);

    expect($result['success'])->toBeTrue();
    expect($result['qr_data'])->toHaveKey('invoice_id');
    expect($result['qr_data'])->toHaveKey('invoice_number');
    expect($result['qr_data'])->toHaveKey('total_amount');
    expect($result['qr_data']['invoice_id'])->toBe($invoice->id);
    expect($result['qr_data']['invoice_number'])->toBe($invoice->number);
});

it('can be configured with custom settings', function () {
    $config = [
        'qr_base_url'             => 'https://example.com',
        'qr_include_invoice_data' => false,
    ];

    $connector       = new DefaultPDFConnector($config);
    $connectorConfig = $connector->getConfiguration();

    expect($connectorConfig['qr_base_url'])->toBe('https://example.com');
    expect($connectorConfig['qr_include_invoice_data'])->toBeFalse();
});

it('generates QR URL with custom base URL', function () {
    $config    = ['qr_base_url' => 'https://example.com'];
    $connector = new DefaultPDFConnector($config);

    $invoice = Invoice::factory()->create([
        'number'     => 'TEST-001',
        'type'       => 'invoice',
        'status'     => 'draft',
        'user_id'    => 1,
        'subtotal'   => 100.0,
        'tax_amount' => 21.0,
        'total'      => 121.0,
    ]);

    $result = $connector->generateQR($invoice);

    expect($result['success'])->toBeTrue();
    expect($result['qr_url'])->toStartWith('https://example.com');
});

it('returns false when validating invoice if connector is not available', function () {
    $connector = new class extends DefaultPDFConnector
    {
        public function isAvailable(): bool
        {
            return false;
        }
    };

    $invoice             = new Invoice;
    // ID auto-generated as UUID
    $invoice->number     = 'TEST-001';
    $invoice->type       = 'invoice';
    $invoice->status     = 'draft';
    $invoice->user_id    = 'test-user';
    $invoice->subtotal   = 10000;
    $invoice->tax_amount = 2100;
    $invoice->total      = 12100;

    expect($connector->validateInvoice($invoice))->toBeFalse();
});
