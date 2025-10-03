<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\BillingService;

it('can create a basic invoice', function () {
    $service = new BillingService();

    $invoiceData = [
        'user_id' => 1,
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ]
        ]
    ];

    $result = $service->createInvoice($invoiceData);

    expect($result)->toBeArray();
    expect($result)->toHaveKey('invoice_number');
    expect($result)->toHaveKey('total_amount');
    expect($result['total_amount'])->toBe(121.0); // 100 + 21% VAT
});

it('can generate sequential invoice numbers', function () {
    $service = new BillingService();

    $invoiceData = [
        'user_id' => 1,
        'items' => [
            [
                'description' => 'Test Item',
                'quantity' => 1,
                'unit_price' => 100.0,
                'tax_rate' => 21.0,
            ]
        ]
    ];

    $invoice1 = $service->createInvoice($invoiceData);
    $invoice2 = $service->createInvoice($invoiceData);

    expect($invoice1['invoice_number'])->not->toBe($invoice2['invoice_number']);
});
