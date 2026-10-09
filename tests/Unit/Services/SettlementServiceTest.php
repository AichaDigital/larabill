<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvalidRectificationAmountException;
use AichaDigital\Larabill\Exceptions\InvoiceNotRectifiableException;
use AichaDigital\Larabill\Exceptions\UnresolvableRectificationRateException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Services\Adapters\VerifactuAdapter;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

/**
 * AID-971 (ADR-014) — SettlementService contract: rectificatives.
 *
 * Eje 1 (artefacto): a rectificative is the artifact linked to a previous
 * invoice via `rectifies_invoice_id`. That FK is the discriminant — never the
 * sign of the amounts — and it is what makes VerifactuAdapter emit TipoFactura
 * R1 + FacturasRectificadas. Eje 2 (amount source): null means the full
 * original (lines cloned verbatim, sign-flipped), an explicit negative
 * base-100 amount means "por diferencias" against the original's FROZEN tax
 * context.
 *
 * Boundary (ADR-014 §2): larabill issues the fiscally correct document; it
 * never decides whether a refund happens, its amount policy, or its channel.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create([
        'is_active'   => true,
        'valid_until' => null,
    ]);

    $this->customer = TestUser::factory()->create();

    $this->settle = app(SettlementService::class);
});

/**
 * Emit a sealed SENT invoice (the recurring-emission precedent, AID-836):
 * €100.00 base + 21% VAT = €121.00, all base-100.
 */
function makeSettlementSourceInvoice(): Invoice
{
    $vatGroup = TaxGroup::factory()->create(['name' => 'IVA General']);
    $rate     = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
    $vatGroup->taxRates()->attach($rate->id);

    $article = Article::factory()->create(['tax_group_id' => $vatGroup->id]);

    return app(InvoiceService::class)->createInvoice([
        'billable_user_id' => TestUser::factory()->create()->id,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            [
                'article_id'  => $article->id,
                'quantity'    => 100,
                'base_price'  => 10000,
                'description' => 'Hosting anual',
            ],
        ],
    ], ['make_immutable' => true]);
}

/**
 * Emit a sealed SENT reverse-charge invoice (AID-929): tax-free article (no
 * tax group) declared `is_roi_taxed` — reverse charge and real tax cannot
 * coexist, so an roi original must carry no real tax to exist at all.
 */
function makeSettlementRoiSourceInvoice(): Invoice
{
    $article = Article::factory()->create();

    return app(InvoiceService::class)->createInvoice([
        'billable_user_id' => TestUser::factory()->create()->id,
        'status'           => InvoiceStatus::SENT->value,
        'is_roi_taxed'     => true,
        'items'            => [
            [
                'article_id'  => $article->id,
                'quantity'    => 100,
                'base_price'  => 10000,
                'description' => 'Servicio intracomunitario',
            ],
        ],
    ], ['make_immutable' => true]);
}

it('issues a linked rectificative in its own RECT series, born sealed', function () {
    $original = makeSettlementSourceInvoice();

    $rect = $this->settle->rectify($original);

    // Eje 1: the FK is the artifact discriminant.
    expect($rect->rectifies_invoice_id)->toBe($original->id)
        ->and($rect->serie)->toBe(InvoiceSerieType::RECTIFICATIVE)
        ->and($rect->prefix)->toBe('RECT')
        ->and($rect->series_number)->toBe(1)
        ->and($rect->fiscal_year)->toBe((int) now()->format('Y'));

    // The rectificative is a fiscal document: born sealed, never a draft.
    expect($rect->status)->toBe(InvoiceStatus::SENT)
        ->and($rect->is_immutable)->toBeTrue()
        ->and($rect->issuer_snapshot)->not->toBeNull()
        ->and($rect->customer_snapshot)->not->toBeNull()
        ->and($rect->fiscal_snapshot)->not->toBeNull();

    // The original is untouched.
    expect($original->fresh()->rectificatives()->count())->toBe(1)
        ->and($original->fresh()->total_amount->unscaledValue())->toBe(12100);
});

