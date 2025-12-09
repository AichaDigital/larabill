<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use Carbon\Carbon;

describe('BillingFrequency enum values', function () {
    it('has correct values ordered by period length', function () {
        expect(BillingFrequency::ONE_TIME->value)->toBe(0)
            ->and(BillingFrequency::WEEKLY->value)->toBe(1)
            ->and(BillingFrequency::BIWEEKLY->value)->toBe(2)
            ->and(BillingFrequency::MONTHLY->value)->toBe(3)
            ->and(BillingFrequency::BIMONTHLY->value)->toBe(4)
            ->and(BillingFrequency::QUARTERLY->value)->toBe(5)
            ->and(BillingFrequency::SEMIANNUAL->value)->toBe(6)
            ->and(BillingFrequency::YEARLY->value)->toBe(7)
            ->and(BillingFrequency::BIENNIAL->value)->toBe(8)
            ->and(BillingFrequency::TRIENNIAL->value)->toBe(9);
    });

    it('has 10 frequency options', function () {
        expect(BillingFrequency::cases())->toHaveCount(10);
    });
});

describe('BillingFrequency labels', function () {
    it('returns correct labels for all frequencies', function () {
        expect(BillingFrequency::ONE_TIME->label())->toBe('One-time')
            ->and(BillingFrequency::WEEKLY->label())->toBe('Weekly')
            ->and(BillingFrequency::BIWEEKLY->label())->toBe('Biweekly')
            ->and(BillingFrequency::MONTHLY->label())->toBe('Monthly')
            ->and(BillingFrequency::BIMONTHLY->label())->toBe('Bimonthly')
            ->and(BillingFrequency::QUARTERLY->label())->toBe('Quarterly')
            ->and(BillingFrequency::SEMIANNUAL->label())->toBe('Semiannual')
            ->and(BillingFrequency::YEARLY->label())->toBe('Yearly')
            ->and(BillingFrequency::BIENNIAL->label())->toBe('Biennial')
            ->and(BillingFrequency::TRIENNIAL->label())->toBe('Triennial');
    });
});

describe('BillingFrequency months', function () {
    it('returns correct months for monthly-based frequencies', function () {
        expect(BillingFrequency::MONTHLY->months())->toBe(1)
            ->and(BillingFrequency::BIMONTHLY->months())->toBe(2)
            ->and(BillingFrequency::QUARTERLY->months())->toBe(3)
            ->and(BillingFrequency::SEMIANNUAL->months())->toBe(6)
            ->and(BillingFrequency::YEARLY->months())->toBe(12)
            ->and(BillingFrequency::BIENNIAL->months())->toBe(24)
            ->and(BillingFrequency::TRIENNIAL->months())->toBe(36);
    });

    it('returns null for non-month-based frequencies', function () {
        expect(BillingFrequency::ONE_TIME->months())->toBeNull()
            ->and(BillingFrequency::WEEKLY->months())->toBeNull()
            ->and(BillingFrequency::BIWEEKLY->months())->toBeNull();
    });
});

describe('BillingFrequency days', function () {
    it('returns correct days for sub-monthly frequencies', function () {
        expect(BillingFrequency::WEEKLY->days())->toBe(7)
            ->and(BillingFrequency::BIWEEKLY->days())->toBe(14);
    });

    it('returns null for month-based and one-time frequencies', function () {
        expect(BillingFrequency::ONE_TIME->days())->toBeNull()
            ->and(BillingFrequency::MONTHLY->days())->toBeNull()
            ->and(BillingFrequency::QUARTERLY->days())->toBeNull()
            ->and(BillingFrequency::YEARLY->days())->toBeNull();
    });
});

