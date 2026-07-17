<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Tests\TestCase;

/**
 * AID-508 — the PDF must print the real invoice. getInvoiceItems() was a
 * hardcoded stub returning «Servicio 1» with amounts read from non-existent
 * columns, and no test ever looked at the document's content: they asserted
 * success === true and file_exists().
 */
function makeContentInvoice(): Invoice
{
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'CASTRIS-2026-000001',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'invoice_date'     => '2026-07-16',
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
    ]);

    InvoiceItem::factory()->create([
        'invoice_id'       => $invoice->id,
        'description'      => 'Hosting anual Probe',
        'quantity'         => cents(150),      // 1.50 units
        'unit_price'       => cents(10000),    // €100.00
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
        'taxes_applied'    => [
            ['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100],
        ],
    ]);

    return $invoice->fresh();
}

function invoiceItemsFor(Invoice $invoice): array
{
    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'getInvoiceItems');

    return $method->invoke($engine, $invoice);
}

it('reads the real invoice lines, never a hardcoded stub', function () {
    $items = invoiceItemsFor(makeContentInvoice());

    expect($items)->toHaveCount(1)
        ->and($items[0]['description'])->toBe('Hosting anual Probe')
        ->and($items[0]['description'])->not->toBe('Servicio 1');
});

it('renders each amount as an exact string, honouring its own scale', function () {
    $items = invoiceItemsFor(makeContentInvoice());

    // The trap: the blades divided unit_price by 100 but not quantity. Getting this
    // wrong prints 150.00 units or €0.12 — today it only survives because the stub
    // passes a literal 1.
    expect($items[0]['quantity'])->toBe('1.50')
        ->and($items[0]['unit_price'])->toBe('100.00')
        ->and($items[0]['taxable_amount'])->toBe('100.00')
        ->and($items[0]['tax_amount'])->toBe('21.00')
        ->and($items[0]['total'])->toBe('121.00');
});

it('carries the line tax breakdown from the immutable snapshot', function () {
    $items = invoiceItemsFor(makeContentInvoice());

    expect($items[0]['taxes'])->toHaveCount(1)
        ->and($items[0]['taxes'][0]['name'])->toBe('IVA 21%')
        ->and($items[0]['taxes'][0]['rate'])->toBe('21.00')
        ->and($items[0]['taxes'][0]['amount'])->toBe('21.00');
});

it('returns no lines for an invoice with none, never an invented one', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'EMPTY-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    expect(invoiceItemsFor($invoice))->toBe([]);
});
