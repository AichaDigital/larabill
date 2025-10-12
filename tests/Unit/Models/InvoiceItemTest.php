<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{Invoice, InvoiceItem};

it('can create an invoice item', function () {
    $invoice = Invoice::create([
        'number'     => 'FAC-0001',
        'type'       => 'invoice',
        'status'     => 'draft',
        'user_id'    => 1,
        'subtotal'   => 100.0, // Base100 cast handles conversion
        'tax_amount' => 21.0,
        'total'      => 121.0,
    ]);

    $item = InvoiceItem::create([
        'invoice_id'  => $invoice->id,
        'description' => 'Test Service',
        'quantity'    => 1.0, // Base100 cast handles conversion
        'unit_price'  => 100.0,
        'subtotal'    => 100.0,
        'tax_rate'    => 21.0, // 21% in base-100
        'tax_amount'  => 21.0,
        'total'       => 121.0,
    ]);

    expect($item->description)->toBe('Test Service');
    expect($item->quantity)->toBe(1.0); // Base100 cast returns float
    expect($item->unit_price)->toBe(100.0);
    expect($item->subtotal)->toBe(100.0);
    expect($item->tax_rate)->toBe(21.0);
    expect($item->tax_amount)->toBe(21.0);
    expect($item->total)->toBe(121.0);
});

it('belongs to an invoice', function () {
    $invoice = Invoice::create([
        'number'     => 'FAC-0001',
        'type'       => 'invoice',
        'status'     => 'draft',
        'user_id'    => 1,
        'subtotal'   => 100.0, // Base100 cast handles conversion
        'tax_amount' => 21.0,
        'total'      => 121.0,
    ]);

    $item = InvoiceItem::create([
        'invoice_id'  => $invoice->id,
        'description' => 'Test Service',
        'quantity'    => 1.0, // Base100 cast handles conversion
        'unit_price'  => 100.0,
        'subtotal'    => 100.0,
        'tax_rate'    => 21.0,
        'tax_amount'  => 21.0,
        'total'       => 121.0,
    ]);

    // Refresh to load relationships
    $item = $item->fresh(['invoice']);

    expect($item->invoice)->toBeInstanceOf(Invoice::class);
    expect($item->invoice->id)->toBe($invoice->id);
});
