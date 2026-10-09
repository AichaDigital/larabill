<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceSerieType;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvalidSettlementAmountException;
use AichaDigital\Larabill\Exceptions\ReverseChargeWithTaxException;
use AichaDigital\Larabill\Exceptions\UnsettleableInvoiceException;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-971 (ADR-014) — SettlementService contract: settlement of unconsumed
 * value into a NEW ordinary invoice carrying a credit line.
 *
 * ADR-014 eje 1, artifact "factura ordinaria con línea de abono": an
 * operation NEW that liquidates what was not consumed of a previous contract.
 * The discriminant is `rectifies_invoice_id` — here always NULL, which is
 * what makes VerifactuAdapter declare TipoFactura F1 even though the credit
 * line is negative. ADR-014 §3 fiscal rule: the credit line is taxed in the
 * EMISSION of the invoice containing it, with ITS OWN fiscal context (its
 * date, its rate, its regime) — never the frozen context of the invoice
 * being settled. ADR-014 §5 provenance rule: without a FK, the trace of what
 * is being liquidated lives in the credit line's `metadata`.
 *
 * Boundary (ADR-014 §2): larabill issues the fiscally correct document; the
 * consumer decides the artifact, the amount policy and the channel.
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
 *
 * Helper name UNIQUE across the suite (AID-502): file-level Pest helpers
 * share the global namespace of a combined run and a duplicate declaration
 * fatals the whole run.
 */
function makeSettlementCreditSourceInvoice(): Invoice
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
function makeSettlementCreditRoiSourceInvoice(): Invoice
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

/**
 * Emit a sealed SENT invoice with TWO lines whose articles resolve to two
 * DIFFERENT current tax groups (21% and 10%). Returns the invoice; the line
 * order is [21% article, 10% article].
 */
function makeSettlementCreditMultiGroupSourceInvoice(): Invoice
{
    $group21 = TaxGroup::factory()->create(['name' => 'IVA 21']);
    $rate21  = TaxRate::factory()->create(['name' => 'IVA 21%', 'rate' => 2100]);
    $group21->taxRates()->attach($rate21->id);
    $group10 = TaxGroup::factory()->create(['name' => 'IVA 10']);
    $rate10  = TaxRate::factory()->create(['name' => 'IVA 10%', 'rate' => 1000]);
    $group10->taxRates()->attach($rate10->id);

    $article21 = Article::factory()->create(['tax_group_id' => $group21->id]);
    $article10 = Article::factory()->create(['tax_group_id' => $group10->id]);

    return app(InvoiceService::class)->createInvoice([
        'billable_user_id' => TestUser::factory()->create()->id,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            ['article_id' => $article21->id, 'quantity' => 100, 'base_price' => 5000, 'description' => 'Parte A'],
            ['article_id' => $article10->id, 'quantity' => 100, 'base_price' => 5000, 'description' => 'Parte B'],
        ],
    ], ['make_immutable' => true]);
}

/**
 * Emit a sealed SENT invoice with TWO lines whose articles resolve to the
 * SAME current tax group (21%).
 */
function makeSettlementCreditSameGroupSourceInvoice(): Invoice
{
    $group21 = TaxGroup::factory()->create(['name' => 'IVA 21']);
    $rate21  = TaxRate::factory()->create(['name' => 'IVA 21%', 'rate' => 2100]);
    $group21->taxRates()->attach($rate21->id);

    $articleA = Article::factory()->create(['tax_group_id' => $group21->id]);
    $articleB = Article::factory()->create(['tax_group_id' => $group21->id]);

    return app(InvoiceService::class)->createInvoice([
        'billable_user_id' => TestUser::factory()->create()->id,
        'status'           => InvoiceStatus::SENT->value,
        'items'            => [
            ['article_id' => $articleA->id, 'quantity' => 100, 'base_price' => 5000, 'description' => 'Parte A'],
            ['article_id' => $articleB->id, 'quantity' => 100, 'base_price' => 5000, 'description' => 'Parte B'],
        ],
    ], ['make_immutable' => true]);
}

it('settles into a new ordinary invoice in its own FAC series, born sealed, without rectifies_invoice_id', function () {
    $original = makeSettlementCreditSourceInvoice();

    $settlement = $this->settle->settleUnconsumed($original, 5000);

    // Artifact discriminant (ADR-014 eje 1): a NEW ordinary invoice — serie
    // INVOICE, prefix from the config series for 'invoice', own correlative
    // numbering, and rectifies_invoice_id NEVER set. Pinned explicitly: the
    // absence of the FK is what makes VerifactuAdapter map this to F1 even
    // though the credit line is negative.
    expect($settlement->rectifies_invoice_id)->toBeNull()
        ->and($settlement->serie)->toBe(InvoiceSerieType::INVOICE)
        ->and($settlement->prefix)->toBe('FAC')
        // Own correlative: the fresh issuer's FAC#1 is the original itself,
        // so the settlement takes the next number of the same series.
        ->and($settlement->series_number)->toBe(2)
        ->and($settlement->fiscal_year)->toBe((int) now()->format('Y'));

    // Born sealed, exactly like the recurring engine's emissions (AID-836).
    expect($settlement->status)->toBe(InvoiceStatus::SENT)
        ->and($settlement->is_immutable)->toBeTrue()
        ->and($settlement->issuer_snapshot)->not->toBeNull()
        ->and($settlement->customer_snapshot)->not->toBeNull()
        ->and($settlement->fiscal_snapshot)->not->toBeNull();

    // The settlement bills the ORIGINAL's billable user.
    expect($settlement->billable_user_id)->toBe($original->billable_user_id);
});

