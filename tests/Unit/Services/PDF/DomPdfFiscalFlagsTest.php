<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PDF\DomPDFService;

/**
 * AID-245: the PDF service read fiscal flags from the orphan `fiscal_data` /
 * `user_tax_info_encrypted` (no column → always null) so reverse-charge / exempt
 * legends and client data never rendered. They now read from the real sources.
 *
 * Exercised on in-memory Invoices (no persistence / no creating-hook side effects)
 * so it stays isolated from the rest of the PDF suite.
 */
function callDomProtected(DomPDFService $svc, string $method, Invoice $invoice): mixed
{
    $ref = new ReflectionMethod($svc, $method);
    $ref->setAccessible(true);

    return $ref->invoke($svc, $invoice);
}

it('detects reverse charge from is_roi_taxed', function () {
    $svc = new DomPDFService;

    $roi               = new Invoice;
    $roi->is_roi_taxed = true;

    $domestic               = new Invoice;
    $domestic->is_roi_taxed = false;

    expect(callDomProtected($svc, 'isReverseCharge', $roi))->toBeTrue()
        ->and(callDomProtected($svc, 'isReverseCharge', $domestic))->toBeFalse();
});

it('treats a missing is_roi_taxed as not reverse charge (AID-294)', function () {
    $svc = new DomPDFService;

    // A fresh in-memory invoice never had is_roi_taxed set: Eloquent skips the
    // boolean cast on an absent key and returns null, which violated the `: bool`
    // return type with a TypeError instead of degrading to a domestic invoice.
    $invoice = new Invoice;

    expect(callDomProtected($svc, 'isReverseCharge', $invoice))->toBeFalse();
});

it('detects VAT exemption from the userTaxProfile snapshot', function () {
    $svc = new DomPDFService;

    $exempt = new Invoice;
    $exempt->setRelation('userTaxProfile', UserTaxProfile::factory()->make(['is_exempt_vat' => true]));

    $plain = new Invoice;
    $plain->setRelation('userTaxProfile', UserTaxProfile::factory()->make(['is_exempt_vat' => false]));

    expect(callDomProtected($svc, 'isExemptInvoice', $exempt))->toBeTrue()
        ->and(callDomProtected($svc, 'isExemptInvoice', $plain))->toBeFalse();
});

it('builds client data from the userTaxProfile snapshot', function () {
    $svc = new DomPDFService;

    $invoice = new Invoice;
    $invoice->setRelation('userTaxProfile', UserTaxProfile::factory()->make([
        'fiscal_name'  => 'ACME Export SARL',
        'tax_id'       => 'FR12345678901',
        'country_code' => 'FR',
        'city'         => 'Lyon',
    ]));

    $data = callDomProtected($svc, 'getClientData', $invoice);

    expect($data['name'])->toBe('ACME Export SARL')
        ->and($data['tax_id'])->toBe('FR12345678901')
        ->and($data['country'])->toBe('FR')
        ->and($data['city'])->toBe('Lyon');
});

it('returns empty client data when the invoice has no userTaxProfile', function () {
    $svc = new DomPDFService;

    $invoice = new Invoice;
    $invoice->setRelation('userTaxProfile', null);

    expect(callDomProtected($svc, 'getClientData', $invoice))->toBe([]);
});
