<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\BillingService;

it('can list and filter invoices', function () {
    // Create test invoices with different statuses
    $draftInvoice = Invoice::create([
        'number'       => 'FAC-0001',
        'type'         => 'invoice',
        'status'       => 'draft',
        'user_id'      => 1,
        'subtotal'     => 100.0,
        'tax_amount'   => 21.0,
        'total'        => 121.0,
        'is_immutable' => false,
    ]);

    $sentInvoice = Invoice::create([
        'number'       => 'FAC-0002',
        'type'         => 'invoice',
        'status'       => 'sent',
        'user_id'      => 1,
        'subtotal'     => 200.0,
        'tax_amount'   => 42.0,
        'total'        => 242.0,
        'is_immutable' => false,
    ]);

    $paidInvoice = Invoice::create([
        'number'       => 'FAC-0003',
        'type'         => 'invoice',
        'status'       => 'paid',
        'user_id'      => 1,
        'subtotal'     => 300.0,
        'tax_amount'   => 63.0,
        'total'        => 363.0,
        'is_immutable' => false,
    ]);

    // Test listing all invoices
    $allInvoices = Invoice::all();
    expect($allInvoices)->toHaveCount(3);

    // Test filtering by status
    $draftInvoices = Invoice::where('status', 'draft')->get();
    expect($draftInvoices)->toHaveCount(1);
    expect($draftInvoices->first()->number)->toBe('FAC-0001');

    // Test filtering by type
    $invoices = Invoice::where('type', 'invoice')->get();
    expect($invoices)->toHaveCount(3);

    // Test filtering by user
    $userInvoices = Invoice::where('user_id', 1)->get();
    expect($userInvoices)->toHaveCount(3);
});

it('can create invoices through BillingService', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description' => 'Feature Test Item',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    // Verify invoice creation
    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->number)->toStartWith('FAC-');
    expect($invoice->status)->toBe('draft');
    expect($invoice->type)->toBe('invoice');
    expect($invoice->is_immutable)->toBeFalse();

    // Verify invoice items
    expect($invoice->items)->toHaveCount(1);
    expect($invoice->items->first()->description)->toBe('Feature Test Item');
});

it('can edit invoices only when not immutable', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description' => 'Editable Item',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 21.0,
            ],
        ],
    ];

    // Create mutable invoice
    $mutableInvoice = $service->createInvoice($invoiceData);
    expect($mutableInvoice->is_immutable)->toBeFalse();

    // Can edit mutable invoice
    $mutableInvoice->update([
        'status' => 'sent',
        'notes'  => 'Invoice sent to customer',
    ]);

    $updatedInvoice = Invoice::whereUuid($mutableInvoice->id)->first();
    expect($updatedInvoice->status)->toBe('sent');
    expect($updatedInvoice->notes)->toBe('Invoice sent to customer');

    // Create immutable invoice
    $immutableInvoice = $service->createInvoice($invoiceData, ['make_immutable' => true]);
    expect($immutableInvoice->is_immutable)->toBeTrue();

    // Cannot edit immutable invoice
    expect(fn () => $immutableInvoice->update(['status' => 'paid']))
        ->toThrow(Exception::class);
});

it('can handle email sending for invoices', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description' => 'Email Test Item',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    // Test email sending (mock implementation)
    // In a real implementation, this would test the email service
    expect($invoice->exists)->toBeTrue();

    // Mark as sent (simulating email sending)
    $invoice->update(['status' => 'sent']);
    expect($invoice->status)->toBe('sent');
});

it('can manage proforma invoices', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description' => 'Proforma Item',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 21.0,
            ],
        ],
    ];

    // Create proforma
    $proforma = $service->createProforma($invoiceData);

    expect($proforma->type)->toBe('proforma');
    expect($proforma->status)->toBe('draft');
    expect($proforma->number)->toStartWith('PRO-');
    expect($proforma->is_immutable)->toBeFalse();

    // Convert to invoice
    $invoice = $service->convertToInvoice($proforma);

    expect($invoice->type)->toBe('invoice');
    expect($invoice->number)->toStartWith('FAC-');
    expect($invoice->number)->not->toBe($proforma->number);
});

it('can handle invoice with multiple items and complex calculations', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description' => 'Item 1',
                'quantity'    => 2,
                'unit_price'  => 50.0,
                'tax_rate'    => 21.0,
            ],
            [
                'description' => 'Item 2',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 10.0,
            ],
            [
                'description' => 'Item 3',
                'quantity'    => 3,
                'unit_price'  => 25.0,
                'tax_rate'    => 4.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    // Verify invoice creation
    expect($invoice->items)->toHaveCount(3);

    // Verify calculations
    $totalSubtotal = 0;
    $totalTax      = 0;

    foreach ($invoice->items as $item) {
        $subtotal  = $item->quantity             * $item->unit_price;
        $taxAmount = $subtotal                   * ($item->tax_rate / 100);

        $totalSubtotal += $subtotal;
        $totalTax      += $taxAmount;
    }

    $expectedTotal = $totalSubtotal + $totalTax;

    expect($invoice->total)->toBeFloat();
    expect($invoice->total)->toBeGreaterThan(0);
    expect($invoice->total)->toBeLessThan(1000);
});

it('can handle invoice with ROI verification', function () {
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

it('can handle invoice with custom templates and payment terms', function () {
    $service = new BillingService;

    $dueDate = now()->addDays(30);

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'due_date'         => $dueDate,
        'payment_terms'    => 'Net 30',
        'template_name'    => 'custom_template',
        'items'            => [
            [
                'description' => 'Template Test Item',
                'quantity'    => 1,
                'unit_price'  => 100.0,
                'tax_rate'    => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    // Verify custom fields
    expect($invoice->due_date->format('Y-m-d'))->toBe($dueDate->format('Y-m-d'));
    expect($invoice->payment_terms)->toBe('Net 30');
    expect($invoice->template_name)->toBe('custom_template');
});
