<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\InvalidBillingIntervalException;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Carbon\Carbon;

/**
 * AID-952 (ADR-015 §2.4) — BillingInterval contract: the new cadence domain
 * (quantity + unit) that replaces the closed BillingFrequency enum.
 *
 * Overflow semantics are PINNED to the current engine: addition overflows
 * (`addMonths`/`addYears`, Jan 31 + 1 month → Mar 3, as
 * `BillingFrequency::addToDate()` does today) and subtraction uses NoOverflow
 * (as `BillingFrequency::subtractFromDate()` does today). Changing addition
 * semantics would silently shift migrated schedules — see spec §2.4.
 */
describe('BillingInterval', function () {
    it('constructs with every valid month quantity', function (int $quantity) {
        $interval = new BillingInterval('month', $quantity);

        expect($interval->unit)->toBe('month')
            ->and($interval->quantity)->toBe($quantity);
    })->with([1, 2, 3, 6, 12]);

    it('constructs with every valid year quantity', function (int $quantity) {
        $interval = new BillingInterval('year', $quantity);

        expect($interval->unit)->toBe('year')
            ->and($interval->quantity)->toBe($quantity);
    })->with([1, 2, 3, 10]);

    it('is readonly', function () {
        $interval = new BillingInterval('month', 1);

        $interval->quantity = 5;
    })->throws(Error::class);

    it('rejects a unit other than month or year', function (string $unit) {
        new BillingInterval($unit, 1);
    })->throws(InvalidBillingIntervalException::class)->with([
        'capitalised'      => ['Month'],
        'unsupported unit' => ['week'],
        'empty string'     => [''],
    ]);

    it('rejects an out-of-domain quantity', function (string $unit, int $quantity) {
        new BillingInterval($unit, $quantity);
    })->throws(InvalidBillingIntervalException::class)->with([
        'month 13'          => ['month', 13],
        'month 0'           => ['month', 0],
        'year 11'           => ['year', 11],
        'year 0'            => ['year', 0],
        'negative quantity' => ['month', -3],
    ]);

    it('names the valid domain in the quantity remediation text', function () {
        $message = InvalidBillingIntervalException::forQuantity(13, 'month')->getMessage();

        expect($message)->toContain('1-12')->toContain('1-10');
    });

    it('adds a month with overflow, preserving the current engine semantics', function () {
        // Jan 31 + 1 month overflows into March (BillingFrequency::addToDate
        // semantics): 2026-01-31 → 2026-03-03.
        $result = (new BillingInterval('month', 1))->addToDate(new Carbon('2026-01-31'));

        expect($result->toDateString())->toBe('2026-03-03');
    });

    it('adds one year onto a leap day with overflow', function () {
        $result = (new BillingInterval('year', 1))->addToDate(new Carbon('2024-02-29'));

        expect($result->toDateString())->toBe('2025-03-01');
    });

    it('adds a non-overflowing month unchanged', function () {
        $result = (new BillingInterval('month', 1))->addToDate(new Carbon('2026-01-15'));

        expect($result->toDateString())->toBe('2026-02-15');
    });

    it('subtracts a month without overflow', function () {
        // BillingFrequency::subtractFromDate semantics: Mar 31 − 1 month lands
        // on Feb 28, never on Mar 2/3.
        $result = (new BillingInterval('month', 1))->subtractFromDate(new Carbon('2026-03-31'));

        expect($result->toDateString())->toBe('2026-02-28');
    });

    it('subtracts a year from the last day of February without overflow', function () {
        $result = (new BillingInterval('year', 1))->subtractFromDate(new Carbon('2026-02-28'));

        expect($result->toDateString())->toBe('2025-02-28');
    });

    it('does not mutate the input date when adding', function () {
        $date = new Carbon('2026-01-31');

        (new BillingInterval('month', 1))->addToDate($date);

        expect($date->toDateString())->toBe('2026-01-31');
    });

    it('does not mutate the input date when subtracting', function () {
        $date = new Carbon('2026-03-31');

        (new BillingInterval('month', 1))->subtractFromDate($date);

        expect($date->toDateString())->toBe('2026-03-31');
    });

    it('approximates days for month-based intervals', function () {
        expect((new BillingInterval('month', 3))->approximateDays())->toBe(90);
    });

    it('approximates days for year-based intervals', function () {
        expect((new BillingInterval('year', 2))->approximateDays())->toBe(730);
    });

    it('labels singular and plural for both units', function () {
        expect((new BillingInterval('month', 1))->label())->toBe('1 month')
            ->and((new BillingInterval('month', 3))->label())->toBe('3 months')
            ->and((new BillingInterval('year', 1))->label())->toBe('1 year')
            ->and((new BillingInterval('year', 5))->label())->toBe('5 years');
    });

    it('equals another interval with the same unit and quantity', function () {
        expect((new BillingInterval('month', 3))->equals(new BillingInterval('month', 3)))->toBeTrue()
            ->and((new BillingInterval('year', 2))->equals(new BillingInterval('year', 2)))->toBeTrue();
    });

    it('does not equal an interval with a different unit or quantity', function () {
        $interval = new BillingInterval('month', 3);

        expect($interval->equals(new BillingInterval('month', 6)))->toBeFalse()
            ->and($interval->equals(new BillingInterval('year', 3)))->toBeFalse();
    });

    it('maps the seven recurring legacy frequencies deterministically', function (int $frequency, string $unit, int $quantity) {
        $interval = BillingInterval::fromLegacyFrequency($frequency);

        expect($interval)->not->toBeNull()
            ->and($interval->unit)->toBe($unit)
            ->and($interval->quantity)->toBe($quantity);
    })->with([
        'MONTHLY (3)'    => [3, 'month', 1],
        'BIMONTHLY (4)'  => [4, 'month', 2],
        'QUARTERLY (5)'  => [5, 'month', 3],
        'SEMIANNUAL (6)' => [6, 'month', 6],
        'YEARLY (7)'     => [7, 'year', 1],
        'BIENNIAL (8)'   => [8, 'year', 2],
        'TRIENNIAL (9)'  => [9, 'year', 3],
    ]);

    it('maps ONE_TIME (0) to null — null cadence is a real state', function () {
        expect(BillingInterval::fromLegacyFrequency(0))->toBeNull();
    });

    it('rejects weekly and biweekly legacy frequencies', function (int $frequency) {
        BillingInterval::fromLegacyFrequency($frequency);
    })->throws(InvalidBillingIntervalException::class)->with([
        'WEEKLY (1)'   => [1],
        'BIWEEKLY (2)' => [2],
    ]);

    it('rejects out-of-domain legacy frequencies', function (int $frequency) {
        BillingInterval::fromLegacyFrequency($frequency);
    })->throws(InvalidBillingIntervalException::class)->with([
        'beyond the enum (10)' => [10],
        'negative (-1)'        => [-1],
    ]);
});

