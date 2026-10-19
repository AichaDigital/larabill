<?php

declare(strict_types=1);

use AichaDigital\Lara100\Exceptions\InvalidFixedDecimal;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\TaxGroup;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Services\SettlementArithmetic;
use AichaDigital\Larabill\Services\SettlementService;
use AichaDigital\Larabill\Services\TaxCalculation\VatCalculationStrategy;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * AID-1527 §3-bis — arithmetic conformance for the THREE exact-arithmetic
 * sites the widened money domain exposes:
 *
 *   1. VatCalculationStrategy — four ordered branches before any int cast
 *      (non-finite loud / pre-cast 2^53 conservative cutoff / integral exact
 *      BigDecimal path / fractional legacy float expression with a checked
 *      output boundary, exclusive upper bound).
 *   2. SettlementService::differenceItems — the frozen-rate tax expression.
 *   3. SettlementArithmetic::unconsumedBase — the combined rounding form with
 *      arbitrary-precision intermediates, truncated toward zero (Down).
 *
 * The pin set is the one §3-bis/§6* prescribed: each pin kills a specific
 * mutant the gate rounds measured, and the golden master proves old == new
 * for every in-range input class, INCLUDING fractional inputs and across
 * precision ini settings (round-3: the pins alone did not detect the
 * string-formatting defect).
 *
 * No database: TaxGroup/TaxRate and Invoice/InvoiceItem are built in memory.
 */

/**
 * Build an in-memory single-rate tax group for the strategy.
 */
function aid1527VatGroup(int $rate): TaxGroup
{
    $group     = new TaxGroup(['name' => 'IVA']);
    $group->id = 1;

    $taxRate     = new TaxRate(['name' => 'IVA', 'rate' => $rate]);
    $taxRate->id = 7;
    $group->setRelation('taxRates', collect([$taxRate]));

    return $group;
}

/**
 * Run the strategy for one (base, rate) pair and return the tax cents.
 */
function aid1527Vat(float $base, int $rate): int
{
    return (new VatCalculationStrategy)->calculate($base, aid1527VatGroup($rate))['total_tax_amount'];
}

/**
 * The historical float expression — the golden-master oracle. Exactly what
 * VatCalculationStrategy computed before AID-1527.
 */
function aid1527LegacyVat(float $base, int $rate): int
{
    return (int) round($base * ($rate / 10000));
}

/**
 * Invoke the protected SettlementService::differenceItems() on an in-memory
 * invoice carrying the given frozen taxes_applied snapshot (round-2
 * sensitivity probe B: an independent settlement-path conformance, not only
 * the VAT strategy).
 *
 * @param  array<int, array<string, mixed>>  $taxesApplied
 * @return list<array<string, mixed>>
 */
function aid1527DifferenceItems(int $amount, array $taxesApplied): array
{
    $original = new Invoice(['fiscal_number' => 'FAC2026/1']);
    $item     = new InvoiceItem(['taxes_applied' => $taxesApplied]);
    $original->setRelation('items', collect([$item]));

    $service = (new ReflectionClass(SettlementService::class))->newInstanceWithoutConstructor();
    $method  = new ReflectionMethod(SettlementService::class, 'differenceItems');

    return $method->invoke($service, $original, $amount);
}

/**
 * An in-memory EMITTED line with the given billed base and an inclusive
 * service period of $totalDays days starting 2026-01-01.
 */
function aid1527Line(int $baseCents, int $totalDays): InvoiceItem
{
    return new InvoiceItem([
        'taxable_amount'    => FixedDecimal::ofUnscaled($baseCents, 2),
        'service_date_from' => Carbon::parse('2026-01-01'),
        'service_date_to'   => Carbon::parse('2026-01-01')->addDays($totalDays - 1),
    ]);
}

