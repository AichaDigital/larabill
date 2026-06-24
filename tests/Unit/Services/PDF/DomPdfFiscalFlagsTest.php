<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function invoiceWith(array $attributes): Invoice
{
    return Invoice::factory()->create(array_merge(['user_id' => Str::uuid()->toString()], $attributes));
}

/**
 * AID-245: the PDF service read fiscal flags from the orphan `fiscal_data` /
 * `user_tax_info_encrypted` (no column → always null) so reverse-charge / exempt
 * legends and client data never rendered. They now read from the real sources.
 */
function callDomProtected(DomPDFService $svc, string $method, Invoice $invoice): mixed
{
    $ref = new ReflectionMethod($svc, $method);
    $ref->setAccessible(true);

    return $ref->invoke($svc, $invoice);
}

it('detects reverse charge from is_roi_taxed', function () {
    $svc = new DomPDFService;

    $roi      = invoiceWith(['is_roi_taxed' => true]);
    $domestic = invoiceWith(['is_roi_taxed' => false]);

    expect(callDomProtected($svc, 'isReverseCharge', $roi))->toBeTrue()
        ->and(callDomProtected($svc, 'isReverseCharge', $domestic))->toBeFalse();
});

it('detects VAT exemption from the userTaxProfile snapshot', function () {
    $svc = new DomPDFService;

    $exempt    = UserTaxProfile::factory()->create(['is_exempt_vat' => true]);
    $notExempt = UserTaxProfile::factory()->create(['is_exempt_vat' => false]);

    $exemptInvoice = invoiceWith(['user_tax_profile_id' => $exempt->id]);
    $plainInvoice  = invoiceWith(['user_tax_profile_id' => $notExempt->id]);

    expect(callDomProtected($svc, 'isExemptInvoice', $exemptInvoice))->toBeTrue()
        ->and(callDomProtected($svc, 'isExemptInvoice', $plainInvoice))->toBeFalse();
});

it('builds client data from the userTaxProfile snapshot', function () {
    $svc = new DomPDFService;

    $profile = UserTaxProfile::factory()->create([
        'fiscal_name'  => 'ACME Export SARL',
        'tax_id'       => 'FR12345678901',
        'country_code' => 'FR',
        'city'         => 'Lyon',
    ]);
    $invoice = invoiceWith(['user_tax_profile_id' => $profile->id]);

    $data = callDomProtected($svc, 'getClientData', $invoice);

    expect($data['name'])->toBe('ACME Export SARL')
        ->and($data['tax_id'])->toBe('FR12345678901')
        ->and($data['country'])->toBe('FR')
        ->and($data['city'])->toBe('Lyon');
});
