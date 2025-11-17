<?php

declare(strict_types=1);

use AichaDigital\Larabill\Contracts\FiscalVerificationContract;
use AichaDigital\Larabill\Models\{Customer, IssuerConfig, Invoice};
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Testing\FakeFiscalVerification;

beforeEach(function () {
    // Bind fake fiscal verification for testing
    app()->bind(FiscalVerificationContract::class, FakeFiscalVerification::class);
    
    $this->invoiceService = app(InvoiceService::class);
});

it('can create an invoice with encrypted snapshots', function () {
    $issuer = IssuerConfig::factory()->create();
    $customer = Customer::factory()->create();

    $invoiceData = [
        'customer_id' => $customer->id,
        'issue_date' => now(),
        'series' => 'A',
        'number' => 1,
        'type' => 'final',
        'status' => 1,
    ];

    $invoice = $this->invoiceService->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and($invoice->issuer_snapshot)->not->toBeNull()
        ->and($invoice->customer_snapshot)->not->toBeNull()
        ->and($invoice->fiscal_snapshot)->not->toBeNull()
        ->and($invoice->is_immutable)->toBeFalse();
});

it('can create a proforma invoice', function () {
    $customer = Customer::factory()->create();

    $invoiceData = [
        'customer_id' => $customer->id,
        'issue_date' => now(),
        'series' => 'P',
        'number' => 1,
    ];

    $proforma = $this->invoiceService->createProforma($invoiceData);

    expect($proforma->type)->toBe('proforma')
        ->and($proforma->status)->toBe(1) // DRAFT
        ->and($proforma->is_immutable)->toBeFalse();
});

it('can convert proforma to final invoice', function () {
    $customer = Customer::factory()->create();

    $proforma = $this->invoiceService->createProforma([
        'customer_id' => $customer->id,
        'issue_date' => now(),
        'series' => 'P',
        'number' => 1,
    ]);

    $finalInvoice = $this->invoiceService->convertProformaToInvoice($proforma);

    expect($finalInvoice)->toBeInstanceOf(Invoice::class)
        ->and($finalInvoice->type)->toBe('final')
        ->and($finalInvoice->id)->not->toBe($proforma->id)
        ->and($proforma->fresh()->is_immutable)->toBeTrue()
        ->and($proforma->fresh()->status)->toBe(6) // CONVERTED
        ->and($proforma->fresh()->converted_invoice_id)->toBe($finalInvoice->id);
});

it('cannot convert already converted proforma', function () {
    $customer = Customer::factory()->create();

    $proforma = $this->invoiceService->createProforma([
        'customer_id' => $customer->id,
        'issue_date' => now(),
        'series' => 'P',
        'number' => 1,
    ]);

    // Convert once
    $this->invoiceService->convertProformaToInvoice($proforma);

    // Try to convert again - should throw exception
    $this->invoiceService->convertProformaToInvoice($proforma->fresh());
})->throws(\RuntimeException::class, 'already been converted');

it('can create invoice items with tax calculation', function () {
    $issuer = IssuerConfig::factory()->create();
    $customer = Customer::factory()->create();

    $invoice = $this->invoiceService->createInvoice([
        'customer_id' => $customer->id,
        'issue_date' => now(),
        'series' => 'A',
        'number' => 1,
        'type' => 'final',
        'status' => 1,
    ]);

    $itemData = [
        'invoice_id' => $invoice->id,
        'description' => 'Test Service',
        'quantity' => 1,
        'unit_price' => 100.00,
    ];

    $item = $this->invoiceService->createInvoiceItem($itemData);

    expect($item->invoice_id)->toBe($invoice->id)
        ->and($item->description)->toBe('Test Service')
        ->and($item->quantity)->toBe(1)
        ->and($item->unit_price)->toBeGreaterThan(0);
});

it('locks proforma after conversion', function () {
    $customer = Customer::factory()->create();

    $proforma = $this->invoiceService->createProforma([
        'customer_id' => $customer->id,
        'issue_date' => now(),
        'series' => 'P',
        'number' => 1,
    ]);

    $this->invoiceService->convertProformaToInvoice($proforma);

    $proforma->refresh();

    expect($proforma->is_immutable)->toBeTrue()
        ->and($proforma->status)->toBe(6); // CONVERTED
});

