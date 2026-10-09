<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvoiceNotAnnullableException;
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
 * AID-971 (ADR-014) — SettlementService contract: annulment.
 *
 * An annulment marks an emitted invoice CANCELLED — the fiscal artefact is
 * `RegistroAnulacion`, not a new document. The AEAT registration reason
 * (duplicate, mistaken issue, …) travels with the consumer's lara-verifactu
 * registration call (RegistryManager::createCancellation, one per invoice —
 * AID-726); larabill never registers it and never creates a new Invoice.
 *
 * Boundary (ADR-014 §2): annulment is the one artifact with NO document —
 * "the invoice should never have existed". An invoice carrying issued
 * rectificatives contradicts exactly that, so it is refused loud.
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
function makeSettlementAnnulmentSourceInvoice(): Invoice
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

it('marks the original cancelled and creates no new invoice', function () {
    $original = makeSettlementAnnulmentSourceInvoice();
    $before   = Invoice::count();

    $this->settle->annul($original);

    expect($original->fresh()->status)->toBe(InvoiceStatus::CANCELLED)
        ->and(Invoice::count())->toBe($before);
});

it('leaves the fiscal content untouched after annulment', function () {
    $original = makeSettlementAnnulmentSourceInvoice();

    $before = [
        'total_amount'      => $original->total_amount->unscaledValue(),
        'taxable_amount'    => $original->taxable_amount->unscaledValue(),
        'issuer_snapshot'   => $original->issuer_snapshot,
        'customer_snapshot' => $original->customer_snapshot,
        'fiscal_snapshot'   => $original->fiscal_snapshot,
        'paid_at'           => $original->paid_at?->toIso8601String(),
    ];

    $this->settle->annul($original);

    $after = $original->fresh();

    expect($after->total_amount->unscaledValue())->toBe($before['total_amount'])
        ->and($after->taxable_amount->unscaledValue())->toBe($before['taxable_amount'])
        ->and($after->issuer_snapshot)->toBe($before['issuer_snapshot'])
        ->and($after->customer_snapshot)->toBe($before['customer_snapshot'])
        ->and($after->fiscal_snapshot)->toBe($before['fiscal_snapshot'])
        ->and($after->paid_at?->toIso8601String())->toBe($before['paid_at'])
        ->and($after->is_immutable)->toBeTrue();
});

it('refuses a second annulment of an already cancelled invoice', function () {
    $original = makeSettlementAnnulmentSourceInvoice();

    $this->settle->annul($original);
    $this->settle->annul($original->fresh());
})->throws(InvoiceNotAnnullableException::class);

it('refuses to annul a draft — it was never declared', function () {
    $draft = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
    ]);

    $this->settle->annul($draft);
})->throws(InvoiceNotAnnullableException::class);

it('refuses to annul a proforma — it is not a fiscal document', function () {
    $proforma = app(InvoiceService::class)->createProforma([
        'billable_user_id' => $this->customer->id,
        'items'            => [],
    ]);

    $this->settle->annul($proforma);
})->throws(InvoiceNotAnnullableException::class);

it('refuses to annul an invoice that carries issued rectificatives', function () {
    $original = makeSettlementAnnulmentSourceInvoice();

    $this->settle->rectify($original);

    $this->settle->annul($original);
})->throws(InvoiceNotAnnullableException::class);
