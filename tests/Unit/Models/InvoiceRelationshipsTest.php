<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can have items relationship', function () {
    $user    = TestUser::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $user->id]);

    InvoiceItem::factory()->count(3)->create(['invoice_id' => $invoice->id]);

    expect($invoice->items)->toHaveCount(3);
});

it('can have user relationship', function () {
    $user    = TestUser::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $user->id]);

    expect($invoice->user)->toBeInstanceOf(TestUser::class)
        ->and($invoice->user->id)->toBe($user->id);
});

it('can have proforma relationship', function () {
    $user     = TestUser::factory()->create();
    $proforma = Invoice::factory()->create(['user_id' => $user->id]);
    $invoice  = Invoice::factory()->create([
        'user_id'     => $user->id,
        'proforma_id' => $proforma->id,
    ]);

    expect($invoice->proforma)->toBeInstanceOf(Invoice::class)
        ->and($invoice->proforma->id)->toBe($proforma->id);
});

it('can have rectificative relationship', function () {
    $user     = TestUser::factory()->create();
    $original = Invoice::factory()->create(['user_id' => $user->id]);
    $rectif   = Invoice::factory()->create([
        'user_id'              => $user->id,
        'rectifies_invoice_id' => $original->id,
    ]);

    expect($rectif->rectifiesInvoice)->toBeInstanceOf(Invoice::class)
        ->and($rectif->rectifiesInvoice->id)->toBe($original->id);
});

it('can have rectificatives collection', function () {
    $user     = TestUser::factory()->create();
    $original = Invoice::factory()->create(['user_id' => $user->id]);

    Invoice::factory()->count(2)->create([
        'user_id'              => $user->id,
        'rectifies_invoice_id' => $original->id,
    ]);

    expect($original->rectificatives)->toHaveCount(2);
});

it('can have converted invoices collection', function () {
    $user     = TestUser::factory()->create();
    $proforma = Invoice::factory()->create(['user_id' => $user->id]);

    Invoice::factory()->count(2)->create([
        'user_id'     => $user->id,
        'proforma_id' => $proforma->id,
    ]);

    expect($proforma->convertedInvoices)->toHaveCount(2);
});

it('keeps the userTaxProfile snapshot accessible after the profile is soft-deleted', function () {
    $user       = TestUser::factory()->create();
    $taxProfile = UserTaxProfile::factory()->create(['owner_user_id' => $user->id]);

    $invoice = Invoice::factory()->immutable()->create([
        'user_id'             => $user->id,
        'user_tax_profile_id' => $taxProfile->id,
    ]);

    // Soft-deleting a tax profile is fiscal-history closure, NOT erasure.
    // The immutable invoice must keep access to its historical snapshot.
    $taxProfile->delete();

    $fresh = Invoice::findOrFail($invoice->id);

    expect($fresh->userTaxProfile)->not->toBeNull()
        ->and($fresh->userTaxProfile->id)->toBe($taxProfile->id)
        ->and($fresh->userTaxProfile->trashed())->toBeTrue();
});

it('keeps the companyFiscalConfig snapshot accessible after the config is soft-deleted', function () {
    $user   = TestUser::factory()->create();
    $config = CompanyFiscalConfig::factory()->create();

    $invoice = Invoice::factory()->immutable()->create([
        'user_id'                  => $user->id,
        'company_fiscal_config_id' => $config->id,
    ]);

    // Same principle as the tax profile: the issuer fiscal snapshot is
    // immutable history and must survive a soft-delete of the config row.
    $config->delete();

    $fresh = Invoice::findOrFail($invoice->id);

    expect($fresh->companyFiscalConfig)->not->toBeNull()
        ->and($fresh->companyFiscalConfig->id)->toBe($config->id)
        ->and($fresh->companyFiscalConfig->trashed())->toBeTrue();
});
