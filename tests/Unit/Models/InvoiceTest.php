<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\{InvoiceSerieType, InvoiceStatus};
use AichaDigital\Larabill\Models\Invoice;

it('can create an invoice', function () {
    $invoice = new Invoice([
        'fiscal_number'  => 'FAC-2025-000001',
        'prefix'         => 'FAC',
        'serie'          => InvoiceSerieType::INVOICE->value,
        'series_number'  => 1,
        'fiscal_year'    => 2025,
        'invoice_date'   => now()->toDateString(),
        'issued_at'      => now(),
        'status'         => InvoiceStatus::DRAFT->value,
        'user_id'        => 1,
        'taxable_amount' => 100.0,
        'tax_amount'     => 21.0,
        'total_amount'   => 121.0,
    ]);

    expect($invoice->fiscal_number)->toBe('FAC-2025-000001');
    expect($invoice->serie)->toBe(InvoiceSerieType::INVOICE);
    expect($invoice->status)->toBe(InvoiceStatus::DRAFT);
    expect($invoice->user_id)->toBe(1);
    expect($invoice->taxable_amount)->toBe(100.0); // Base100 cast returns float
    expect($invoice->tax_amount)->toBe(21.0); // Base100 cast returns float
    expect($invoice->total_amount)->toBe(121.0); // Base100 cast returns float
});

it('can make an invoice immutable', function () {
    $invoice = new Invoice([
        'fiscal_number'  => 'FAC-2025-000001',
        'prefix'         => 'FAC',
        'serie'          => InvoiceSerieType::INVOICE->value,
        'series_number'  => 1,
        'fiscal_year'    => 2025,
        'invoice_date'   => now()->toDateString(),
        'issued_at'      => now(),
        'status'         => InvoiceStatus::DRAFT->value,
        'user_id'        => 1,
        'taxable_amount' => 100.0,
        'tax_amount'     => 21.0,
        'total_amount'   => 121.0,
    ]);

    $invoice->makeImmutable();

    expect($invoice->is_immutable)->toBeTrue();
    expect($invoice->immutable_at)->not->toBeNull();
});

it('cannot update an immutable invoice', function () {
    $invoice = new Invoice([
        'fiscal_number'  => 'FAC-2025-000001',
        'prefix'         => 'FAC',
        'serie'          => InvoiceSerieType::INVOICE->value,
        'series_number'  => 1,
        'fiscal_year'    => 2025,
        'invoice_date'   => now()->toDateString(),
        'issued_at'      => now(),
        'status'         => InvoiceStatus::DRAFT->value,
        'user_id'        => 1,
        'is_immutable'   => true,
        'immutable_at'   => now(),
    ]);

    expect(fn () => $invoice->update(['status' => InvoiceStatus::PAID->value]))
        ->toThrow(Exception::class);
});