it('maps the rectificative to R1 with a FacturasRectificadas reference end-to-end', function () {
    $original = makeSettlementSourceInvoice();
    $rect     = $this->settle->rectify($original);

    $data = VerifactuAdapter::toVerifactuInvoice($rect);

    expect($data['type'])->toBe('R1')
        ->and($data['rectification_type'])->toBe('I')
        ->and($data['metadata']['rectified_invoices'][0]['number'])
        ->toBe($original->prefix.$original->series_number)
        ->and($data['metadata']['rectified_invoices'][0]['issue_date'])
        ->toBe($original->invoice_date->format('Y-m-d'));
});

it('rectifies the total by cloning the lines verbatim, sign-flipped, with frozen taxes', function () {
    $original = makeSettlementSourceInvoice();

    $rect = $this->settle->rectify($original);

    expect($rect->taxable_amount->unscaledValue())->toBe(-10000)
        ->and($rect->total_tax_amount->unscaledValue())->toBe(-2100)
        ->and($rect->total_amount->unscaledValue())->toBe(-12100);

    $line = $rect->items->first();
    expect($line->description)->toBe('Hosting anual')
        ->and($line->taxable_amount->unscaledValue())->toBe(-10000)
        ->and($line->total_tax_amount->unscaledValue())->toBe(-2100)
        ->and($line->total_amount->unscaledValue())->toBe(-12100)
        ->and($line->taxes_applied[0]['rate'])->toBe(2100)
        ->and($line->taxes_applied[0]['amount'])->toBe(-2100);
});

it('rectifies por diferencias against the FROZEN tax context, not the live catalogue', function () {
    $original = makeSettlementSourceInvoice();

    // The catalogue moves after emission (AID-956 doctrine): the live group
    // now says 10%. The rectificative must still bill the rate the original
    // was issued with — frozen in its taxes_applied snapshot.
    $newGroup = TaxGroup::factory()->create(['name' => 'IVA Reducido']);
    $newRate  = TaxRate::factory()->create(['name' => 'IVA Reducido 10%', 'rate' => 1000]);
    $newGroup->taxRates()->attach($newRate->id);
    Article::whereKey($original->items->first()->article_id)->update(['tax_group_id' => $newGroup->id]);

    $rect = $this->settle->rectify($original, -5000);

    $line = $rect->items->first();
    expect($line->taxable_amount->unscaledValue())->toBe(-5000)
        ->and($line->total_tax_amount->unscaledValue())->toBe(-1050)
        ->and($line->total_amount->unscaledValue())->toBe(-6050)
        ->and($line->taxes_applied[0]['rate'])->toBe(2100)
        ->and($line->taxes_applied[0]['amount'])->toBe(-1050);
});

it('refuses to rectify a draft — nothing was ever emitted', function () {
    $draft = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
    ]);

    $this->settle->rectify($draft);
})->throws(InvoiceNotRectifiableException::class);

it('refuses to rectify a proforma — it is not a fiscal document', function () {
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
    ]);

    $this->settle->rectify($proforma);
})->throws(InvoiceNotRectifiableException::class);

it('propagates is_roi_taxed from the original to the rectificative (AID-929)', function () {
    $original = makeSettlementRoiSourceInvoice();

    expect($original->is_roi_taxed)->toBeTrue();

    // A rectificative corrects the SAME operation: if the original was
    // reverse-charge, the rectificative declares it too. Omitting the flag
    // silently misdeclares the operation to the AEAT.
    $rect = $this->settle->rectify($original);

    expect($rect->is_roi_taxed)->toBeTrue();
});

it('refuses to rectify an annulled original — annulment and rectification are mutually exclusive', function () {
    $original = makeSettlementSourceInvoice();

    $this->settle->annul($original);

    // An annulled document was declared never to have existed
    // (ADR-014 RegistroAnulacion): it cannot be corrected.
    $this->settle->rectify($original->fresh());
})->throws(InvoiceNotRectifiableException::class);