describe('BillingFrequency approximate days', function () {
    it('returns approximate days for all recurring frequencies', function () {
        expect(BillingFrequency::WEEKLY->approximateDays())->toBe(7)
            ->and(BillingFrequency::BIWEEKLY->approximateDays())->toBe(14)
            ->and(BillingFrequency::MONTHLY->approximateDays())->toBe(30)
            ->and(BillingFrequency::BIMONTHLY->approximateDays())->toBe(60)
            ->and(BillingFrequency::QUARTERLY->approximateDays())->toBe(90)
            ->and(BillingFrequency::SEMIANNUAL->approximateDays())->toBe(180)
            ->and(BillingFrequency::YEARLY->approximateDays())->toBe(365)
            ->and(BillingFrequency::BIENNIAL->approximateDays())->toBe(730)
            ->and(BillingFrequency::TRIENNIAL->approximateDays())->toBe(1095);
    });

    it('returns null for one-time frequency', function () {
        expect(BillingFrequency::ONE_TIME->approximateDays())->toBeNull();
    });
});

describe('BillingFrequency isRecurring', function () {
    it('identifies recurring frequencies correctly', function () {
        expect(BillingFrequency::WEEKLY->isRecurring())->toBeTrue()
            ->and(BillingFrequency::BIWEEKLY->isRecurring())->toBeTrue()
            ->and(BillingFrequency::MONTHLY->isRecurring())->toBeTrue()
            ->and(BillingFrequency::BIMONTHLY->isRecurring())->toBeTrue()
            ->and(BillingFrequency::QUARTERLY->isRecurring())->toBeTrue()
            ->and(BillingFrequency::SEMIANNUAL->isRecurring())->toBeTrue()
            ->and(BillingFrequency::YEARLY->isRecurring())->toBeTrue()
            ->and(BillingFrequency::BIENNIAL->isRecurring())->toBeTrue()
            ->and(BillingFrequency::TRIENNIAL->isRecurring())->toBeTrue();
    });

    it('identifies one-time as non-recurring', function () {
        expect(BillingFrequency::ONE_TIME->isRecurring())->toBeFalse();
    });
});

describe('BillingFrequency recurring helper', function () {
    it('returns all recurring frequencies (9 total)', function () {
        $recurring = BillingFrequency::recurring();

        expect($recurring)->toHaveCount(9)
            ->and($recurring)->not->toContain(BillingFrequency::ONE_TIME);
    });

    it('returns month-based frequencies only (7 total)', function () {
        $monthlyOrLonger = BillingFrequency::monthlyOrLonger();

        expect($monthlyOrLonger)->toHaveCount(7)
            ->and($monthlyOrLonger)->toContain(BillingFrequency::MONTHLY)
            ->and($monthlyOrLonger)->toContain(BillingFrequency::YEARLY)
            ->and($monthlyOrLonger)->not->toContain(BillingFrequency::WEEKLY)
            ->and($monthlyOrLonger)->not->toContain(BillingFrequency::BIWEEKLY)
            ->and($monthlyOrLonger)->not->toContain(BillingFrequency::ONE_TIME);
    });
});

