<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Exceptions\FiscalContentMissingException;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Services\PDF\FiscalContentValidator;

/**
 * AID-502 (ADR-011, D2a): the post-render guard of the safe-restyle guarantee.
 * Every non-empty mandatory fiscal datum the data layer handed to the template
 * must appear in the rendered HTML — a consumer restyle may change layout
 * freely, but it may not omit or rewrite fiscal values. The validator guards
 * RENDER FIDELITY, not data completeness (an empty datum is an emission
 * concern, out of its scope).
 */
function fiscalInvoiceFor(InvoiceSerieType $serie = InvoiceSerieType::INVOICE): Invoice
{
    $invoice                = new Invoice;
    $invoice->serie         = $serie;
    $invoice->fiscal_number = 'FAC-2026-000123';
    $invoice->invoice_date  = \Carbon\Carbon::parse('2026-07-18');

    return $invoice;
}

function completeTemplateData(): array
{
    return [
        'company' => ['name' => 'ACME Digital S.L.', 'tax_id' => 'B12345678'],
        'client'  => ['name' => 'Cliente & Asociados', 'tax_id' => 'X1234567L'],
        'items'   => [
            ['description' => 'Servicio de hosting anual', 'unit_price' => '100.00'],
        ],
        'totals' => [
            'subtotal'      => '100.00',
            'total'         => '121.00',
            'tax_breakdown' => [
                ['name' => 'IVA 21%', 'rate' => '21.00', 'base' => '100.00', 'amount' => '21.00'],
            ],
        ],
        'operation_date' => null,
    ];
}

function htmlWithEverything(): string
{
    return <<<'HTML'
        <html><body>
        <h1>Factura FAC-2026-000123</h1>
        <p>Fecha de expedición: 18/07/2026</p>
        <div>ACME Digital S.L. — B12345678</div>
        <div>Cliente &amp; Asociados (X1234567L)</div>
        <table><tr><td>Servicio de hosting anual</td><td>100.00 €</td></tr></table>
        <p>Base imponible: 100.00 € · IVA 21% (21.00%): 21.00 €</p>
        <p>Total: 121.00 €</p>
        </body></html>
        HTML;
}

it('passes a rendered output carrying every mandatory datum', function () {
    (new FiscalContentValidator)->validate(fiscalInvoiceFor(), completeTemplateData(), htmlWithEverything());
})->throwsNoExceptions();

it('throws naming the missing field when the number is dropped', function () {
    $html = str_replace('FAC-2026-000123', '', htmlWithEverything());

    expect(fn () => (new FiscalContentValidator)->validate(fiscalInvoiceFor(), completeTemplateData(), $html))
        ->toThrow(FiscalContentMissingException::class, 'fiscal_number');
});

it('accepts the expedition date in any tolerated format', function (string $rendered) {
    $html = str_replace('18/07/2026', $rendered, htmlWithEverything());

    (new FiscalContentValidator)->validate(fiscalInvoiceFor(), completeTemplateData(), $html);
})->with([
    'ISO'         => '2026-07-18',
    'dashed'      => '18-07-2026',
    'dotted'      => '18.07.2026',
])->throwsNoExceptions();

it('throws when the expedition date appears in no tolerated format', function () {
    $html = str_replace('18/07/2026', '18 de julio de 2026', htmlWithEverything());

    expect(fn () => (new FiscalContentValidator)->validate(fiscalInvoiceFor(), completeTemplateData(), $html))
        ->toThrow(FiscalContentMissingException::class, 'invoice_date');
});

it('requires the operation date verbatim when the data layer computed one', function () {
    $data                   = completeTemplateData();
    $data['operation_date'] = '01/07/2026';

    expect(fn () => (new FiscalContentValidator)->validate(fiscalInvoiceFor(), $data, htmlWithEverything()))
        ->toThrow(FiscalContentMissingException::class, 'operation_date');
});

it('does not require customer identification on a simplified invoice', function () {
    $html = str_replace(['Cliente &amp; Asociados', 'X1234567L'], '', htmlWithEverything());

    (new FiscalContentValidator)->validate(fiscalInvoiceFor(InvoiceSerieType::SIMPLIFIED), completeTemplateData(), $html);
})->throwsNoExceptions();

it('requires customer identification on a standard invoice', function () {
    $html = str_replace(['Cliente &amp; Asociados', 'X1234567L'], '', htmlWithEverything());

    expect(fn () => (new FiscalContentValidator)->validate(fiscalInvoiceFor(), completeTemplateData(), $html))
        ->toThrow(FiscalContentMissingException::class, 'customer.name');
});

it('skips mandatory slots whose datum is empty in the data layer', function () {
    // Render fidelity, not data completeness: an issuer without tax_id is an
    // emission defect — the render cannot print what it was never handed.
    $data                      = completeTemplateData();
    $data['company']['tax_id'] = null;

    $html = str_replace('B12345678', '', htmlWithEverything());

    (new FiscalContentValidator)->validate(fiscalInvoiceFor(), $data, $html);
})->throwsNoExceptions();

it('finds values regardless of markup, entities and whitespace', function () {
    // The needle survives inline tags, HTML entities and reflowed whitespace.
    $html = str_replace(
        'ACME Digital S.L. — B12345678',
        "<strong>ACME</strong> Digital\n   S.L. — <em>B123</em>45678",
        htmlWithEverything()
    );

    (new FiscalContentValidator)->validate(fiscalInvoiceFor(), completeTemplateData(), $html);
})->throwsNoExceptions();

it('requires every tax breakdown row, not just the first', function () {
    $data                                      = completeTemplateData();
    $data['totals']['tax_breakdown'][]         = ['name' => 'IVA 10%', 'rate' => '10.00', 'base' => '50.00', 'amount' => '5.00'];

    expect(fn () => (new FiscalContentValidator)->validate(fiscalInvoiceFor(), $data, htmlWithEverything()))
        ->toThrow(FiscalContentMissingException::class, 'tax_breakdown.1');
});

it('lists every missing field in one exception', function () {
    $html = '<html><body>nothing fiscal here</body></html>';

    try {
        (new FiscalContentValidator)->validate(fiscalInvoiceFor(), completeTemplateData(), $html);
        $this->fail('Expected FiscalContentMissingException');
    } catch (FiscalContentMissingException $e) {
        expect($e->getMessage())
            ->toContain('fiscal_number')
            ->toContain('invoice_date')
            ->toContain('issuer.name')
            ->toContain('issuer.tax_id')
            ->toContain('customer.tax_id')
            ->toContain('items.0.description')
            ->toContain('items.0.unit_price')
            ->toContain('totals.subtotal')
            ->toContain('totals.total')
            ->toContain('tax_breakdown.0');
    }
});
