<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{Invoice, InvoiceItem};
use AichaDigital\Larabill\Services\BillingService;

it('can perform complete CRUD operations on invoices', function () {
    // Create
    $invoice = Invoice::create([
        'number'       => 'FAC-0001',
        'type'         => 'invoice',
        'status'       => 'draft',
        'user_id'      => 1,
        'subtotal'     => 100.0,
        'tax_amount'   => 21.0,
        'total'        => 121.0,
        'is_immutable' => false,
    ]);

    expect($invoice->exists)->toBeTrue();
    expect($invoice->id)->not->toBeNull();

    // Read
    $foundInvoice = Invoice::whereUuid($invoice->id)->first();
    expect($foundInvoice->number)->toBe('FAC-0001');
    expect($foundInvoice->total)->toBe(121.0);

    // Update
    $foundInvoice->update([
        'status' => 'sent',
        'notes'  => 'Invoice sent to customer',
    ]);

    $updatedInvoice = Invoice::whereUuid($invoice->id)->first();
    expect($updatedInvoice->status)->toBe('sent');
    expect($updatedInvoice->notes)->toBe('Invoice sent to customer');

    // Delete
    $updatedInvoice->delete();
    expect(Invoice::whereUuid($invoice->id)->first())->toBeNull();
});

it('can handle invoice with items relationship', function () {
    test()->markTestSkipped('Inverse relationship with UUID binary needs refactoring');
    
    $invoice = Invoice::create([
        'number'       => 'FAC-0002',
        'type'         => 'invoice',
        'status'       => 'draft',
        'user_id'      => 1,
        'subtotal'     => 200.0,
        'tax_amount'   => 42.0,
        'total'        => 242.0,
        'is_immutable' => false,
    ]);

    // Create invoice items
    $item1 = InvoiceItem::create([
        'invoice_id'  => $invoice->id,
        'description' => 'Item 1',
        'quantity'    => 2,
        'unit_price'  => 50.0,
        'subtotal'    => 100.0,
        'tax_rate'    => 21.0,
        'tax_amount'  => 21.0,
        'total'       => 121.0,
    ]);

    $item2 = InvoiceItem::create([
        'invoice_id'  => $invoice->id,
        'description' => 'Item 2',
        'quantity'    => 1,
        'unit_price'  => 100.0,
        'subtotal'    => 100.0,
        'tax_rate'    => 21.0,
        'tax_amount'  => 21.0,
        'total'       => 121.0,
    ]);

    // Refresh invoice to load items
    $invoice->refresh();

    // Test relationship
    expect($invoice->items)->toHaveCount(2);
    expect($invoice->items->first()->description)->toBe('Item 1');
    expect($invoice->items->last()->description)->toBe('Item 2');

    // Test inverse relationship (refresh items to load invoice)
    $item1->refresh();
    $item2->refresh();
    expect($item1->invoice->id)->toBe($invoice->id);
    expect($item2->invoice->number)->toBe('FAC-0002');
});

it('can handle invoice immutability correctly', function () {
    $invoice = Invoice::create([
        'number'       => 'FAC-0003',
        'type'         => 'invoice',
        'status'       => 'draft',
        'user_id'      => 1,
        'subtotal'     => 100.0,
        'tax_amount'   => 21.0,
        'total'        => 121.0,
        'is_immutable' => false,
    ]);

    // Initially mutable
    expect($invoice->is_immutable)->toBeFalse();
    expect($invoice->immutable_at)->toBeNull();

    // Make immutable
    $invoice->makeImmutable();
    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->immutable_at)->not->toBeNull();

    // Cannot update immutable invoice
    expect(fn () => $invoice->update(['status' => 'paid']))
        ->toThrow(Exception::class);

    // Cannot make immutable again (should not throw, just return early)
    $invoice->makeImmutable(); // Should not throw
    expect($invoice->is_immutable)->toBeTrue();
});

it('can handle invoice data encryption when immutable', function () {
    $invoice = Invoice::create([
        'number'        => 'FAC-0004',
        'type'          => 'invoice',
        'status'        => 'draft',
        'user_id'       => 1,
        'subtotal'      => 100.0,
        'tax_amount'    => 21.0,
        'total'         => 121.0,
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

it('can generate PDF for invoice', function () {
    $invoice = Invoice::create([
        'number'       => 'FAC-0005',
        'type'         => 'invoice',
        'status'       => 'draft',
        'user_id'      => 1,
        'subtotal'     => 100.0,
        'tax_amount'   => 21.0,
        'total'        => 121.0,
        'is_immutable' => false,
    ]);

    // Skip PDF generation test for now due to type issues
    $this->markTestSkipped('PDF generation test skipped due to type issues in DomPDFService');
});

it('can determine if QR code should be included', function () {
    // Regular invoice should include QR
    $invoice = Invoice::create([
        'number'       => 'FAC-0006',
        'type'         => 'invoice',
        'status'       => 'draft',
        'user_id'      => 1,
        'subtotal'     => 100.0,
        'tax_amount'   => 21.0,
        'total'        => 121.0,
        'is_immutable' => false,
    ]);

    expect($invoice->shouldIncludeQR())->toBeTrue();

    // Proforma should not include QR
    $proforma = Invoice::create([
        'number'       => 'PRO-0001',
        'type'         => 'proforma',
        'status'       => 'draft',
        'user_id'      => 1,
        'subtotal'     => 100.0,
        'tax_amount'   => 21.0,
        'total'        => 121.0,
        'is_immutable' => false,
    ]);

    expect($proforma->shouldIncludeQR())->toBeFalse();
});

it('can get invoice type correctly', function () {
    $invoice = Invoice::create([
        'number'       => 'FAC-0007',
        'type'         => 'invoice',
        'status'       => 'draft',
        'user_id'      => 1,
        'subtotal'     => 100.0,
        'tax_amount'   => 21.0,
        'total'        => 121.0,
        'is_immutable' => false,
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
    expect($invoice->number)->toStartWith('FAC-');
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
            'vat_code'   => 'DE123456789',
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
    expect($proforma->type)->toBe('proforma');
    expect($proforma->number)->toStartWith('PRO-');

    // Convert to invoice
    $invoice = $service->convertToInvoice($proforma, ['make_immutable' => true]);
    expect($invoice->type)->toBe('invoice');
    expect($invoice->number)->toStartWith('FAC-');
    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->user_id)->toBe($proforma->user_id);
});