it('freezes the recipient from the ORIGINAL snapshot even when the fiscal profile changed', function () {
    $owner = TestUser::factory()->create();

    UserTaxProfile::factory()->forOwner($owner->id)->create([
        'fiscal_name' => 'Cliente Original S.L.',
        'tax_id'      => 'ESB12345678',
    ]);

    $original = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $owner->id,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [],
    ], ['make_immutable' => true]);

    $originalProfileId = $original->user_tax_profile_id;
    expect($originalProfileId)->not->toBeNull();

    // The fiscal identity changes after emission: a new active profile for
    // the same owner with a different fiscal name and NIF.
    UserTaxProfile::factory()->forOwner($owner->id)->create([
        'fiscal_name' => 'Cliente Nuevo S.L.',
        'tax_id'      => 'ESB87654321',
    ]);

    $rect = $this->settle->rectify($original);

    // R1 corrects the operation with the ORIGINAL recipient: the profile
    // reference and the encrypted snapshot are frozen from the original.
    expect($rect->user_tax_profile_id)->toBe($originalProfileId);

    $snapshot = json_decode(Crypt::decryptString($rect->customer_snapshot), true, 512, JSON_THROW_ON_ERROR);

    expect($snapshot['fiscal_name'])->toBe('Cliente Original S.L.')
        ->and($snapshot['tax_id'])->toBe('ESB12345678');
});

it('rectifies an original emitted without any tax profile', function () {
    // $this->customer has no UserTaxProfile at all.
    $original = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $this->customer->id,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [],
    ], ['make_immutable' => true]);

    expect($original->user_tax_profile_id)->toBeNull();

    $rect = $this->settle->rectify($original);

    expect($rect->user_tax_profile_id)->toBeNull()
        ->and($rect->customer_snapshot)->not->toBeNull();
});

it('refuses non-negative rectification amounts', function () {
    $original = makeSettlementSourceInvoice();

    $this->settle->rectify($original, 0);
})->throws(InvalidRectificationAmountException::class);

it('refuses to guess a rate when the original is multi-rate and the amount is explicit', function () {
    $group21  = TaxGroup::factory()->create(['name' => 'IVA 21']);
    $rate21   = TaxRate::factory()->create(['name' => 'IVA 21%', 'rate' => 2100]);
    $group21->taxRates()->attach($rate21->id);
    $group10  = TaxGroup::factory()->create(['name' => 'IVA 10']);
    $rate10   = TaxRate::factory()->create(['name' => 'IVA 10%', 'rate' => 1000]);
    $group10->taxRates()->attach($rate10->id);

    $article21 = Article::factory()->create(['tax_group_id' => $group21->id]);
    $article10 = Article::factory()->create(['tax_group_id' => $group10->id]);

    $original = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => TestUser::factory()->create()->id,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            ['article_id' => $article21->id, 'quantity' => 100, 'base_price' => 5000, 'description' => 'Parte A'],
            ['article_id' => $article10->id, 'quantity' => 100, 'base_price' => 5000, 'description' => 'Parte B'],
        ],
    ], ['make_immutable' => true]);

    // An explicit difference on a multi-rate original has no defensible rate
    // distribution: larabill does not invent it (AID-589 doctrine).
    $this->settle->rectify($original, -5000);
})->throws(UnresolvableRectificationRateException::class);

it('allows the total rectification of a multi-rate original — lines are cloned per rate', function () {
    $group21  = TaxGroup::factory()->create(['name' => 'IVA 21']);
    $rate21   = TaxRate::factory()->create(['name' => 'IVA 21%', 'rate' => 2100]);
    $group21->taxRates()->attach($rate21->id);
    $group10  = TaxGroup::factory()->create(['name' => 'IVA 10']);
    $rate10   = TaxRate::factory()->create(['name' => 'IVA 10%', 'rate' => 1000]);
    $group10->taxRates()->attach($rate10->id);

    $article21 = Article::factory()->create(['tax_group_id' => $group21->id]);
    $article10 = Article::factory()->create(['tax_group_id' => $group10->id]);

    $original = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => TestUser::factory()->create()->id,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            ['article_id' => $article21->id, 'quantity' => 100, 'base_price' => 5000, 'description' => 'Parte A'],
            ['article_id' => $article10->id, 'quantity' => 100, 'base_price' => 5000, 'description' => 'Parte B'],
        ],
    ], ['make_immutable' => true]);

    $rect = $this->settle->rectify($original);

    expect($rect->taxable_amount->unscaledValue())->toBe(-10000)
        ->and($rect->total_tax_amount->unscaledValue())->toBe(-1550)
        ->and($rect->total_amount->unscaledValue())->toBe(-11550)
        ->and($rect->items)->toHaveCount(2);
});
