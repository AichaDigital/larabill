<?php

declare(strict_types=1);

use AichaDigital\Larabill\Contracts\Services\FiscalVerificationContract;
use AichaDigital\Larabill\Models\{Customer, Invoice, IssuerConfig};
use AichaDigital\Larabill\Services\FiscalVerification\FakeFiscalVerification;
use AichaDigital\Larabill\Services\InvoiceService;

beforeEach(function () {
    // Clean singleton IssuerConfig
    IssuerConfig::query()->delete();

    // Create IssuerConfig (required for all invoice operations)
    $this->issuer = IssuerConfig::factory()->create();

    // Bind fake fiscal verification for testing
    app()->bind(FiscalVerificationContract::class, FakeFiscalVerification::class);

    $this->invoiceService = app(InvoiceService::class);
});

it('can create an invoice with encrypted snapshots', function () {
    $customer = Customer::factory()->create();

    $invoiceData = [
        'customer_id' => $customer->id,
        'issue_date'  => now(),
        'series'      => 'A',
        'number'      => 1,
        'type'        => 'final',
        'status'      => 1,
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
        'issue_date'  => now(),
        'series'      => 'P',
        'number'      => 1,
    ];

    $proforma = $this->invoiceService->createProforma($invoiceData);

    expect($proforma->serie)->toBe(\AichaDigital\Larabill\Enums\InvoiceSerieType::PROFORMA)
        ->and($proforma->prefix)->toBe('PRO')
        ->and($proforma->status)->toBe(\AichaDigital\Larabill\Enums\InvoiceStatus::DRAFT) // DRAFT
        ->and($proforma->is_immutable)->toBeFalse();
});

it('can convert proforma to final invoice', function () {
    $customer = Customer::factory()->create();

    $proforma = $this->invoiceService->createProforma([
        'customer_id' => $customer->id,
        'issue_date'  => now(),
        'series'      => 'P',
        'number'      => 1,
    ]);

    $finalInvoice = $this->invoiceService->convertProformaToInvoice($proforma);

    expect($finalInvoice)->toBeInstanceOf(Invoice::class)
        ->and($finalInvoice->serie)->toBe(\AichaDigital\Larabill\Enums\InvoiceSerieType::INVOICE)
        ->and($finalInvoice->id)->not->toBe($proforma->id)
        ->and($proforma->fresh()->is_immutable)->toBeTrue()
        ->and($proforma->fresh()->status)->toBe(\AichaDigital\Larabill\Enums\InvoiceStatus::CONVERTED) // CONVERTED
        ->and($proforma->fresh()->converted_invoice_id)->toBe($finalInvoice->id);
});

it('cannot convert already converted proforma', function () {
    $customer = Customer::factory()->create();

    $proforma = $this->invoiceService->createProforma([
        'customer_id' => $customer->id,
        'issue_date'  => now(),
        'series'      => 'P',
        'number'      => 1,
    ]);

    // Convert once
    $this->invoiceService->convertProformaToInvoice($proforma);

    // Try to convert again - should throw exception
    $this->invoiceService->convertProformaToInvoice($proforma->fresh());
})->throws(\InvalidArgumentException::class, 'already converted');

it('can create invoice items with tax calculation', function () {
    $customer = Customer::factory()->create();
    $article  = \AichaDigital\Larabill\Models\Article::factory()->create([
        'base_price' => 10000, // 100.00 EUR en Base-100
    ]);

    $invoice = $this->invoiceService->createInvoice([
        'customer_id' => $customer->id,
        'issue_date'  => now(),
        'series'      => 'A',
        'number'      => 1,
        'type'        => 'final',
        'status'      => 1,
        'items'       => [
            [
                'quantity'    => 100, // 1.0 unit in base100
                'base_price'  => 10000, // 100.00 EUR
                'description' => 'Test line',
            ],
        ],
    ]);

    expect($invoice->items)->toHaveCount(1)
        ->and($invoice->items->first()->quantity)->toBe(100) // 1.0 unit in base100
        ->and($invoice->items->first()->unit_price)->toBe(10000)
        ->and($invoice->items->first()->taxable_amount)->toBe(10000)
        ->and($invoice->items->first()->total_tax_amount)->toBe(0)
        ->and($invoice->items->first()->total_amount)->toBe(10000);
});

it('locks proforma after conversion', function () {
    $customer = Customer::factory()->create();

    $proforma = $this->invoiceService->createProforma([
        'customer_id' => $customer->id,
        'issue_date'  => now(),
        'series'      => 'P',
        'number'      => 1,
    ]);

    $this->invoiceService->convertProformaToInvoice($proforma);

    $proforma->refresh();

    expect($proforma->is_immutable)->toBeTrue()
        ->and($proforma->status)->toBe(\AichaDigital\Larabill\Enums\InvoiceStatus::CONVERTED); // CONVERTED
});
