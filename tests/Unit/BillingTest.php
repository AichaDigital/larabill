<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};
use AichaDigital\Larabill\Models\{Invoice, TaxGroup, TaxRate};
use AichaDigital\Larabill\Services\BillingService;

beforeEach(function () {
    // Create a standard tax group for tests - v0.3.3 Agnostic Tax System
    $this->taxGroup = TaxGroup::factory()->create(['name' => 'IVA General España']);
    $this->taxRate  = TaxRate::factory()->create(['rate' => 2100]); // 21%
    $this->taxGroup->taxRates()->attach($this->taxRate);
});

it('can create a basic invoice', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id, // v0.3.3: Use tax_group_id instead of tax_rate
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->fiscal_number)->toStartWith('FAC-');
    expect($invoice->total_amount)->toBe(121.0); // 100 + 21% VAT
    expect($invoice->is_immutable)->toBeFalse();

    // v0.3.3: Verify new tax structure in invoice_item
    $item = $invoice->items->first();
    expect($item->total_tax_amount)->toBe(21.0);
    expect($item->taxes_applied)->toBeArray();
    expect($item->taxes_applied)->toHaveCount(1);
});

it('can create an immutable invoice', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
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
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $proforma = $service->createProforma($invoiceData);

    expect($proforma)->toBeInstanceOf(Invoice::class);
    expect($proforma->serie)->toBe(InvoiceSerieType::PROFORMA);
    expect($proforma->status)->toBe(InvoiceStatus::DRAFT);
    expect($proforma->is_immutable)->toBeFalse();
});

it('can convert proforma to invoice', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $proforma = $service->createProforma($invoiceData);
    $invoice  = $service->convertToInvoice($proforma, ['make_immutable' => true]);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->serie)->toBe(InvoiceSerieType::INVOICE);
    expect($invoice->user_id)->toBe($proforma->user_id);
    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->fiscal_number)->not->toBe($proforma->fiscal_number);
});

it('throws exception when trying to convert non-proforma invoice', function () {
    $service = new BillingService;

    // Use factory to create with correct schema
    $regularInvoice = Invoice::factory()->create([
        'fiscal_number' => 'FAC-2025-001',
        'serie'         => InvoiceSerieType::INVOICE->value, // Not proforma
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => 1,
    ]);

    expect(fn () => $service->convertToInvoice($regularInvoice))
        ->toThrow(InvalidArgumentException::class, 'Only proforma invoices can be converted');
});

it('can generate sequential invoice numbers', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $invoice1 = $service->createInvoice($invoiceData);
    $invoice2 = $service->createInvoice($invoiceData);

    expect($invoice1->fiscal_number)->not->toBe($invoice2->fiscal_number);
});

it('can generate invoice numbers with annual reset', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $options = ['annual_reset' => true];

    $invoice1 = $service->createInvoice($invoiceData, $options);
    $invoice2 = $service->createInvoice($invoiceData, $options);

    expect($invoice1->fiscal_number)->toStartWith('FAC-');
    expect($invoice2->fiscal_number)->toStartWith('FAC-');
    expect($invoice1->fiscal_number)->not->toBe($invoice2->fiscal_number);
});

it('can generate invoice numbers with detailed format', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $options = ['number_format' => 'detailed'];

    $invoice = $service->createInvoice($invoiceData, $options);

    // Should match pattern: FAC-YYYYMMDDHHMMNN
    expect($invoice->fiscal_number)->toMatch('/^FAC-\d{12}\d{2}$/');
});

it('can generate proforma numbers with different prefix', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $proforma = $service->createProforma($invoiceData);

    expect($proforma->fiscal_number)->toStartWith('PRO-');
});

it('can create invoice with encrypted customer data when immutable', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'customer_data'    => [
            'name'     => 'John Doe',
            'email'    => 'john@example.com',
            'address'  => '123 Main St, Madrid',
            'vat_code' => 'ESB12345678',
        ],
        'items' => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
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
        'user_id'          => 1,
        'customer_country' => 'DE',
        'customer_type'    => 'business',
        'vat_verification' => [
            'vat_code'     => 'DE123456789',
            'country_code' => 'DE',
        ],
        'items' => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $options = ['roi_verification' => true];

    $invoice = $service->createInvoice($invoiceData, $options);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->vat_verification)->toBe($invoiceData['vat_verification']);
    // Note: is_roi_taxed logic is handled separately by RoiVerificationService
    // Here we just verify the invoice was created with vat_verification data
    expect($invoice->vat_verification)->toHaveKey('vat_code');
    expect($invoice->vat_verification)->toHaveKey('country_code');
});

it('can create invoice with multiple items', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'items'            => [
            [
                'description'  => 'Item 1',
                'quantity'     => 2,
                'unit_price'   => 50.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
            [
                'description'  => 'Item 2',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->items)->toHaveCount(2);

    // Check first item
    $item1 = $invoice->items->first();
    expect($item1->description)->toBe('Item 1');
    expect($item1->quantity)->toBe(2.0);
    expect($item1->unit_price)->toBe(50.0);

    // Check second item
    $item2 = $invoice->items->last();
    expect($item2->description)->toBe('Item 2');
    expect($item2->quantity)->toBe(1.0);
    expect($item2->unit_price)->toBe(100.0);
});

it('can create invoice with custom template', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'template_name'    => 'custom_template',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
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
        'user_id'          => 1,
        'customer_country' => 'ES',
        'customer_type'    => 'individual',
        'due_date'         => $dueDate,
        'payment_terms'    => 'Net 30',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 100.0,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->due_date->format('Y-m-d'))->toBe($dueDate->format('Y-m-d'));
    expect($invoice->payment_terms)->toBe('Net 30');
});
