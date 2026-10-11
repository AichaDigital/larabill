<?php

declare(strict_types=1);

use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Models\ArticleServiceStatus;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Carbon\Carbon;

/*
 * Scheduling invariance — ADR-015 slice 2b (AID-952).
 *
 * The recurring engine's scheduling moved from `BillingFrequency::addToDate()`
 * to `BillingInterval::addToDate()` (unit + quantity). Overflow semantics are
 * PINNED (spec §2.4): addition overflows exactly as the enum does (Jan 31 + 1
 * month → Mar 3), subtraction uses NoOverflow. This test pins the bridge: for
 * every legacy-representable cadence and a table of anchors — including Jan 31,
 * Feb 29 leap and month-end overflows — the new BillingInterval path MUST
 * produce the same dates the old frequency math produced for the same anchor.
 *
 * One-time (legacy 0) becomes the NULL cadence: the old math returned the date
 * unchanged (`BillingFrequency::ONE_TIME->addToDate()` copies), so the NULL
 * interval must too — everywhere the engine schedules.
 */

/**
 * Every legacy-representable cadence paired with its ADR-015 interval (or
 * NULL for the one-time cadence). Single source: `BillingInterval::
 * fromLegacyFrequency()` — the same bridge the migration backfills through.
 *
 * @return array<string, array{0: BillingFrequency, 1: ?BillingInterval}>
 */
function schedulingInvarianceCadences(): array
{
    return [
        'monthly'    => [BillingFrequency::MONTHLY, new BillingInterval('month', 1)],
        'bimonthly'  => [BillingFrequency::BIMONTHLY, new BillingInterval('month', 2)],
        'quarterly'  => [BillingFrequency::QUARTERLY, new BillingInterval('month', 3)],
        'semiannual' => [BillingFrequency::SEMIANNUAL, new BillingInterval('month', 6)],
        'yearly'     => [BillingFrequency::YEARLY, new BillingInterval('year', 1)],
        'biennial'   => [BillingFrequency::BIENNIAL, new BillingInterval('year', 2)],
        'triennial'  => [BillingFrequency::TRIENNIAL, new BillingInterval('year', 3)],
        'one-time'   => [BillingFrequency::ONE_TIME, null],
    ];
}

/**
 * Anchors that stress the pinned overflow semantics: Jan 31 (leap and
 * non-leap), Feb 29 leap, month-ends and a mid-month control.
 */
function schedulingInvarianceAnchors(): array
{
    return ['2024-01-31', '2023-01-31', '2024-02-29', '2024-03-31', '2024-12-31', '2024-10-15'];
}

/**
 * The full (cadence, anchor) dataset, flattened for Pest.
 *
 * @return array<int, array{0: BillingFrequency, 1: ?BillingInterval, 2: string}>
 */
function schedulingInvarianceDataset(): array
{
    $dataset = [];

    foreach (schedulingInvarianceCadences() as $name => [$frequency, $interval]) {
        foreach (schedulingInvarianceAnchors() as $anchor) {
            $dataset[] = [$frequency, $interval, $anchor, $name.' @ '.$anchor];
        }
    }

    return $dataset;
}

it('produces the same next-billing dates as the legacy frequency math for every cadence and anchor', function (
    BillingFrequency $frequency,
    ?BillingInterval $interval,
    string $anchor,
) {
    $date = Carbon::parse($anchor);

    // Scheduling (addition): the new path must equal the old one exactly.
    $legacyNext   = $frequency->addToDate($date);
    $intervalNext = $interval?->addToDate($date) ?? $date->copy();

    expect($intervalNext->toDateString())->toBe(
        $legacyNext->toDateString(),
        "scheduling diverged for {$frequency->name} anchored at {$anchor}",
    );

    // Reverse math (subtraction, NoOverflow): same pin, used by the refund's
    // last-billing-date approximation.
    $legacyPrevious   = $frequency->subtractFromDate($date, 1);
    $intervalPrevious = $interval?->subtractFromDate($date) ?? $date->copy();

    expect($intervalPrevious->toDateString())->toBe(
        $legacyPrevious->toDateString(),
        "reverse scheduling diverged for {$frequency->name} anchored at {$anchor}",
    );

    // Period end: addToDate() minus one day — the recurring emission window.
    $legacyPeriodEnd   = $frequency->addToDate($date)->subDay();
    $intervalPeriodEnd = ($interval?->addToDate($date) ?? $date->copy())->subDay();

    expect($intervalPeriodEnd->toDateString())->toBe(
        $legacyPeriodEnd->toDateString(),
        "period end diverged for {$frequency->name} anchored at {$anchor}",
    );
})->with(schedulingInvarianceDataset());

it('computes the same next billing date on the model as the legacy frequency math', function (
    BillingFrequency $frequency,
    ?BillingInterval $interval,
    string $anchor,
) {
    $service = ArticleServiceStatus::factory()->make([
        'billing_unit'      => $interval?->unit,
        'billing_quantity'  => $interval?->quantity,
        'next_billing_date' => Carbon::parse($anchor),
    ]);

    $legacyNext = $frequency->addToDate(Carbon::parse($anchor));

    expect($service->calculateNextBillingDate()->toDateString())->toBe(
        $legacyNext->toDateString(),
        "model scheduling diverged for {$frequency->name} anchored at {$anchor}",
    );
})->with(schedulingInvarianceDataset());

it('keeps the one-time (NULL cadence) scheduling behaviour: the date comes back unchanged', function () {
    // Legacy ONE_TIME semantics: addToDate() returns the date unchanged — so
    // a NULL-cadence agreement must too, both on the model and on the VO.
    $anchor = Carbon::parse('2024-01-31');

    $service = ArticleServiceStatus::factory()->make([
        'billing_unit'      => null,
        'billing_quantity'  => null,
        'next_billing_date' => $anchor,
    ]);

    expect($service->calculateNextBillingDate()->toDateString())->toBe($anchor->toDateString())
        ->and($service->billingInterval())->toBeNull();
});

it('resolves the billing interval from the agreement unit/quantity columns', function () {
    $service = ArticleServiceStatus::factory()->make([
        'billing_unit'     => 'month',
        'billing_quantity' => 3,
    ]);

    expect($service->billingInterval())->toEqual(new BillingInterval('month', 3));
});
