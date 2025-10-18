<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};
use AichaDigital\Larabill\Models\{Invoice, TaxGroup, TaxRate};
use AichaDigital\Larabill\Services\BillingService;

beforeEach(function () {
    // Create a standard tax group for tests
    $this->taxGroup = TaxGroup::factory()->create();
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
                'unit_price'   => 10000, // €100.00 en base-100
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->fiscal_number)->toStartWith('FAC-');
    expect($invoice->total_amount)->toEqual(12100); // 100 + 21% VAT
    expect($invoice->is_immutable)->toBeFalse();

    // Verify new tax structure in invoice_item
    $item = $invoice->items->first();
    expect($item->total_tax_amount)->toEqual(2100);
    expect($item->taxes_applied)->toBe([
        [
            'source_rate_id' => $this->taxRate->id,
            'name'           => $this->taxRate->name,
            'rate'           => 2100,
            'amount'         => 2100,
        ],
    ]);
});

it('can create an immutable invoice', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 10000,
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
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 10000,
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
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 10000,
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
        'serie' => InvoiceSerieType::INVOICE, // Not proforma
    ]);

    expect(fn () => $service->convertToInvoice($regularInvoice))
        ->toThrow(\InvalidArgumentException::class, 'Only proforma invoices can be converted');
});

it('can generate sequential invoice numbers', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 10000,
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
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 10000,
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
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 10000,
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
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 10000,
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
                'unit_price'   => 10000,
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

it('can create invoice with multiple items', function () {
    $service = new BillingService;

    // Create a second tax group for this test
    $taxGroupReduced = TaxGroup::factory()->create();
    $taxRateReduced  = TaxRate::factory()->create(['rate' => 1000]); // 10%
    $taxGroupReduced->taxRates()->attach($taxRateReduced);

    $invoiceData = [
        'user_id'          => 1,
        'items'            => [
            [
                'description'  => 'Item 1',
                'quantity'     => 2,
                'unit_price'   => 5000, // 2 x 50 = 100
                'tax_group_id' => $this->taxGroup->id, // 21%
            ],
            [
                'description'  => 'Item 2',
                'quantity'     => 1,
                'unit_price'   => 10000, // 1 x 100 = 100
                'tax_group_id' => $taxGroupReduced->id, // 10%
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->items)->toHaveCount(2);

    // Totals check:
    // Item 1: 10000 taxable, 2100 tax -> 12100 total
    // Item 2: 10000 taxable, 1000 tax -> 11000 total
    // Invoice: 20000 taxable, 3100 tax -> 23100 total
    expect($invoice->taxable_amount)->toEqual(20000);
    expect($invoice->total_tax_amount)->toEqual(3100);
    expect($invoice->total_amount)->toEqual(23100);
});

it('can create invoice with custom template', function () {
    $service = new BillingService;

    $invoiceData = [
        'user_id'          => 1,
        'template_name'    => 'custom_template',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 10000,
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
        'due_date'         => $dueDate,
        'payment_terms'    => 'Net 30',
        'items'            => [
            [
                'description'  => 'Test Item',
                'quantity'     => 1,
                'unit_price'   => 10000,
                'tax_group_id' => $this->taxGroup->id,
            ],
        ],
    ];

    $invoice = $service->createInvoice($invoiceData);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->due_date->format('Y-m-d'))->toBe($dueDate->format('Y-m-d'));
    expect($invoice->payment_terms)->toBe('Net 30');
});
