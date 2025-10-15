<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};
use AichaDigital\Larabill\Models\{Invoice, InvoiceItem};
use AichaDigital\Larabill\Services\BillingService;

it('can perform complete CRUD operations on invoices', function () {
    // Create using factory
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FAC-2025-0001',
        'user_id'       => 1,
        'is_immutable'  => false,
        'immutable_at'  => null,
    ]);

    expect($invoice->exists)->toBeTrue();
    expect($invoice->id)->not->toBeNull();

    // Read
    $foundInvoice = Invoice::whereUuid($invoice->id)->first();
    expect($foundInvoice->fiscal_number)->toBe('FAC-2025-0001');
    expect($foundInvoice->total_amount)->toBeFloat();

    // Update
    $foundInvoice->update([
        'status' => InvoiceStatus::SENT->value,
        'notes'  => 'Invoice sent to customer',
    ]);

    $updatedInvoice = Invoice::whereUuid($invoice->id)->first();
    expect($updatedInvoice->status)->toBe(InvoiceStatus::SENT);
    expect($updatedInvoice->notes)->toBe('Invoice sent to customer');

    // Delete
    $updatedInvoice->delete();
    expect(Invoice::whereUuid($invoice->id)->first())->toBeNull();
});

it('can handle invoice with items relationship using binary UUID', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FAC-2025-0002',
        'user_id'       => 1,
    ]);

    // Create invoice items directly
    InvoiceItem::create([
        'invoice_id'     => $invoice->id,
        'description'    => 'Item 1',
        'quantity'       => 200, // Base-100: 2.00
        'unit_price'     => 5000, // Base-100: €50.00
        'taxable_amount' => 10000, // 2.00 * 50.00 = €100.00
        'tax_rate'       => 2100, // 21%
        'tax_amount'     => 2100, // 21% of 100 = €21.00
        'total_amount'   => 12100, // €121.00
    ]);

    InvoiceItem::create([
        'invoice_id'     => $invoice->id,
        'description'    => 'Item 2',
        'quantity'       => 100, // Base-100: 1.00
        'unit_price'     => 10000, // Base-100: €100.00
        'taxable_amount' => 10000, // 1.00 * 100.00 = €100.00
        'tax_rate'       => 2100, // 21%
        'tax_amount'     => 2100, // 21% of 100 = €21.00
        'total_amount'   => 12100, // €121.00
    ]);

    // Refresh invoice to load items
    $invoice->refresh();

    // Test relationship
    expect($invoice->items)->toHaveCount(2);
    expect($invoice->items->first()->description)->toBe('Item 1');
    expect($invoice->items->last()->description)->toBe('Item 2');

    // Test inverse relationship
    $items = InvoiceItem::where('invoice_id', $invoice->id)->get();
    expect($items)->toHaveCount(2);

    $items->each(function ($item) use ($invoice) {
        expect($item->invoice->id)->toBe($invoice->id);
        expect($item->invoice->fiscal_number)->toBe('FAC-2025-0002');
    });
});

it('can handle invoice immutability correctly', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FAC-2025-0003',
        'user_id'       => 1,
        'is_immutable'  => false,
        'immutable_at'  => null,
    ]);

    // Initially mutable
    expect($invoice->is_immutable)->toBeFalse();
    expect($invoice->immutable_at)->toBeNull();

    // Make immutable
    $invoice->makeImmutable();
    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->immutable_at)->not->toBeNull();

    // Cannot update immutable invoice
    expect(fn () => $invoice->update(['status' => InvoiceStatus::PAID->value]))
        ->toThrow(Exception::class);

    // Cannot make immutable again (should not throw, just return early)
    $invoice->makeImmutable(); // Should not throw
    expect($invoice->is_immutable)->toBeTrue();
});

it('can handle invoice data encryption when immutable', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FAC-2025-0004',
        'user_id'       => 1,
        'is_immutable'  => false,
        'customer_data' => [
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'address' => '123 Main St',
        ],
    ]);

    // Before immutability - data is not encrypted
    expect($invoice->customer_data)->not->toBeNull();
    expect($invoice->customer_data['name'])->toBe('John Doe');

    // Make immutable - data should remain the same (encryption not implemented yet)
    $invoice->makeImmutable();

    // After immutability - data should remain the same
    $invoice->refresh();
    expect($invoice->customer_data)->toBe([
        'name'    => 'John Doe',
        'email'   => 'john@example.com',
        'address' => '123 Main St',
    ]);
});

it('can determine if QR code should be included', function () {
    // Regular invoice should include QR
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FAC-2025-0006',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'user_id'       => 1,
    ]);

    expect($invoice->shouldIncludeQR())->toBeTrue();

    // Proforma should not include QR
    $proforma = Invoice::factory()->proforma()->create([
        'fiscal_number' => 'PRO-2025-0001',
        'user_id'       => 1,
    ]);

    expect($proforma->shouldIncludeQR())->toBeFalse();
});

it('can get invoice type correctly', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'FAC-2025-0007',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'user_id'       => 1,
    ]);

    expect($invoice->getInvoiceType())->toBe('invoice');
});

it('can handle invoice with BillingService integration', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description' => 'Integration Test Item',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    // Verify invoice was created correctly
    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->fiscal_number)->toStartWith('FAC-');
    expect($invoice->items)->toHaveCount(1);
    expect($invoice->items->first()->description)->toBe('Integration Test Item');

    // Test immutability
    $immutableInvoice = $service->createInvoice($invoiceData, ['make_immutable' => true]);
    expect($immutableInvoice->is_immutable)->toBeTrue();
});

it('can handle invoice with ROI verification integration', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'DE',
        'customer_type'    => 'business',
        'vat_verification' => [
            'vat_code'     => 'DE123456789',
            'country_code' => 'DE',
        ],
        'items' => [
            [
                'description' => 'ROI Test Item',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData, ['roi_verification' => true]);

    // Verify ROI verification data
    expect($invoice->vat_verification)->toBe($invoiceData['vat_verification']);
    expect($invoice->is_roi_taxed)->toBeBool();
});

it('can handle proforma to invoice conversion', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description' => 'Conversion Test Item',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 21.0,
            ],
        ],
    ];

    // Create proforma
    $proforma = $service->createProforma($invoiceData);
    expect($proforma->serie)->toBe(InvoiceSerieType::PROFORMA);
    expect($proforma->fiscal_number)->toStartWith('PRO-');

    // Convert to invoice
    $invoice = $service->convertToInvoice($proforma, ['make_immutable' => true]);
    expect($invoice->serie)->toBe(InvoiceSerieType::INVOICE);
    expect($invoice->fiscal_number)->toStartWith('FAC-');
    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->user_id)->toBe($proforma->user_id);
});
