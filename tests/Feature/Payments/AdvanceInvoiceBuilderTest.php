<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceDocumentStatus;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\CreditMovementAlreadyLinkedException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\AdvanceInvoiceBuilder;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-1778 spec §8.2 (S2/S3) — the MONEY half of AdvanceInvoiceBuilder::
 * forCreditEntry(): the pinned reverse-base cases (R7/R7b), the G3/G8
 * zero-rate-vs-null-group representations, the unnumbered DRAFT shape (R8),
 * the no-tax-profile divergence (R13) and the O5 duplicate-draft pin (R10b).
 *
 * Refusals live in tests/Unit/Services/AdvanceInvoiceBuilderTest.php.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $this->customer = TestUser::factory()->create();

    $this->entry = CreditMovement::factory()->create([
        'billable_user_id' => (string) $this->customer->id,
        'currency'         => 'EUR',
        'amount'           => cents(10000),
    ]);

    $this->group = TaxGroup::factory()->create(['name' => 'IVA General 21%']);
    $this->rate  = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
    $this->group->taxRates()->attach($this->rate->id);
});

/** A single-rate group with the given base-100 percent rate. */
function aid1778SingleRateGroup(string $name, int $rate): TaxGroup
{
    $group = TaxGroup::factory()->create(['name' => $name]);
    $group->taxRates()->attach(TaxRate::factory()->create(['name' => $name, 'rate' => $rate])->id);

    return $group;
}

it('builds the pinned reverse base: 100,00 € @ 21 % → 82,64 + 17,36 (R7)', function () {
    $draft = app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $this->entry, 'FAC', 'Advance collected', $this->group->id, false,
    );

    $item = $draft->items()->sole();

    expect($item->taxable_amount->unscaledValue())->toBe(8264)
        ->and($item->total_tax_amount->unscaledValue())->toBe(1736)
        ->and($item->total_amount->unscaledValue())->toBe(10000)
        ->and($draft->taxable_amount->unscaledValue())->toBe(8264)
        ->and($draft->total_tax_amount->unscaledValue())->toBe(1736)
        ->and($draft->total_amount->unscaledValue())->toBe(10000)
        ->and($item->taxes_applied[0]['rate'])->toBe(2100)
        ->and($item->taxes_applied[0]['amount'])->toBe(1736);
});

it('builds the one-cent advance: 0,01 € @ 21 % → base 1, tax 0 (R7)', function () {
    $entry = CreditMovement::factory()->create([
        'billable_user_id' => (string) $this->customer->id,
        'currency'         => 'EUR',
        'amount'           => cents(1),
    ]);

    $draft = app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $entry, 'FAC', 'Tiny advance', $this->group->id, false,
    );

    $item = $draft->items()->sole();

    expect($item->taxable_amount->unscaledValue())->toBe(1)
        ->and($item->total_tax_amount->unscaledValue())->toBe(0)
        ->and($item->total_amount->unscaledValue())->toBe(1);
});

it('builds the large advance without float drift: 1.000.000,00 € @ 21 % (R7)', function () {
    $entry = CreditMovement::factory()->create([
        'billable_user_id' => (string) $this->customer->id,
        'currency'         => 'EUR',
        'amount'           => cents(100000000),
    ]);

    $draft = app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $entry, 'FAC', 'Large advance', $this->group->id, false,
    );

    $item = $draft->items()->sole();

    expect($item->taxable_amount->unscaledValue())->toBe(82644628)
        ->and($item->total_tax_amount->unscaledValue())->toBe(17355372)
        ->and($item->total_amount->unscaledValue())->toBe(100000000);
});

it('pins the G12 HalfUp boundary cents of the reverse base (R7b)', function () {
    $down = CreditMovement::factory()->create([
        'billable_user_id' => (string) $this->customer->id,
        'currency'         => 'EUR',
        'amount'           => cents(49),
    ]);
    $up = CreditMovement::factory()->create([
        'billable_user_id' => (string) $this->customer->id,
        'currency'         => 'EUR',
        'amount'           => cents(72),
    ]);

    $build = fn (CreditMovement $movement) => app(AdvanceInvoiceBuilder::class)
        ->forCreditEntry($movement, 'FAC', 'Boundary', $this->group->id, false)
        ->items()->sole();

    // 490000/12100 = 40.4959… rounds DOWN
    expect($build($down)->taxable_amount->unscaledValue())->toBe(40)
        ->and($build($down)->total_tax_amount->unscaledValue())->toBe(9)
        // 720000/12100 = 59.5041… rounds UP
        ->and($build($up)->taxable_amount->unscaledValue())->toBe(60)
        ->and($build($up)->total_tax_amount->unscaledValue())->toBe(12);
});

it('emits the strategy zero entry for an explicit zero-rate group (G3/R7)', function () {
    $zeroGroup = aid1778SingleRateGroup('Exento con rate 0', 0);

    $draft = app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $this->entry, 'FAC', 'Zero-rate advance', $zeroGroup->id, false,
    );

    $item = $draft->items()->sole();

    expect($item->taxable_amount->unscaledValue())->toBe(10000)
        ->and($item->total_tax_amount->unscaledValue())->toBe(0)
        ->and($item->total_amount->unscaledValue())->toBe(10000)
        ->and($item->taxes_applied)->toHaveCount(1)
        ->and($item->taxes_applied[0]['source_rate_id'])->toBe($zeroGroup->taxRates->sole()->id)
        ->and($item->taxes_applied[0]['rate'])->toBe(0)
        ->and($item->taxes_applied[0]['amount'])->toBe(0);
});

