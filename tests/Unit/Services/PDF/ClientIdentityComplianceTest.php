<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\PDF\DomPDFService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use AichaDigital\Larabill\Tests\TestCase;

/**
 * AID-546 — the recipient block must come from the frozen customer_snapshot
 * (ADR-001), never from the live UserTaxProfile row. Editing the client's
 * profile after issuance used to rewrite the «FACTURAR A» block of every
 * historical PDF: the customer half of the identity defect AID-508 fixed
 * on the issuer side.
 *
 * @return array{0: Invoice, 1: UserTaxProfile}
 */
function makeFrozenClientInvoice(array $profileAttributes = []): array
{
    // The suite's users live in TestUser (test_users). Without this override the
    // billableUser() relation resolves the models.user mapping default — a model
    // bound to another table — and the creating hook silently skips the
    // customer_snapshot generation this test needs to exercise for real.
    config()->set('larabill.models.user', TestUser::class);

    // Same guard as makeContentInvoice(): a second active config would trip
    // FiscalIntegrityChecker inside Invoice::boot()'s creating hook.
    if (CompanyFiscalConfig::query()->where('is_active', true)->whereNull('valid_until')->doesntExist()) {
        CompanyFiscalConfig::factory()->create();
    }

    $profile = UserTaxProfile::factory()->create($profileAttributes + [
        'owner_user_id' => TestCase::USER_UUID_1,
        'fiscal_name'   => 'Cliente Original SL',
        'tax_id'        => 'ESB11111111',
        'address'       => 'Calle Original 1',
        'city'          => 'Sevilla',
        'zip_code'      => '41001',
        'country_code'  => 'ES',
        'is_exempt_vat' => false,
    ]);

    // The creating hook resolves the profile and freezes both snapshots.
    $invoice = Invoice::factory()->create([
        'fiscal_number'    => 'FROZEN-CLIENT-1',
        'serie'            => InvoiceSerieType::INVOICE->value,
        'status'           => InvoiceStatus::DRAFT->value,
        'user_id'          => TestCase::USER_UUID_1,
        'billable_user_id' => TestCase::USER_UUID_1,
        'invoice_date'     => '2026-07-16',
    ]);

    return [$invoice->fresh(), $profile];
}

function clientDataFor(Invoice $invoice): array
{
    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'getClientData');

    return $method->invoke($engine, $invoice);
}

function fiscalFlagsFor(Invoice $invoice): array
{
    $engine = new DomPDFService([]);
    $method = new ReflectionMethod($engine, 'prepareTemplateData');

    return $method->invoke($engine, $invoice, null, false)['fiscal_data'];
}

it('reads the client from the frozen snapshot, even after the live profile is edited', function () {
    [$invoice, $profile] = makeFrozenClientInvoice();

    expect($invoice->customer_snapshot)->not->toBeNull();

    $profile->update([
        'fiscal_name' => 'Cliente Editado SA',
        'tax_id'      => 'ESB99999999',
        'address'     => 'Calle Editada 99',
        'city'        => 'Madrid',
        'zip_code'    => '28001',
    ]);

    $client = clientDataFor($invoice->fresh());

    expect($client['name'])->toBe('Cliente Original SL')
        ->and($client['tax_id'])->toBe('ESB11111111')
        ->and($client['address'])->toBe('Calle Original 1')
        ->and($client['city'])->toBe('Sevilla')
        ->and($client['postal_code'])->toBe('41001')
        ->and($client['country'])->toBe('ES');
});

it('renders the frozen client identity in the template, never the edited one', function () {
    [$invoice, $profile] = makeFrozenClientInvoice();

    $profile->update(['fiscal_name' => 'Cliente Editado SA']);

    $engine  = new DomPDFService([]);
    $prepare = new ReflectionMethod($engine, 'prepareTemplateData');
    $render  = new ReflectionMethod($engine, 'renderTemplate');

    $html = $render->invoke(
        $engine,
        'larabill::pdf.invoice.fiscal',
        $prepare->invoke($engine, $invoice->fresh(), null, false)
    );

    expect($html)->toContain('Cliente Original SL')
        ->and($html)->not->toContain('Cliente Editado SA');
});

it('freezes the VAT exemption flag with the invoice, not with the live profile', function () {
    [$invoice, $profile] = makeFrozenClientInvoice(['is_exempt_vat' => true]);

    $profile->update(['is_exempt_vat' => false]);

    expect(fiscalFlagsFor($invoice->fresh())['exempt'])->toBeTrue();
});

it('falls back to the live profile for legacy invoices frozen before the snapshot existed', function () {
    [$invoice, $profile] = makeFrozenClientInvoice();

    // Simulate a pre-ADR-001 invoice: FK reference present, no encrypted snapshot.
    $invoice->forceFill(['customer_snapshot' => null])->saveQuietly();

    $profile->update(['fiscal_name' => 'Cliente Editado SA']);

    // Best-effort identity, the documented limitation of the legacy path
    // (mirror of legacyIssuerData): the live row is all there is.
    expect(clientDataFor($invoice->fresh())['name'])->toBe('Cliente Editado SA');
});

it('returns no client block when there is neither snapshot nor profile', function () {
    if (CompanyFiscalConfig::query()->where('is_active', true)->whereNull('valid_until')->doesntExist()) {
        CompanyFiscalConfig::factory()->create();
    }

    $invoice = Invoice::factory()->create([
        'fiscal_number' => 'NO-CLIENT-1',
        'serie'         => InvoiceSerieType::INVOICE->value,
        'status'        => InvoiceStatus::DRAFT->value,
        'user_id'       => TestCase::USER_UUID_1,
    ]);

    expect(clientDataFor($invoice->fresh()))->toBe([]);
});
