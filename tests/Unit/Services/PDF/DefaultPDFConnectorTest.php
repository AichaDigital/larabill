<?php

declare(strict_types=1);
use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\DefaultPDFConnector;
use AichaDigital\Larabill\Tests\TestCase;

beforeEach(function () {
    $this->connector = new DefaultPDFConnector;
});

it('can be instantiated', function () {
    expect($this->connector)->toBeInstanceOf(DefaultPDFConnector::class);
});

it('returns correct connector type', function () {
    expect($this->connector->getConnectorType())->toBe('local');
});

it('returns required fields', function () {
    $fields = $this->connector->getRequiredFields();

    expect($fields)->toBeArray();
    expect($fields)->toContain('id');
    expect($fields)->toContain('fiscal_number');
    expect($fields)->toContain('total_amount');
    expect($fields)->toContain('status');
});

it('refuses to fabricate a fiscal QR', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'CONNECTOR-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    // It used to return 'QR:'.substr($hash, 0, 16).':'.base64_encode(substr($json, 0, 100)):
    // plain text, not scannable, with the JSON truncated mid-key.
    expect(fn () => $this->connector->generateQR($invoice))
        ->toThrow(LogicException::class, 'does not generate fiscal QR codes');
});

it('no longer carries the fake QR generator', function () {
    expect(method_exists(DefaultPDFConnector::class, 'generateQRCode'))->toBeFalse();
});

it('can validate valid invoice', function () {
    $invoice = Invoice::factory()->make([
        'fiscal_number'          => 'TEST-001',
        'serie'                  => InvoiceSerieType::INVOICE->value,
        'status'                 => InvoiceStatus::DRAFT->value,
        'user_id'                => TestCase::USER_UUID_1,
        'taxable_amount'         => cents((int) 100.0),
        'total_tax_amount'       => cents((int) 21.0),
        'total_amount'           => cents((int) 121.0),
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

it('returns false when validating invoice if connector is not available', function () {
    $connector = new class extends DefaultPDFConnector
    {
        public function isAvailable(): bool
        {
            return false;
        }
    };

    $invoice = Invoice::factory()->make([
        'fiscal_number'        => 'TEST-001',
        'serie'                => InvoiceSerieType::INVOICE->value,
        'status'               => InvoiceStatus::DRAFT->value,
        'user_id'              => TestCase::USER_UUID_1,
        'taxable_amount'       => cents(10000),
        'total_tax_amount'     => cents(2100),
        'total_amount'         => cents(12100),
    ]);

    expect($connector->validateInvoice($invoice))->toBeFalse();
});