it('taxes the credit line with the CURRENT context at emission, not the frozen original', function () {
    $original = makeSettlementCreditSourceInvoice();

    // The catalogue moves after emission (AID-956 doctrine): the live group
    // now says 10%. The credit line must bill the CURRENT rate — the context
    // of the invoice that carries it (ADR-014 §3) — not the frozen 21%.
    $newGroup = TaxGroup::factory()->create(['name' => 'IVA Reducido']);
    $newRate  = TaxRate::factory()->create(['name' => 'IVA Reducido 10%', 'rate' => 1000]);
    $newGroup->taxRates()->attach($newRate->id);
    Article::whereKey($original->items->first()->article_id)->update(['tax_group_id' => $newGroup->id]);

    $settlement = $this->settle->settleUnconsumed($original, 5000);

    $line = $settlement->items->first();
    expect($line->quantity->unscaledValue())->toBe(100)
        ->and($line->unit_price->unscaledValue())->toBe(-5000)
        ->and($line->taxable_amount->unscaledValue())->toBe(-5000)
        ->and($line->total_tax_amount->unscaledValue())->toBe(-500)
        ->and($line->total_amount->unscaledValue())->toBe(-5500)
        ->and($line->taxes_applied[0]['rate'])->toBe(1000)
        ->and($settlement->taxable_amount->unscaledValue())->toBe(-5000)
        ->and($settlement->total_tax_amount->unscaledValue())->toBe(-500)
        ->and($settlement->total_amount->unscaledValue())->toBe(-5500);

    // Provenance lives in the LINE's metadata (ADR-014 §5): the new invoice
    // has no FK, so the trace of what is liquidated travels with the line.
    expect($line->metadata['settles_invoice_id'])->toBe($original->id)
        ->and($line->metadata['settles_fiscal_number'])->toBe($original->fiscal_number)
        ->and($line->description)
        ->toBe("Credit for unconsumed service of invoice {$original->fiscal_number}");
});

it('appends additional items after the credit line, taxed on the normal path', function () {
    $original = makeSettlementCreditSourceInvoice();

    // Cambio de plan (ADR-014 §4): abono of the unconsumed value plus the new
    // plan's charge on the same document. The charge line resolves its tax
    // group from ITS OWN article, on the normal live path.
    $otherGroup = TaxGroup::factory()->create(['name' => 'IVA Nuevo Plan']);
    $otherRate  = TaxRate::factory()->create(['name' => 'IVA Nuevo Plan 21%', 'rate' => 2100]);
    $otherGroup->taxRates()->attach($otherRate->id);
    $otherArticle = Article::factory()->create(['tax_group_id' => $otherGroup->id]);

    $settlement = $this->settle->settleUnconsumed($original, 5000, [
        [
            'article_id'  => $otherArticle->id,
            'quantity'    => 100,
            'base_price'  => 20000,
            'description' => 'New plan charge',
        ],
    ]);

    expect($settlement->items)->toHaveCount(2);

    $credit = $settlement->items->first();
    $charge = $settlement->items->last();

    // The credit line is ALWAYS first; the charge follows, positive and taxed
    // with its article's current group.
    expect($credit->taxable_amount->unscaledValue())->toBe(-5000)
        ->and($credit->total_tax_amount->unscaledValue())->toBe(-1050)
        ->and($charge->description)->toBe('New plan charge')
        ->and($charge->taxable_amount->unscaledValue())->toBe(20000)
        ->and($charge->total_tax_amount->unscaledValue())->toBe(4200)
        ->and($charge->total_amount->unscaledValue())->toBe(24200);

    // Invoice totals are the sum of both lines.
    expect($settlement->taxable_amount->unscaledValue())->toBe(15000)
        ->and($settlement->total_tax_amount->unscaledValue())->toBe(3150)
        ->and($settlement->total_amount->unscaledValue())->toBe(18150);
});

it('refuses to settle a draft — nothing was ever emitted', function () {
    $draft = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
    ]);

    $this->settle->settleUnconsumed($draft, 5000);
})->throws(UnsettleableInvoiceException::class);

it('refuses to settle a proforma — it is not a fiscal document', function () {
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
    ]);

    $this->settle->settleUnconsumed($proforma, 5000);
})->throws(UnsettleableInvoiceException::class);