describe('AID-1527 §3-bis site 1 — VatCalculationStrategy exact arithmetic', function () {

    it('computes the round-1 probe exactly: 80000000000007 × 2100 → 16800000000001', function () {
        expect(aid1527Vat(80000000000007, 2100))->toBe(16_800_000_000_001);
    });

    it('carries the round-1 probe into the immutable taxes_applied snapshot', function () {
        $result = (new VatCalculationStrategy)->calculate(80000000000007.0, aid1527VatGroup(2100));

        expect($result['taxes_applied'][0]['amount'])->toBe(16_800_000_000_001);
    });

    it('rounds half-away-from-zero on signed half boundaries: 50→11, -50→-11, 99→21, -99→-21 (mutant killers)', function () {
        expect(aid1527Vat(50, 2100))->toBe(11)
            ->and(aid1527Vat(-50, 2100))->toBe(-11)
            ->and(aid1527Vat(99, 2100))->toBe(21)
            ->and(aid1527Vat(-99, 2100))->toBe(-21);
    });

    it('lands -0.0 on 0 through the exact branch', function () {
        expect(aid1527Vat(-0.0, 2100))->toBe(0);
    });

    it('rejects NAN loudly (the legacy expression wrapped it to zero silently)', function () {
        aid1527Vat(NAN, 2100);
    })->throws(InvalidArgumentException::class);

    it('rejects +INF and -INF loudly', function () {
        aid1527Vat(INF, 2100);
    })->throws(InvalidArgumentException::class);

    it('rejects -INF loudly', function () {
        aid1527Vat(-INF, 2100);
    })->throws(InvalidArgumentException::class);

    it('rejects integral floats at 2^53 and 2^53+2 via the PRE-cast conservative cutoff (round-5: they cast to exact ints)', function () {
        aid1527Vat(2.0 ** 53, 2100);
    })->throws(InvalidArgumentException::class);

    it('rejects 2^53+2 loudly', function () {
        aid1527Vat(2.0 ** 53 + 2, 2100);
    })->throws(InvalidArgumentException::class);

    it('rejects ±2^63 and 2^64 loudly (legacy: wrapped to 0 / PHP_INT_MIN)', function () {
        expect(fn () => aid1527Vat(2.0 ** 63, 2100))->toThrow(InvalidArgumentException::class)
            ->and(fn () => aid1527Vat(-(2.0 ** 63), 2100))->toThrow(InvalidArgumentException::class)
            ->and(fn () => aid1527Vat(2.0 ** 64, 2100))->toThrow(InvalidArgumentException::class);
    });

    it('rejects a fractional in-widened case the legacy expression wrapped to 0: 92233720368547.77 × rate 2e9', function () {
        aid1527Vat(92233720368547.77, 2_000_000_000);
    })->throws(InvalidArgumentException::class);

    it('rejects the NEGATIVE mirror through the LOWER bound: -92233720368547.77 × rate 2e9 (missing-lower mutant: silently returns 0)', function () {
        aid1527Vat(-92233720368547.77, 2_000_000_000);
    })->throws(InvalidArgumentException::class);

    it('rejects an integral exact product beyond PHP_INT_MAX with the typed InvalidFixedDecimal, never a silent PHP_INT_MAX saturation (unchecked-exact mutant)', function () {
        // 99_999_999_999_999 × 200_000 = 19_999_999_999_999_800_000 > PHP_INT_MAX.
        expect(fn () => aid1527Vat(99999999999999.0, 2_000_000_000))->toThrow(InvalidFixedDecimal::class);
    });

    it('keeps the fractional legacy surface and checks the output boundary: rounded result exactly 2^63 → loud (float promotion of PHP_INT_MAX; legacy wrapped it to PHP_INT_MIN)', function () {
        aid1527Vat(4010161755154250.5, 23_000_000);
    })->throws(InvalidArgumentException::class);

    it('accepts the exact 2^63−1024 acceptance pin: 4503599627370495.5 × 20480000 → 9223372036854774784, returned integral (too-strict-upper mutant rejects this valid result)', function () {
        // 4503599627370495.5 = 2^52 − 0.5; × 2048 exactly = 2^63 − 1024, the
        // largest float-representable value the exclusive upper bound admits.
        expect(aid1527Vat(4503599627370495.5, 20_480_000))->toBe(9_223_372_036_854_774_784);
    });

    it('accepts a fractional result of exactly −2^63 (inclusive lower bound)', function () {
        expect(aid1527Vat(-4010161755154250.5, 23_000_000))->toBe(PHP_INT_MIN);
    });

    it('matches the legacy float expression for sampled in-range cases (integral + fractional) across precision ini 6/14/17', function () {
        $rates = [2100, 400, 10000, 1050, 0, 9999, 5050, 7];
        mt_srand(20261018);

        foreach ([6, 14, 17] as $precision) {
            ini_set('precision', (string) $precision);

            try {
                for ($i = 0; $i < 400; $i++) {
                    $rate = $rates[array_rand($rates)];

                    // Integral base.
                    $integral = (float) mt_rand(-2000000000, 2000000000);
                    expect(aid1527Vat($integral, $rate))->toBe(aid1527LegacyVat($integral, $rate));

                    // Fractional base (a legacy surface the contract admits).
                    $fractional = mt_rand(-2000000000, 2000000000) / 100.0;
                    if ((float) (int) $fractional === $fractional) {
                        continue;
                    }
                    expect(aid1527Vat($fractional, $rate))->toBe(aid1527LegacyVat($fractional, $rate));
                }
            } finally {
                ini_set('precision', '14');
            }
        }
    });

});