describe('BillingFrequency addToDate', function () {
    it('adds weekly intervals correctly', function () {
        $date = Carbon::parse('2024-01-15');

        $result = BillingFrequency::WEEKLY->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-01-22');
    });

    it('adds biweekly intervals correctly', function () {
        $date = Carbon::parse('2024-01-15');

        $result = BillingFrequency::BIWEEKLY->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-01-29');
    });

    it('adds monthly intervals correctly', function () {
        $date = Carbon::parse('2024-01-15');

        $result = BillingFrequency::MONTHLY->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-02-15');
    });

    it('adds bimonthly intervals correctly', function () {
        $date = Carbon::parse('2024-01-15');

        $result = BillingFrequency::BIMONTHLY->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-03-15');
    });

    it('adds quarterly intervals correctly', function () {
        $date = Carbon::parse('2024-01-15');

        $result = BillingFrequency::QUARTERLY->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-04-15');
    });

    it('adds semiannual intervals correctly', function () {
        $date = Carbon::parse('2024-01-15');

        $result = BillingFrequency::SEMIANNUAL->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-07-15');
    });

    it('adds yearly intervals correctly', function () {
        $date = Carbon::parse('2024-02-15');

        $result = BillingFrequency::YEARLY->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2025-02-15');
    });

    it('adds biennial intervals correctly', function () {
        $date = Carbon::parse('2024-02-15');

        $result = BillingFrequency::BIENNIAL->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2026-02-15');
    });

    it('adds triennial intervals correctly', function () {
        $date = Carbon::parse('2024-02-15');

        $result = BillingFrequency::TRIENNIAL->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2027-02-15');
    });

    it('does not change date for one-time', function () {
        $date = Carbon::parse('2024-01-15');

        $result = BillingFrequency::ONE_TIME->addToDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-01-15');
    });

    it('adds multiple intervals correctly', function () {
        $date = Carbon::parse('2024-01-15');

        $twoWeeks    = BillingFrequency::WEEKLY->addToDate($date, 2);
        $twoMonths   = BillingFrequency::MONTHLY->addToDate($date, 2);
        $twoQuarters = BillingFrequency::QUARTERLY->addToDate($date, 2);
        $twoYears    = BillingFrequency::YEARLY->addToDate($date, 2);

        expect($twoWeeks->format('Y-m-d'))->toBe('2024-01-29')
            ->and($twoMonths->format('Y-m-d'))->toBe('2024-03-15')
            ->and($twoQuarters->format('Y-m-d'))->toBe('2024-07-15')
            ->and($twoYears->format('Y-m-d'))->toBe('2026-01-15');
    });

    it('does not mutate original date', function () {
        $original    = Carbon::parse('2024-01-15');
        $originalStr = $original->format('Y-m-d');

        BillingFrequency::MONTHLY->addToDate($original);

        expect($original->format('Y-m-d'))->toBe($originalStr);
    });
});

describe('BillingFrequency subtractFromDate', function () {
    it('subtracts weekly intervals correctly', function () {
        $date = Carbon::parse('2024-01-22');

        $result = BillingFrequency::WEEKLY->subtractFromDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-01-15');
    });

    it('subtracts monthly intervals correctly', function () {
        $date = Carbon::parse('2024-02-15');

        $result = BillingFrequency::MONTHLY->subtractFromDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-01-15');
    });

    it('subtracts yearly intervals correctly', function () {
        $date = Carbon::parse('2025-02-15');

        $result = BillingFrequency::YEARLY->subtractFromDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-02-15');
    });

    it('does not change date for one-time', function () {
        $date = Carbon::parse('2024-01-15');

        $result = BillingFrequency::ONE_TIME->subtractFromDate($date);

        expect($result->format('Y-m-d'))->toBe('2024-01-15');
    });
});

describe('BillingFrequency fromMonths', function () {
    it('creates frequency from months count', function () {
        expect(BillingFrequency::fromMonths(1))->toBe(BillingFrequency::MONTHLY)
            ->and(BillingFrequency::fromMonths(2))->toBe(BillingFrequency::BIMONTHLY)
            ->and(BillingFrequency::fromMonths(3))->toBe(BillingFrequency::QUARTERLY)
            ->and(BillingFrequency::fromMonths(6))->toBe(BillingFrequency::SEMIANNUAL)
            ->and(BillingFrequency::fromMonths(12))->toBe(BillingFrequency::YEARLY)
            ->and(BillingFrequency::fromMonths(24))->toBe(BillingFrequency::BIENNIAL)
            ->and(BillingFrequency::fromMonths(36))->toBe(BillingFrequency::TRIENNIAL);
    });

    it('returns null for invalid month counts', function () {
        expect(BillingFrequency::fromMonths(5))->toBeNull()
            ->and(BillingFrequency::fromMonths(7))->toBeNull()
            ->and(BillingFrequency::fromMonths(48))->toBeNull();
    });
});

describe('BillingFrequency fromDays', function () {
    it('creates frequency from days count', function () {
        expect(BillingFrequency::fromDays(7))->toBe(BillingFrequency::WEEKLY)
            ->and(BillingFrequency::fromDays(14))->toBe(BillingFrequency::BIWEEKLY);
    });

    it('returns null for invalid day counts', function () {
        expect(BillingFrequency::fromDays(1))->toBeNull()
            ->and(BillingFrequency::fromDays(30))->toBeNull()
            ->and(BillingFrequency::fromDays(365))->toBeNull();
    });
});
