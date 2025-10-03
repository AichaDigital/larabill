<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;

it('can create an invoice', function () {
    $invoice = new Invoice([
        'number' => 'FAC-0001',
        'type' => 'invoice',
        'status' => 'draft',
        'user_id' => 1,
        'subtotal' => 100.0,
        'tax_amount' => 21.0,
        'total' => 121.0,
    ]);

    expect($invoice->number)->toBe('FAC-0001');
    expect($invoice->type)->toBe('invoice');
    expect($invoice->status)->toBe('draft');
    expect($invoice->user_id)->toBe(1);
    expect($invoice->subtotal)->toBe('100.00');
    expect($invoice->tax_amount)->toBe('21.00');
    expect($invoice->total)->toBe('121.00');
});

it('can make an invoice immutable', function () {
    $invoice = new Invoice([
        'number' => 'FAC-0001',
        'type' => 'invoice',
        'status' => 'draft',
        'user_id' => 1,
        'subtotal' => 100.0,
        'tax_amount' => 21.0,
        'total' => 121.0,
    ]);

    $invoice->makeImmutable();

    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->immutable_at)->not->toBeNull();
});

it('cannot update an immutable invoice', function () {
    $invoice = new Invoice([
        'number' => 'FAC-0001',
        'type' => 'invoice',
        'status' => 'draft',
        'user_id' => 1,
        'is_immutable' => true,
        'immutable_at' => now(),
    ]);

    expect(fn () => $invoice->update(['status' => 'paid']))
        ->toThrow(Exception::class);
});
