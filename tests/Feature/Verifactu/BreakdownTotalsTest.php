<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\InvoiceStatus;
use AichaDigital\Larabill\Models\Article;
use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\Adapters\VerifactuAdapter;
use AichaDigital\Larabill\Services\InvoiceService;
use AichaDigital\Larabill\Tests\Models\TestUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * AID-2065 (spec docs/specs/aid-2065-verifactu-breakdown.md): the VeriFactu
 * breakdown must add up to the invoice header.
 *
 * D1: a net negative untaxed base is kept as an exempt row, never dropped.
 * D2: a line with more than one tax entry is refused, never declared with its
 * base repeated once per entry.
 */
beforeEach(function () {
    CompanyFiscalConfig::factory()->create(['is_active' => true, 'valid_until' => null]);

    $this->customer = TestUser::factory()->create();
    $this->vat      = TaxRate::factory()->create(['name' => 'IVA General 21%', 'rate' => 2100]);
});

/**
 * One fiscal invoice from frozen lines (persisted verbatim).
 *
 * @param  array<int, array<string, mixed>>  $lines
 */
function aid2065Invoice(string $customerId, array $lines): Invoice
{
    return app(InvoiceService::class)->createInvoice([
        'billable_user_id' => $customerId,
        'type'             => 'invoice',
        'series'           => 'FAC',
        'status'           => InvoiceStatus::SENT->value,
        'items'            => $lines,
    ], ['make_immutable' => true]);
}

/** @return array<string, mixed> */
function aid2065TaxedLine(int $rateId, int $base, int $rate, int $tax): array
{
    return [
        'description'      => "Taxed {$base}",
        'quantity'         => 100,
        'base_price'       => $base,
        'taxable_amount'   => $base,
        'total_tax_amount' => $tax,
        'taxes_applied'    => [[
            'source_rate_id' => $rateId,
            'name'           => 'IVA',
            'rate'           => $rate,
            'amount'         => $tax,
        ]],
        'total_amount' => $base + $tax,
    ];
}

/** @return array<string, mixed> */
function aid2065UntaxedLine(int $base): array
{
    return [
        'description'      => "Untaxed {$base}",
        'quantity'         => 100,
        'base_price'       => $base,
        'taxable_amount'   => $base,
        'total_tax_amount' => 0,
        'taxes_applied'    => [],
        'total_amount'     => $base,
    ];
}

/**
 * Sums of the breakdown in cents, compared against the header.
 *
 * @param  array<int, array<string, mixed>>  $breakdowns
 * @return array{base: int, tax: int}
 */
function aid2065Sums(array $breakdowns): array
{
    $base = 0;
    $tax  = 0;

    foreach ($breakdowns as $row) {
        $base += (int) round($row['base_amount'] * 100);

        if ($row['exempt'] === false) {
            $tax += (int) round($row['tax_amount'] * 100);
        }
    }

    return ['base' => $base, 'tax' => $tax];
}

it('keeps a negative untaxed base in the VeriFactu breakdown (T1, D1)', function () {
    $invoice = aid2065Invoice((string) $this->customer->id, [
        aid2065TaxedLine($this->vat->id, 10000, 2100, 2100),
        aid2065UntaxedLine(-1000),
    ]);

    $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);
    $sums       = aid2065Sums($breakdowns);

    expect($sums['base'])->toBe($invoice->taxable_amount->unscaledValue())
        ->and($sums['base'])->toBe(9000)
        ->and($sums['tax'])->toBe($invoice->total_tax_amount->unscaledValue())
        ->and($sums['tax'])->toBe(2100);

    $exempt = array_values(array_filter($breakdowns, fn (array $row): bool => $row['exempt']));

    expect($exempt)->toHaveCount(1)
        ->and($exempt[0]['base_amount'])->toBe(-10.0)
        ->and($exempt[0]['tax_amount'])->toBe(0.0);
});

