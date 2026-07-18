<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\TemplateInvoiceType;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PDF\DomPDFService;

/**
 * AID-502 (ADR-011, D1): the template type has a SINGLE derivation from the
 * invoice — resolveTemplateType() — consolidating the routing logic that was
 * scattered across getTemplateForInvoice(). The fiscal serie vocabulary
 * ('invoice', 'simplified', ...) never reaches the template registry; the
 * presentation vocabulary is TemplateInvoiceType.
 *
 * Exercised on in-memory Invoices (no persistence / no creating-hook side
 * effects), same isolation pattern as DomPdfFiscalFlagsTest.
 */
function resolveType(Invoice $invoice): TemplateInvoiceType
{
    $ref = new ReflectionMethod(DomPDFService::class, 'resolveTemplateType');
    $ref->setAccessible(true);

    return $ref->invoke(new DomPDFService, $invoice);
}

it('resolves a proforma to the PROFORMA template type', function () {
    $invoice        = new Invoice;
    $invoice->serie = InvoiceSerieType::PROFORMA;

    expect(resolveType($invoice))->toBe(TemplateInvoiceType::PROFORMA);
});

it('resolves an ROI invoice to the REVERSE_CHARGE template type', function () {
    $invoice               = new Invoice;
    $invoice->serie        = InvoiceSerieType::INVOICE;
    $invoice->is_roi_taxed = true;

    expect(resolveType($invoice))->toBe(TemplateInvoiceType::REVERSE_CHARGE);
});

it('resolves a VAT-exempt recipient to the EXEMPT template type', function () {
    $invoice        = new Invoice;
    $invoice->serie = InvoiceSerieType::INVOICE;
    $invoice->setRelation('userTaxProfile', UserTaxProfile::factory()->make(['is_exempt_vat' => true]));

    expect(resolveType($invoice))->toBe(TemplateInvoiceType::EXEMPT);
});

it('gives reverse charge precedence over exemption', function () {
    // Order preserved from the pre-ADR-011 routing: ROI wins.
    $invoice               = new Invoice;
    $invoice->serie        = InvoiceSerieType::INVOICE;
    $invoice->is_roi_taxed = true;
    $invoice->setRelation('userTaxProfile', UserTaxProfile::factory()->make(['is_exempt_vat' => true]));

    expect(resolveType($invoice))->toBe(TemplateInvoiceType::REVERSE_CHARGE);
});

it('resolves every fiscal serie without special conditions to FISCAL', function (InvoiceSerieType $serie) {
    $invoice        = new Invoice;
    $invoice->serie = $serie;

    expect(resolveType($invoice))->toBe(TemplateInvoiceType::FISCAL);
})->with([
    'standard invoice' => InvoiceSerieType::INVOICE,
    'simplified'       => InvoiceSerieType::SIMPLIFIED,
    'rectificative'    => InvoiceSerieType::RECTIFICATIVE,
]);