it('refuses to settle an annulled invoice — it never existed, nothing to liquidate', function () {
    $original = makeSettlementCreditSourceInvoice();

    $this->settle->annul($original);

    $this->settle->settleUnconsumed($original->fresh(), 5000);
})->throws(UnsettleableInvoiceException::class);

it('refuses a zero settlement amount — zero is the "ninguno" artifact, not a document', function () {
    $original = makeSettlementCreditSourceInvoice();

    $this->settle->settleUnconsumed($original, 0);
})->throws(InvalidSettlementAmountException::class);

it('refuses a negative settlement amount — negatives are a rectificative concern', function () {
    $original = makeSettlementCreditSourceInvoice();

    $this->settle->settleUnconsumed($original, -5000);
})->throws(InvalidSettlementAmountException::class);

it('declares is_roi_taxed on the settlement when the receptor is now reverse-charge', function () {
    $original = makeSettlementCreditRoiSourceInvoice();

    // The receptor is NOW intra-community reverse-charge (EU NIF-IVA): the
    // new invoice must declare it (line at zero tax). The credit line bills
    // the CURRENT context, not the original's frozen one.
    $settlement = $this->settle->settleUnconsumed($original, 5000, [], true);

    expect($settlement->is_roi_taxed)->toBeTrue()
        ->and($settlement->total_tax_amount->unscaledValue())->toBe(0);
});

it('refuses an roi settlement whose credit line carries real tax (AID-929)', function () {
    // Taxed original: the credit line would resolve a real 21% rate.
    $original = makeSettlementCreditSourceInvoice();

    $this->settle->settleUnconsumed($original, 5000, [], true);
})->throws(ReverseChargeWithTaxException::class);

it('refuses to guess the credit article when the original spans two current tax groups', function () {
    $original = makeSettlementCreditMultiGroupSourceInvoice();

    // The old first-line heuristic silently attributed the credit line to the
    // FIRST item's article. With two distinct current groups, attributing the
    // whole abono to one of them is an invented distribution (AID-589).
    $this->settle->settleUnconsumed($original, 5000);
})->throws(UnsettleableInvoiceException::class);

it('taxes the credit line at the explicitly chosen article\'s current group rate', function () {
    $original = makeSettlementCreditMultiGroupSourceInvoice();

    $creditArticleId = (int) $original->items->last()->article_id; // the 10% article

    $settlement = $this->settle->settleUnconsumed($original, 5000, [], false, $creditArticleId);

    $line = $settlement->items->first();
    expect($line->article_id)->toBe($creditArticleId)
        ->and($line->taxable_amount->unscaledValue())->toBe(-5000)
        ->and($line->total_tax_amount->unscaledValue())->toBe(-500)
        ->and($line->taxes_applied[0]['rate'])->toBe(1000);
});

it('refuses a credit article that is not among the original\'s lines', function () {
    $original = makeSettlementCreditSourceInvoice();

    $unrelated = Article::factory()->create();

    $this->settle->settleUnconsumed($original, 5000, [], false, $unrelated->id);
})->throws(UnsettleableInvoiceException::class);

it('still settles without an explicit article when all original lines share one tax group', function () {
    $original = makeSettlementCreditSameGroupSourceInvoice();

    $settlement = $this->settle->settleUnconsumed($original, 5000);

    $line = $settlement->items->first();
    expect($line->taxable_amount->unscaledValue())->toBe(-5000)
        ->and($line->total_tax_amount->unscaledValue())->toBe(-1050)
        ->and($line->taxes_applied[0]['rate'])->toBe(2100);
});

it('liquidates an arbitrary consumer amount with no derivation', function () {
    $original = makeSettlementCreditSourceInvoice(); // €100.00 base + 21% VAT

    // ADR-014 eje 2, T5: the consumer's amount flows through UNCHANGED.
    // 7777 is deliberately unrelated to any original line amount (the
    // original billed a single 10000-base line) — the settlement must not
    // derive, prorate or redistribute it against the original. Only the
    // tax is computed, from the live rate of this emission (ADR-014 §3).
    // The proportional derivation itself is the consumer's job, via the
    // pure SettlementArithmetic calculator.
    $settlement = $this->settle->settleUnconsumed($original, 7777);

    $line = $settlement->items->first();
    expect($line->taxable_amount->unscaledValue())->toBe(-7777)
        ->and($line->unit_price->unscaledValue())->toBe(-7777)
        ->and($line->taxes_applied[0]['rate'])->toBe(2100)
        // 7777 × 21% = 1633.17 → 1633, half-up on the live rate only.
        ->and($line->total_tax_amount->unscaledValue())->toBe(-1633)
        ->and($line->total_amount->unscaledValue())->toBe(-9410)
        ->and($settlement->taxable_amount->unscaledValue())->toBe(-7777)
        ->and($settlement->total_tax_amount->unscaledValue())->toBe(-1633)
        ->and($settlement->total_amount->unscaledValue())->toBe(-9410);
});
