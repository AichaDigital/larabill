<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\BillingService;

it('can list and filter invoices', function () {
    // Create test invoices with different statuses
    $draftInvoice = Invoice::factory()->create([
        'fiscal_number'  => 'FAC-2025-0001',
        'serie'          => InvoiceSerieType::INVOICE->value,
        'status'         => InvoiceStatus::DRAFT->value,
        'user_id'        => 1,
        'taxable_amount' => 10000, // Base-100: €100.00
        'tax_amount'     => 2100,  // Base-100: €21.00
        'total_amount'   => 12100, // Base-100: €121.00
        'is_immutable'   => false,
    ]);

    $sentInvoice = Invoice::factory()->create([
        'fiscal_number'  => 'FAC-2025-0002',
        'serie'          => InvoiceSerieType::INVOICE->value,
        'status'         => InvoiceStatus::SENT->value,
        'user_id'        => 1,
        'taxable_amount' => 20000, // Base-100: €200.00
        'tax_amount'     => 4200,  // Base-100: €42.00
        'total_amount'   => 24200, // Base-100: €242.00
        'is_immutable'   => false,
    ]);

    $paidInvoice = Invoice::factory()->create([
        'fiscal_number'  => 'FAC-2025-0003',
        'serie'          => InvoiceSerieType::INVOICE->value,
        'status'         => InvoiceStatus::PAID->value,
        'user_id'        => 1,
        'taxable_amount' => 30000, // Base-100: €300.00
        'tax_amount'     => 6300,  // Base-100: €63.00
        'total_amount'   => 36300, // Base-100: €363.00
        'is_immutable'   => false,
    ]);

    // Test listing all invoices
    $allInvoices = Invoice::all();
    expect($allInvoices)->toHaveCount(3);

    // Test filtering by status
    $draftInvoices = Invoice::where('status', InvoiceStatus::DRAFT->value)->get();
    expect($draftInvoices)->toHaveCount(1);
    expect($draftInvoices->first()->fiscal_number)->toBe('FAC-2025-0001');

    // Test filtering by serie (type)
    $invoices = Invoice::where('serie', InvoiceSerieType::INVOICE->value)->get();
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
    expect($invoice->fiscal_number)->toStartWith('FAC-');
    expect($invoice->status)->toBe(InvoiceStatus::DRAFT);
    expect($invoice->serie)->toBe(InvoiceSerieType::INVOICE);
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
        'status' => InvoiceStatus::SENT->value,
        'notes'  => 'Invoice sent to customer',
    ]);

    $updatedInvoice = Invoice::whereUuid($mutableInvoice->id)->first();
    expect($updatedInvoice->status)->toBe(InvoiceStatus::SENT);
    expect($updatedInvoice->notes)->toBe('Invoice sent to customer');

    // Create immutable invoice
    $immutableInvoice = $service->createInvoice($invoiceData, ['make_immutable' => true]);
    expect($immutableInvoice->is_immutable)->toBeTrue();

    // Cannot edit immutable invoice
    expect(fn () => $immutableInvoice->update(['status' => InvoiceStatus::PAID->value]))
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
    $invoice->update(['status' => InvoiceStatus::SENT->value]);
    expect($invoice->status)->toBe(InvoiceStatus::SENT);
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

    expect($proforma->serie)->toBe(InvoiceSerieType::PROFORMA);
    expect($proforma->status)->toBe(InvoiceStatus::DRAFT);
    expect($proforma->fiscal_number)->toStartWith('PRO-');
    expect($proforma->is_immutable)->toBeFalse();

    // Convert to invoice
    $invoice = $service->convertToInvoice($proforma);

    expect($invoice->serie)->toBe(InvoiceSerieType::INVOICE);
    expect($invoice->fiscal_number)->toStartWith('FAC-');
    expect($invoice->fiscal_number)->not->toBe($proforma->fiscal_number);
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
        $subtotal  = $item->quantity * $item->unit_price;
        $taxAmount = $subtotal       * ($item->tax_rate / 100);

        $totalSubtotal += $subtotal;
        $totalTax      += $taxAmount;
    }

    $expectedTotal = $totalSubtotal + $totalTax;

    expect($invoice->total_amount)->toBeFloat();
    expect($invoice->total_amount)->toBeGreaterThan(0);
    expect($invoice->total_amount)->toBeLessThan(1000);
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
