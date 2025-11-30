<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use Carbon\Carbon;

it('has correct values for each case', function () {
    expect(BillingFrequency::MONTHLY->value)->toBe(0)
        ->and(BillingFrequency::QUARTERLY->value)->toBe(1)
        ->and(BillingFrequency::YEARLY->value)->toBe(2)
        ->and(BillingFrequency::LIFETIME->value)->toBe(3);
});

it('returns correct labels', function () {
    expect(BillingFrequency::MONTHLY->label())->toBe('Monthly')
        ->and(BillingFrequency::QUARTERLY->label())->toBe('Quarterly')
        ->and(BillingFrequency::YEARLY->label())->toBe('Yearly')
        ->and(BillingFrequency::LIFETIME->label())->toBe('Lifetime');
});

it('returns correct months for each frequency', function () {
    expect(BillingFrequency::MONTHLY->months())->toBe(1)
        ->and(BillingFrequency::QUARTERLY->months())->toBe(3)
        ->and(BillingFrequency::YEARLY->months())->toBe(12)
        ->and(BillingFrequency::LIFETIME->months())->toBeNull();
});

it('adds correct interval to date for monthly', function () {
    $date = Carbon::parse('2024-01-15');

    $nextMonth = BillingFrequency::MONTHLY->addToDate($date);

    expect($nextMonth->format('Y-m-d'))->toBe('2024-02-15');
});

it('adds correct interval to date for quarterly', function () {
    $date = Carbon::parse('2024-01-15');

    $nextQuarter = BillingFrequency::QUARTERLY->addToDate($date);

    expect($nextQuarter->format('Y-m-d'))->toBe('2024-04-15');
});

it('adds correct interval to date for yearly', function () {
    $date = Carbon::parse('2024-02-15');

    $nextYear = BillingFrequency::YEARLY->addToDate($date);

    expect($nextYear->format('Y-m-d'))->toBe('2025-02-15');
});

it('does not change date for lifetime', function () {
    $date = Carbon::parse('2024-01-15');

    $result = BillingFrequency::LIFETIME->addToDate($date);

    expect($result->format('Y-m-d'))->toBe('2024-01-15');
});

it('adds multiple intervals correctly', function () {
    $date = Carbon::parse('2024-01-15');

    $twoMonths   = BillingFrequency::MONTHLY->addToDate($date, 2);
    $twoQuarters = BillingFrequency::QUARTERLY->addToDate($date, 2);
    $twoYears    = BillingFrequency::YEARLY->addToDate($date, 2);

    expect($twoMonths->format('Y-m-d'))->toBe('2024-03-15')
        ->and($twoQuarters->format('Y-m-d'))->toBe('2024-07-15')
        ->and($twoYears->format('Y-m-d'))->toBe('2026-01-15');
});

it('identifies recurring frequencies correctly', function () {
    expect(BillingFrequency::MONTHLY->isRecurring())->toBeTrue()
        ->and(BillingFrequency::QUARTERLY->isRecurring())->toBeTrue()
        ->and(BillingFrequency::YEARLY->isRecurring())->toBeTrue()
        ->and(BillingFrequency::LIFETIME->isRecurring())->toBeFalse();
});

it('returns all recurring frequencies', function () {
    $recurring = BillingFrequency::recurring();

    expect($recurring)->toHaveCount(3)
        ->and($recurring)->toContain(BillingFrequency::MONTHLY)
        ->and($recurring)->toContain(BillingFrequency::QUARTERLY)
        ->and($recurring)->toContain(BillingFrequency::YEARLY)
        ->and($recurring)->not->toContain(BillingFrequency::LIFETIME);
});

it('creates frequency from months', function () {
    expect(BillingFrequency::fromMonths(1))->toBe(BillingFrequency::MONTHLY)
        ->and(BillingFrequency::fromMonths(3))->toBe(BillingFrequency::QUARTERLY)
        ->and(BillingFrequency::fromMonths(12))->toBe(BillingFrequency::YEARLY)
        ->and(BillingFrequency::fromMonths(6))->toBeNull()
        ->and(BillingFrequency::fromMonths(24))->toBeNull();
});

it('handles edge cases for date calculations', function () {
    // Last day of January → Carbon overflow behavior
    $jan31 = Carbon::parse('2024-01-31');
    $feb   = BillingFrequency::MONTHLY->addToDate($jan31);
    expect($feb->format('Y-m-d'))->toBe('2024-03-02'); // Carbon overflow: 31+1month = Mar 2

    // May 31 → June 30 (June has 30 days)
    $may31 = Carbon::parse('2024-05-31');
    $june  = BillingFrequency::MONTHLY->addToDate($may31);
    expect($june->format('Y-m-d'))->toBe('2024-07-01'); // Carbon overflow: 31+1month = Jul 1

    // Safe dates (mid-month) work as expected
    $jan15 = Carbon::parse('2024-01-15');
    $feb15 = BillingFrequency::MONTHLY->addToDate($jan15);
    expect($feb15->format('Y-m-d'))->toBe('2024-02-15');
});

it('does not mutate original date', function () {
    $original    = Carbon::parse('2024-01-15');
    $originalStr = $original->format('Y-m-d');

    BillingFrequency::MONTHLY->addToDate($original);

    expect($original->format('Y-m-d'))->toBe($originalStr);
});
