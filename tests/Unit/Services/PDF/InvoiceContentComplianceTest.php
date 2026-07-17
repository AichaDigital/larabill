<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
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

function invoiceTotalsFor(Invoice $invoice): array
{
    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'getInvoiceTotals');

    return $method->invoke($engine, $invoice);
}

it('reads the real totals from the real columns', function () {
    $totals = invoiceTotalsFor(makeContentInvoice());

    // The old code read $invoice->subtotal / ->tax_amount / ->total: columns that do
    // not exist. Eloquent returned null and the blade printed "0.00" without a peep.
    expect($totals['subtotal'])->toBe('100.00')
        ->and($totals['tax_amount'])->toBe('21.00')
        ->and($totals['total'])->toBe('121.00');
});

it('breaks the tax down per rate on a mixed-rate invoice', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'MIXED-1',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'taxable_amount'   => cents(15000),
        'total_tax_amount' => cents(2600),
        'total_amount'     => cents(17600),
    ]);
    InvoiceItem::factory()->create([
        'invoice_id'       => $invoice->id,
        'description'      => 'Standard rate line',
        'quantity'         => cents(100),
        'unit_price'       => cents(10000),
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(2100),
        'total_amount'     => cents(12100),
        'taxes_applied'    => [['source_rate_id' => 1, 'name' => 'IVA 21%', 'rate' => 2100, 'amount' => 2100]],
    ]);
    InvoiceItem::factory()->create([
        'invoice_id'       => $invoice->id,
        'description'      => 'Reduced rate line',
        'quantity'         => cents(100),
        'unit_price'       => cents(5000),
        'taxable_amount'   => cents(5000),
        'total_tax_amount' => cents(500),
        'total_amount'     => cents(5500),
        'taxes_applied'    => [['source_rate_id' => 2, 'name' => 'IVA 10%', 'rate' => 1000, 'amount' => 500]],
    ]);

    $breakdown = invoiceTotalsFor($invoice->fresh())['tax_breakdown'];

    // RD 1619/2012 art. 6.1.g/h: base and rate, broken down when the invoice
    // comprises several. The old blade printed $items[0]['tax_rate'] for the lot.
    expect($breakdown)->toHaveCount(2)
        ->and($breakdown[0])->toMatchArray(['rate' => '21.00', 'name' => 'IVA 21%', 'base' => '100.00', 'amount' => '21.00'])
        ->and($breakdown[1])->toMatchArray(['rate' => '10.00', 'name' => 'IVA 10%', 'base' => '50.00', 'amount' => '5.00']);
});

it('returns an empty breakdown for an invoice with no taxes', function () {
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'NOTAX-1',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(0),
        'total_amount'     => cents(10000),
    ]);
    InvoiceItem::factory()->create([
        'invoice_id'       => $invoice->id,
        'description'      => 'Exempt line',
        'quantity'         => cents(100),
        'unit_price'       => cents(10000),
        'taxable_amount'   => cents(10000),
        'total_tax_amount' => cents(0),
        'total_amount'     => cents(10000),
        'taxes_applied'    => [],
    ]);

    // Never the hardcoded `?? 21` the old blade fell back to.
    expect(invoiceTotalsFor($invoice->fresh())['tax_breakdown'])->toBe([]);
});

it('formats a rate with decimals, which an int cannot carry', function () {
    $invoice = makeContentInvoice();
    $invoice->items()->first()->update([
        'taxes_applied' => [['source_rate_id' => 9, 'name' => 'Recargo 5,2%', 'rate' => 520, 'amount' => 520]],
    ]);

    $breakdown = invoiceTotalsFor($invoice->fresh())['tax_breakdown'];

    expect($breakdown[0]['rate'])->toBe('5.20');
});

function companyDataFor(Invoice $invoice): array
{
    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'getCompanyData');

    return $method->invoke($engine, $invoice);
}

it('reads the issuer from the frozen snapshot, not from config or fantasy', function () {
    $config  = CompanyFiscalConfig::factory()->create([
        'business_name' => 'Castris Conformance SL',
        'tax_id'        => 'ESB12345678',
        'address'       => 'Calle Real 1',
        'city'          => 'Granada',
        'zip_code'      => '18001',
        'country_code'  => 'ES',
    ]);
    $invoice                           = makeContentInvoice();
    $invoice->company_fiscal_config_id = $config->id;
    $invoice->snapshotFiscalConfigs();
    $invoice->save();

    $company = companyDataFor($invoice->fresh());

    expect($company['name'])->toBe('Castris Conformance SL')
        ->and($company['tax_id'])->toBe('ESB12345678')
        ->and($company['address'])->toBe('Calle Real 1')
        ->and($company['postal_code'])->toBe('18001')
        ->and($company['city'])->toBe('Granada')
        // The stub's fantasy, which reached the customer's PDF:
        ->and($company['name'])->not->toBe('Mi Empresa')
        ->and($company['tax_id'])->not->toBe('NIF: 12345678A');
});

it('carries no invented contact details', function () {
    $company = companyDataFor(makeContentInvoice());

    // CompanyFiscalConfig has no phone/email/website. The stub invented
    // +34 123 456 789 / info@empresa.com / https://empresa.com and printed them.
    expect($company)->not->toHaveKeys(['phone', 'email', 'website']);
});
