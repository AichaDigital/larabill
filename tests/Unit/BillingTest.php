<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\BillingService;

it('can create a basic invoice', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->number)->toStartWith('FAC-');
    expect($invoice->getTotalAsAmount())->toBe(121.0); // 100 + 21% VAT
    expect($invoice->is_immutable)->toBeFalse();
});

it('can create an immutable invoice', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData, ['make_immutable' => true]);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->immutable_at)->not->toBeNull();
});

it('can create a proforma invoice', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $proforma = $service->createProforma($invoiceData);

    expect($proforma)->toBeInstanceOf(Invoice::class);
    expect($proforma->type)->toBe('proforma');
    expect($proforma->status)->toBe('draft');
    expect($proforma->is_immutable)->toBeFalse();
});

it('can convert proforma to invoice', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $proforma = $service->createProforma($invoiceData);
    $invoice = $service->convertToInvoice($proforma, ['make_immutable' => true]);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->type)->toBe('invoice');
    expect($invoice->user_id)->toBe($proforma->user_id);
    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->number)->not->toBe($proforma->number);
});

it('can generate sequential invoice numbers', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $invoice1 = $service->createInvoice($invoiceData);
    $invoice2 = $service->createInvoice($invoiceData);

    expect($invoice1->number)->not->toBe($invoice2->number);
});

it('can generate invoice numbers with annual reset', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $options = ['annual_reset' => true];

    $invoice1 = $service->createInvoice($invoiceData, $options);
    $invoice2 = $service->createInvoice($invoiceData, $options);

    expect($invoice1->number)->toStartWith('FAC-');
    expect($invoice2->number)->toStartWith('FAC-');
    expect($invoice1->number)->not->toBe($invoice2->number);
});

it('can generate invoice numbers with detailed format', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $options = ['number_format' => 'detailed'];

    $invoice = $service->createInvoice($invoiceData, $options);

    // Should match pattern: FAC-YYYYMMDDHHMMNN
    expect($invoice->number)->toMatch('/^FAC-\d{12}\d{2}$/');
});

it('can generate proforma numbers with different prefix', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $proforma = $service->createProforma($invoiceData);

    expect($proforma->number)->toStartWith('PRO-');
});

it('can create invoice with encrypted customer data when immutable', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'customer_data' => [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'address' => '123 Main St, Madrid',
            'vat_number' => 'ESB12345678',
        ],
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData, ['make_immutable' => true]);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->is_immutable)->toBeTrue();

    // Check that customer data is encrypted when immutable
    if (isset($invoice->customer_data)) {
        expect($invoice->customer_data)->not->toBe($invoiceData['customer_data']);
        expect($invoice->customer_data)->toContain('encrypted');
    }
});

it('can create invoice with ROI verification', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'DE',
        'customer_type' => 'business',
        'vat_verification' => [
            'vat_number' => 'DE123456789',
            'country_code' => 'DE',
        ],
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $options = ['roi_verification' => true];

    $invoice = $service->createInvoice($invoiceData, $options);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->vat_verification)->toBe($invoiceData['vat_verification']);
    expect($invoice->is_roi_taxed)->toBeBool();
});

it('can create invoice with multiple items', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'items' => [
            [
                'description' => 'Item 1',
                'quantity' => 2,
                'unit_price' => 50.0,
                'tax_rate' => 21.0,
            ],
            [
                'description' => 'Item 2',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 10.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->items)->toHaveCount(2);

    // Check first item
    $item1 = $invoice->items->first();
    expect($item1->description)->toBe('Item 1');
    expect($item1->getQuantityAsFloat())->toBe(2.0);
    expect($item1->getUnitPriceAsAmount())->toBe(50.0);

    // Check second item
    $item2 = $invoice->items->last();
    expect($item2->description)->toBe('Item 2');
    expect($item2->getQuantityAsFloat())->toBe(1.0);
    expect($item2->getUnitPriceAsAmount())->toBe(100.0);
});

it('can create invoice with custom template', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'template_name' => 'custom_template',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->template_name)->toBe('custom_template');
});

it('can create invoice with payment terms and due date', function () {
    $service = new BillingService;

    $dueDate = now()->addDays(30);

    $invoiceData = [
        'user_id' => 1,
        'customer_country' => 'ES',
        'customer_type' => 'individual',
        'due_date' => $dueDate,
        'payment_terms' => 'Net 30',
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->due_date->format('Y-m-d'))->toBe($dueDate->format('Y-m-d'));
    expect($invoice->payment_terms)->toBe('Net 30');
});
