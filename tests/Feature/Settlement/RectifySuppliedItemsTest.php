<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\CreditMovementKind;
use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Exceptions\InvalidRectificationAmountException;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CreditMovement;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\AdvanceInvoiceBuilder;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * AID-1778 spec §5.1 (G10) / §8.3 R18 — `SettlementService::rectify()` with
 * the additive optional `$items` parameter: the supplied frozen lines are
 * used VERBATIM (bypassing clonedVerbatimItems()/differenceItems()), every
 * G10 validation runs BEFORE any write, and `$amount` must be null when
 * `$items` is supplied. An items-less call stays byte-for-byte 8.3.0 (the
 * existing SettlementService suites are the regression set, §8.4).
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $this->settle   = app(SettlementService::class);
    $this->builder  = app(AdvanceInvoiceBuilder::class);
    $this->customer = TestUser::factory()->create();

    $this->group = TaxGroup::factory()->create(['name' => 'IVA General 21%']);
    $this->rate  = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
    $this->group->taxRates()->attach($this->rate->id);

    // The advance: the §4-builder shape — an unnumbered DRAFT carrying the
    // frozen per-rate breakdown (82,64 + 17,36 on 100,00 € @ 21 %). Under
    // the 9.0 link contract (spec §5.6, L9/L14) it is linked as a DRAFT and
    // issued afterwards; an ISSUED advance can no longer be linked.
    $this->advance = app(InvoiceService::class)->createInvoice([
        'billable_user_id' => (string) $this->customer->id,
        'type'             => 'invoice',
        'series'           => 'FAC',
        'status'           => InvoiceStatus::DRAFT->value,
        'items'            => [[
            'description'        => 'Advance collected',
            'quantity'           => 100,
            'base_price'         => 8264,
            'taxable_amount'     => 8264,
            'total_tax_amount'   => 1736,
            'taxes_applied'      => [[
                'source_rate_id' => $this->rate->id,
                'name'           => $this->rate->name,
                'rate'           => 2100,
                'amount'         => 1736,
            ]],
            'total_amount'       => 10000,
        ]],
    ]);

    $entry = CreditMovement::factory()->create([
        'billable_user_id'   => (string) $this->customer->id,
        'currency'           => 'EUR',
        'amount'             => cents(10000),
    ]);
    $entry->linkAdvanceInvoice($this->advance);
    $this->advance = app(InvoiceService::class)->issue($this->advance);

    // issue() leaves the collection axis DRAFT by design — the consumer moves
    // it as today (Invoice::isDraft()). rectify() below reads the issued,
    // in-collection shape the consumer reaches right after issuance.
    $this->advance->status = InvoiceStatus::SENT;
    $this->advance->save();
    $this->advance->refresh();

    $exit = CreditMovement::factory()->create([
        'billable_user_id'     => (string) $this->customer->id,
        'currency'             => 'EUR',
        'kind'                 => CreditMovementKind::REFUNDED->value,
        'amount'               => cents(-3000),
        'grouped_payment_id'   => $entry->grouped_payment_id,
        'consumes_movement_id' => $entry->id,
        'band_start'           => 0,
    ]);

    $this->lines = $this->builder->rectificativeLines($exit);
    $this->exit  = $exit;
});

it('issues an immutable R1 from the supplied frozen lines, verbatim (R18)', function () {
    $rectificative = $this->settle->rectify($this->advance, null, null, $this->lines);

    expect($rectificative->rectifies_invoice_id)->toBe((string) $this->advance->id)
        ->and($rectificative->status)->toBe(InvoiceStatus::SENT)
        ->and($rectificative->is_immutable)->toBeTrue()
        ->and($rectificative->items)->toHaveCount(1);

    $line = $rectificative->items->sole();

    // The supplied line travels VERBATIM: band [0,3000) → base -2479,
    // tax -521, total -3000, the advance's frozen rate negated.
    expect($line->taxable_amount->unscaledValue())->toBe(-2479)
        ->and($line->total_tax_amount->unscaledValue())->toBe(-521)
        ->and($line->total_amount->unscaledValue())->toBe(-3000)
        ->and($line->taxes_applied)->toBe([[
            'source_rate_id' => $this->rate->id,
            'name'           => $this->rate->name,
            'rate'           => 2100,
            'amount'         => -521,
        ]])
        ->and($rectificative->total_amount->unscaledValue())->toBe(-3000)
        ->and($rectificative->total_tax_amount->unscaledValue())->toBe(-521)
        ->and($rectificative->taxable_amount->unscaledValue())->toBe(-2479);
});

it('refuses items supplied together with an explicit amount (R18)', function () {
    expect(fn () => $this->settle->rectify($this->advance, -1000, null, $this->lines))
        ->toThrow(InvalidRectificationAmountException::class);

    // Zero writes: no rectificative exists.
    expect(Invoice::query()->where('rectifies_invoice_id', $this->advance->id)->count())->toBe(0);
});

it('refuses an item whose total_amount is not strictly negative (R18)', function () {
    $lines                    = $this->lines;
    $lines[0]['total_amount'] = 3000;

    expect(fn () => $this->settle->rectify($this->advance, null, null, $lines))
        ->toThrow(InvalidRectificationAmountException::class);
});

it('refuses an item naming a source_rate_id absent from the original breakdown (R18)', function () {
    $otherRate                                      = TaxRate::factory()->create(['name' => 'IVA 10%', 'rate' => 1000]);
    $lines                                          = $this->lines;
    $lines[0]['taxes_applied'][0]['source_rate_id'] = $otherRate->id;

    expect(fn () => $this->settle->rectify($this->advance, null, null, $lines))
        ->toThrow(InvalidRectificationAmountException::class);
});

it('refuses an item whose taxes_applied do not sum to its total_tax_amount (R18)', function () {
    $lines                                  = $this->lines;
    $lines[0]['taxes_applied'][0]['amount'] = -500;

    expect(fn () => $this->settle->rectify($this->advance, null, null, $lines))
        ->toThrow(InvalidRectificationAmountException::class);
});

it('refuses an item breaking the larabill line identity taxable + tax = total (R18, G10)', function () {
    $lines                        = $this->lines;
    $lines[0]['total_tax_amount'] = -999;

    expect(fn () => $this->settle->rectify($this->advance, null, null, $lines))
        ->toThrow(InvalidRectificationAmountException::class);
});

it('refuses an empty items list loudly (R18, G10)', function () {
    expect(fn () => $this->settle->rectify($this->advance, null, null, []))
        ->toThrow(InvalidRectificationAmountException::class);
});

it('refuses a non-integer base-100 amount instead of truncating it (impl gate round 1)', function (string $path) {
    $lines = $this->lines;

    // Subtracting 0.4 keeps every integer cast identical ((int) truncates
    // toward zero), so the line identity and the tax sum still hold after a
    // cast: only a strict integer check can catch the fractional cent.
    if ($path === 'taxes_applied.amount') {
        $lines[0]['taxes_applied'][0]['amount'] -= 0.4;
    } else {
        $lines[0][$path] -= 0.4;
    }

    expect(fn () => $this->settle->rectify($this->advance, null, null, $lines))
        ->toThrow(InvalidRectificationAmountException::class);

    // Zero writes: no rectificative exists.
    expect(Invoice::query()->where('rectifies_invoice_id', $this->advance->id)->count())->toBe(0);
})->with(['taxable_amount', 'total_tax_amount', 'total_amount', 'taxes_applied.amount']);