it('emits an empty taxes_applied for a null tax group — a different fiscal statement (G8/R7)', function () {
    $draft = app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $this->entry, 'FAC', 'Tax-free advance', null, false,
    );

    $item = $draft->items()->sole();

    expect($item->taxable_amount->unscaledValue())->toBe(10000)
        ->and($item->total_tax_amount->unscaledValue())->toBe(0)
        ->and($item->total_amount->unscaledValue())->toBe(10000)
        ->and($item->taxes_applied)->toBe([]);
});

it('returns an unnumbered DRAFT with one frozen line and no article (R8)', function () {
    $draft = app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $this->entry, 'FAC', 'Advance collected', $this->group->id, false,
    );

    $item = $draft->items()->sole();

    expect($draft->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
        ->and($draft->status)->toBe(InvoiceStatus::DRAFT)
        ->and($draft->fiscal_number)->toBeNull()
        ->and($draft->series_number)->toBeNull()
        ->and($draft->fiscal_year)->toBeNull()
        ->and($draft->invoice_date)->toBeNull()
        ->and($draft->issued_at)->toBeNull()
        ->and($draft->prefix)->toBe('FAC')
        ->and($item->article_id)->toBeNull()
        ->and($item->deducts_invoice_id)->toBeNull()
        ->and($item->description)->toBe('Advance collected')
        ->and($draft->total_amount->unscaledValue())->toBe($this->entry->amount->unscaledValue());
});

it('succeeds without a tax profile: null profile and a resolved customer snapshot (R13)', function () {
    $draft = app(AdvanceInvoiceBuilder::class)->forCreditEntry(
        $this->entry, 'FAC', 'Advance collected', $this->group->id, false,
    );

    expect($draft->user_tax_profile_id)->toBeNull()
        ->and($draft->customer_snapshot)->not->toBeNull();
});

it('runs the build → link → issue sequence: only the linked draft becomes THE advance, and the orphan still issues raw (R25/L9/L12)', function () {
    $builder = app(AdvanceInvoiceBuilder::class);
    $service = app(InvoiceService::class);

    // Two drafts of the SAME entry both exist (O5 duplicates are legal).
    $linked = $builder->forCreditEntry($this->entry, 'FAC', 'Advance linked', $this->group->id, false);
    $orphan = $builder->forCreditEntry($this->entry, 'FAC', 'Advance orphan', $this->group->id, false);

    expect(Invoice::query()->where('billable_user_id', (string) $this->customer->id)->count())->toBe(2);

    // The link happens while both are still unnumbered DRAFTs (L9 sequence);
    // only one of them links — the competing link of the same entry refuses.
    $this->entry->linkAdvanceInvoice($linked->refresh());

    expect(fn () => $this->entry->linkAdvanceInvoice($orphan->refresh()))
        ->toThrow(CreditMovementAlreadyLinkedException::class)
        ->and($orphan->refresh()->invoice_document_status)->toBe(InvoiceDocumentStatus::DRAFT)
        ->and($orphan->refresh()->fiscal_number)->toBeNull();

    // The LINKED draft issues through the documented sequence.
    $issued = $service->issue($linked->refresh());

    expect($issued->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($issued->fiscal_number)->not->toBeNull()
        ->and($this->entry->fresh()->advance_invoice_id)->toBe($linked->id);

    // The ORPHAN draft is then issued by a raw issue() call WITHOUT refusal
    // (operator L12): larabill does NOT enforce «only the linked one can be
    // issued» — that guarantee is the consumer's documented contract; a stray
    // issuance yields a valid, unlinked invoice.
    $orphanIssued = $service->issue($orphan->refresh());

    expect($orphanIssued->invoice_document_status)->toBe(InvoiceDocumentStatus::ISSUED)
        ->and($orphanIssued->fiscal_number)->not->toBeNull()
        ->and($orphanIssued->fiscal_number)->not->toBe($issued->fiscal_number)
        // The ledger is untouched by the orphan's issuance.
        ->and($this->entry->fresh()->advance_invoice_id)->toBe($linked->id);
});

it('mints two independent drafts on two calls for the same entry; only the first link wins (R10b/O5)', function () {
    $builder = app(AdvanceInvoiceBuilder::class);

    $first  = $builder->forCreditEntry($this->entry, 'FAC', 'Attempt 1', $this->group->id, false);
    $second = $builder->forCreditEntry($this->entry, 'FAC', 'Attempt 2', $this->group->id, false);

    expect($first->isNot($second))->toBeTrue()
        ->and(Invoice::query()->where('billable_user_id', (string) $this->customer->id)->count())->toBe(2);

    // Duplicates are legal (O5); the one-to-one link is what stops the second
    // draft from ever becoming THE advance of this entry.
    $this->entry->linkAdvanceInvoice($first->refresh());

    expect(fn () => $this->entry->linkAdvanceInvoice($second->refresh()))
        ->toThrow(CreditMovementAlreadyLinkedException::class)
        ->and($this->entry->refresh()->advance_invoice_id)->toBe($first->id);
});