/** The D2 refusal, asserted on its error bag key, not only on its class. */
function aid2065ExpectRefusal(Invoice $invoice): void
{
    try {
        VerifactuAdapter::toVerifactuBreakdowns($invoice);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('verifactu')
            ->and($e->errors()['verifactu'][0])->toContain('more than one tax entry');

        return;
    }

    test()->fail('Expected the D2 refusal (ValidationException on key verifactu).');
}

it('refuses a frozen line with several tax entries instead of repeating its base (T2, D2)', function (array $extraRates, int $base) {
    $tax     = intdiv($base * 2100, 10000);
    $entries = [['source_rate_id' => $this->vat->id, 'name' => 'IVA', 'rate' => 2100, 'amount' => $tax]];

    foreach ($extraRates as $rate) {
        $extra     = TaxRate::factory()->create(['name' => "Extra {$rate}", 'rate' => $rate]);
        $amount    = intdiv($base * $rate, 10000);
        $tax      += $amount;
        $entries[] = ['source_rate_id' => $extra->id, 'name' => "Extra {$rate}", 'rate' => $rate, 'amount' => $amount];
    }

    $invoice = aid2065Invoice((string) $this->customer->id, [[
        'description'      => 'Multi-tax line',
        'quantity'         => 100,
        'base_price'       => $base,
        'taxable_amount'   => $base,
        'total_tax_amount' => $tax,
        'taxes_applied'    => $entries,
        'total_amount'     => $base + $tax,
    ]]);

    aid2065ExpectRefusal($invoice);
})->with([
    'two entries (VAT + RE 5.2%)'          => [[520], 11000],
    'three entries (VAT + 2 more)'         => [[520, 140], 11000],
    'zero base and zero tax (spec §4 D2)'  => [[1000], 0],
]);

it('refuses a calculated line from a two-rate tax group (T3, D2 reachability)', function () {
    $surcharge = TaxRate::factory()->create(['name' => 'Recargo 5,2%', 'rate' => 520]);
    $group     = TaxGroup::factory()->create(['name' => 'IVA + RE']);
    $group->taxRates()->attach($this->vat->id, ['priority' => 1]);
    $group->taxRates()->attach($surcharge->id, ['priority' => 2]);
    $article = Article::factory()->create(['tax_group_id' => $group->id]);

    $invoice = aid2065Invoice((string) $this->customer->id, [[
        'article_id'   => $article->id,
        'tax_group_id' => $group->id,
        'quantity'     => 100,
        'base_price'   => 11000,
        'description'  => 'Calculated two-rate line',
    ]]);

    // The calculated path produced two entries: the shape is reachable (spec §2).
    expect($invoice->items()->first()->taxes_applied)->toHaveCount(2);

    aid2065ExpectRefusal($invoice);
});

it('emits no exempt row when the untaxed lines net to zero (T4)', function () {
    $invoice = aid2065Invoice((string) $this->customer->id, [
        aid2065TaxedLine($this->vat->id, 10000, 2100, 2100),
        aid2065UntaxedLine(500),
        aid2065UntaxedLine(-500),
    ]);

    $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);
    $sums       = aid2065Sums($breakdowns);

    expect(array_filter($breakdowns, fn (array $row): bool => $row['exempt']))->toBeEmpty()
        ->and($sums['base'])->toBe($invoice->taxable_amount->unscaledValue())
        ->and($sums['tax'])->toBe($invoice->total_tax_amount->unscaledValue());
});

it('still emits one exempt row for a positive untaxed net (T5)', function () {
    $invoice = aid2065Invoice((string) $this->customer->id, [
        aid2065TaxedLine($this->vat->id, 10000, 2100, 2100),
        aid2065UntaxedLine(2500),
    ]);

    $breakdowns = VerifactuAdapter::toVerifactuBreakdowns($invoice);
    $exempt     = array_values(array_filter($breakdowns, fn (array $row): bool => $row['exempt']));

    expect($exempt)->toHaveCount(1)
        ->and($exempt[0]['base_amount'])->toBe(25.0)
        ->and(aid2065Sums($breakdowns)['base'])->toBe($invoice->taxable_amount->unscaledValue());
});
