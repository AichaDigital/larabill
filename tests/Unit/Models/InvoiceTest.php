<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Tests\TestCase;

it('can create an invoice', function () {
    $invoice = new Invoice([
        'fiscal_number'     => 'FAC-2025-000001',
        'prefix'            => 'FAC',
        'serie'             => InvoiceSerieType::INVOICE->value,
        'series_number'     => TestCase::USER_UUID_1,
        'fiscal_year'       => 2025,
        'invoice_date'      => now()->toDateString(),
        'issued_at'         => now(),
        'status'            => InvoiceStatus::DRAFT->value,
        'user_id'           => TestCase::USER_UUID_1,
        'taxable_amount'    => 10000, // €100.00 in base100
        'total_tax_amount'  => 2100,  // €21.00 in base100
        'total_amount'      => 12100, // €121.00 in base100
    ]);

    expect($invoice->fiscal_number)->toBe('FAC-2025-000001');
    expect($invoice->serie)->toBe(InvoiceSerieType::INVOICE);
    expect($invoice->status)->toBe(InvoiceStatus::DRAFT);
    expect($invoice->user_id)->toBe(TestCase::USER_UUID_1);
    expect($invoice->taxable_amount)->toBe(10000); // Base100Int returns int
    expect($invoice->total_tax_amount)->toBe(2100);
    expect($invoice->total_amount)->toBe(12100);
});

it('can make an invoice immutable', function () {
    $invoice = new Invoice([
        'fiscal_number'  => 'FAC-2025-000001',
        'prefix'         => 'FAC',
        'serie'          => InvoiceSerieType::INVOICE->value,
        'series_number'  => TestCase::USER_UUID_1,
        'fiscal_year'    => 2025,
        'invoice_date'   => now()->toDateString(),
        'issued_at'      => now(),
        'status'         => InvoiceStatus::DRAFT->value,
        'user_id'        => TestCase::USER_UUID_1,
        'taxable_amount' => 10000, // €100.00 in base100
        'tax_amount'     => 2100,  // €21.00 in base100
        'total_amount'   => 12100, // €121.00 in base100
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
        'series_number'  => TestCase::USER_UUID_1,
        'fiscal_year'    => 2025,
        'invoice_date'   => now()->toDateString(),
        'issued_at'      => now(),
        'status'         => InvoiceStatus::DRAFT->value,
        'user_id'        => TestCase::USER_UUID_1,
        'is_immutable'   => true,
        'immutable_at'   => now(),
    ]);

    expect(fn () => $invoice->update(['status' => InvoiceStatus::PAID->value]))
        ->toThrow(Exception::class);
});