describe('BillingInterval::toLegacyFrequency — the reverse bridge (ADR-015, transitional)', function () {
    it('maps the seven legacy-representable cadences back to the retired enum', function (string $unit, int $quantity, BillingFrequency $frequency) {
        expect((new BillingInterval($unit, $quantity))->toLegacyFrequency())->toBe($frequency);
    })->with([
        'month/1 → MONTHLY'    => ['month', 1, BillingFrequency::MONTHLY],
        'month/2 → BIMONTHLY'  => ['month', 2, BillingFrequency::BIMONTHLY],
        'month/3 → QUARTERLY'  => ['month', 3, BillingFrequency::QUARTERLY],
        'month/6 → SEMIANNUAL' => ['month', 6, BillingFrequency::SEMIANNUAL],
        'year/1 → YEARLY'      => ['year', 1, BillingFrequency::YEARLY],
        'year/2 → BIENNIAL'    => ['year', 2, BillingFrequency::BIENNIAL],
        'year/3 → TRIENNIAL'   => ['year', 3, BillingFrequency::TRIENNIAL],
    ]);

    it('throws for cadences inside the new domain but outside the legacy image', function () {
        // The bridge is transitional (retired with the enum in slice 2b):
        // failing loud beats silently dropping a cadence from a read.
        (new BillingInterval('month', 5))->toLegacyFrequency();
    })->throws(InvalidArgumentException::class);
});

describe('BillingInterval long-cadence invariance (AID-1613, year/4-10)', function () {
    // The 7.0 domain admits years 1-10; the reference consumer (clientes,
    // MR !336) imports 4-, 5- and 9-year domain records at their EXACT
    // cadence. These pins are live contracts after the cutover: scheduling
    // at N > 3 must behave exactly like scheduling at N <= 3.

    it('adds N years exactly for every cadence in the long tail', function (int $quantity, string $expectedDate) {
        $result = (new BillingInterval('year', $quantity))->addToDate(new Carbon('2026-03-15'));

        expect($result->toDateString())->toBe($expectedDate);
    })->with([
        'year/4'  => [4, '2030-03-15'],
        'year/5'  => [5, '2031-03-15'],
        'year/6'  => [6, '2032-03-15'],
        'year/7'  => [7, '2033-03-15'],
        'year/8'  => [8, '2034-03-15'],
        'year/9'  => [9, '2035-03-15'],
        'year/10' => [10, '2036-03-15'],
    ]);

    it('round-trips add/subtract without drift across the long tail', function (int $quantity, Carbon $seed) {
        $interval = new BillingInterval('year', $quantity);

        $added        = $interval->addToDate($seed);
        $roundTripped = $interval->subtractFromDate($added);

        expect($roundTripped->toDateString())->toBe($seed->toDateString());
    })->with([
        // Leap-safe multipliers: 2024+4=2028 and 2024+8=2032 are leap years,
        // so Feb 29 survives both legs (no overflow, exact identity).
        'year/4 from a leap day'   => [4, new Carbon('2024-02-29')],
        'year/8 from a leap day'   => [8, new Carbon('2024-02-29')],
        'year/5 from Feb 28'       => [5, new Carbon('2026-02-28')],
        'year/9 from Dec 31'       => [9, new Carbon('2026-12-31')],
    ]);

    it('overflows a leap seed symmetrically when the target year is not leap', function () {
        // Same pinned engine semantics as the year/1 case above: a leap seed
        // landing on a non-leap target overflows to Mar 1 on the way out, and
        // the return leg comes back to Mar 1 — the round-trip through an
        // overflow is NOT an identity, by design (documented asymmetry).
        $interval = new BillingInterval('year', 10);

        $added        = $interval->addToDate(new Carbon('2024-02-29'));
        $roundTripped = $interval->subtractFromDate($added);

        expect($added->toDateString())->toBe('2034-03-01')
            ->and($roundTripped->toDateString())->toBe('2024-03-01');
    });

    it('labels every long cadence as a plural year phrase', function (int $quantity, string $label) {
        expect((new BillingInterval('year', $quantity))->label())->toBe($label);
    })->with([
        'year/4'  => [4, '4 years'],
        'year/5'  => [5, '5 years'],
        'year/9'  => [9, '9 years'],
        'year/10' => [10, '10 years'],
    ]);
});