describe('AID-1527 §3-bis site 2 — SettlementService::differenceItems frozen-rate tax', function () {

    it('liquidates a bigint rectification base exactly through the frozen-tax snapshot', function () {
        $items = aid1527DifferenceItems(-80000000000007, [
            ['source_rate_id' => 5, 'name' => 'IVA 21', 'rate' => 2100, 'amount' => 2100],
        ]);

        expect($items[0]['taxable_amount'])->toBe(-80000000000007)
            ->and($items[0]['total_tax_amount'])->toBe(-16_800_000_000_001)
            ->and($items[0]['total_amount'])->toBe(-96_800_000_000_008)
            ->and($items[0]['taxes_applied'][0]['amount'])->toBe(-16_800_000_000_001);
    });

    it('is exact where the legacy float expression lost precision above 2^53 (round-2 sensitivity probe)', function () {
        $items = aid1527DifferenceItems(-(2 ** 53 + 1), [
            ['source_rate_id' => 5, 'name' => 'IVA 21', 'rate' => 2100, 'amount' => 2100],
        ]);

        // Legacy float path computed 1_891_511_843_495_608; the exact path is 1_891_511_843_495_609.
        expect($items[0]['total_tax_amount'])->toBe(-1_891_511_843_495_609);
    });

    it('keeps zero tax on a tax-free original (no frozen snapshot)', function () {
        $items = aid1527DifferenceItems(-5000, []);

        expect($items[0]['taxable_amount'])->toBe(-5000)
            ->and($items[0]['total_tax_amount'])->toBe(0)
            ->and($items[0]['total_amount'])->toBe(-5000);
    });

    it('rejects a settlement tax beyond PHP_INT_MAX with the typed InvalidFixedDecimal — the SAME checked extraction as the VAT path (unchecked-exact mutant)', function () {
        // abs(-99_999_999_999_999) × 200_000 = 19_999_999_999_999_800_000 > PHP_INT_MAX.
        aid1527DifferenceItems(-99999999999999, [
            ['source_rate_id' => 5, 'name' => 'IVA', 'rate' => 2_000_000_000, 'amount' => 0],
        ]);
    })->throws(InvalidFixedDecimal::class);

    it('matches the legacy expression for sampled old-domain rectifications across precision ini settings', function () {
        $rates  = [2100, 400, 10000, 1050, 0, 21];
        $legacy = fn (int $amount, int $rate): int => (int) round(abs($amount) * ($rate / 10000));
        mt_srand(15270002);

        foreach ([6, 14, 17] as $precision) {
            ini_set('precision', (string) $precision);

            try {
                for ($i = 0; $i < 200; $i++) {
                    $amount = -mt_rand(1, 2000000000);
                    $rate   = $rates[array_rand($rates)];

                    $items = aid1527DifferenceItems($amount, [
                        ['source_rate_id' => 5, 'name' => 'IVA', 'rate' => $rate, 'amount' => 0],
                    ]);

                    expect($items[0]['total_tax_amount'])->toBe(-$legacy($amount, $rate));
                }
            } finally {
                ini_set('precision', '14');
            }
        }
    });

});

describe('AID-1527 §3-bis site 3 — SettlementArithmetic::unconsumedBase arbitrary-precision combined form', function () {

    it('survives the long-period large-base case that overflowed int in the legacy intdiv expression (round-2)', function () {
        $line = aid1527Line(99_999_999_999_999, 73051); // 2000-01-01 → 2200-01-01 inclusive, base = MAX_MONEY_CENTS

        // Full unconsumed period (consumedUntil before the service period).
        expect((new SettlementArithmetic)->unconsumedBase($line, Carbon::parse('1999-12-31')))
            ->toBe(99_999_999_999_999);
    });

    it('is bit-identical to the legacy intdiv combined form over sampled positive bases and day shapes', function () {
        $arithmetic = new SettlementArithmetic;
        $legacy     = fn (int $base, int $totalDays, int $unconsumedDays): int => intdiv(2 * $base * $unconsumedDays + $totalDays, 2 * $totalDays);

        mt_srand(15270003);

        for ($i = 0; $i < 1000; $i++) {
            $totalDays  = mt_rand(1, 400);
            $consumed   = mt_rand(0, $totalDays);
            $unconsumed = $totalDays - $consumed;

            if ($unconsumed === 0) {
                continue;
            }

            $base = mt_rand(1, 2147483647);
            $line = aid1527Line($base, $totalDays);
            // consumed days = diffInDays(from, until) + 1: consumed=0 → until one
            // day BEFORE the period (diff -1 → clamped to 0 consumed days).
            $until = Carbon::parse('2026-01-01')->addDays($consumed - 1);

            expect((new SettlementArithmetic)->unconsumedBase($line, $until))
                ->toBe($legacy($base, $totalDays, $unconsumed));
        }
    });

    it('is bit-identical on the half-boundary shapes (odd combined numerators: t=1 sweep)', function () {
        $arithmetic = new SettlementArithmetic;
        $legacy     = fn (int $base, int $totalDays, int $unconsumedDays): int => intdiv(2 * $base * $unconsumedDays + $totalDays, 2 * $totalDays);

        for ($base = 1; $base <= 10; $base++) {
            $line = aid1527Line($base, 1);

            expect((new SettlementArithmetic)->unconsumedBase($line, Carbon::parse('1999-12-31')))
                ->toBe($legacy($base, 1, 1));
        }
    });

    it('returns 0 for a fully consumed period', function () {
        $line = aid1527Line(5000, 10);

        expect((new SettlementArithmetic)->unconsumedBase($line, Carbon::parse('2026-01-10')))
            ->toBe(0);
    });

});
