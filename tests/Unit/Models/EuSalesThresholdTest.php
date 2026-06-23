<?php

declare(strict_types=1);

/**
 * EuSalesThreshold — base-100 / FixedDecimal contract (AID-240).
 *
 * Monetary amounts (total_amount, threshold_amount) are FixedDecimal:2 over an
 * integer column of base-100 minor units. breakdown_by_country is a JSON map of
 * raw integer cents per country. Per-country and aggregate amounts are exposed
 * as FixedDecimal; the raw breakdown attribute stays integer cents.
 *
 * cents(500000) === €5,000.00 (helper from tests/Pest.php).
 */

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Models\EuSalesThreshold;
use AichaDigital\Larabill\Tests\TestCase;
use Carbon\Carbon;

it('can create an EU sales threshold', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'              => TestCase::USER_UUID_1,
        'fiscal_year'          => 2024,
        'total_amount'         => cents(500000), // €5,000.00
        'threshold_exceeded'   => false,
        'notification_sent'    => false,
        'breakdown_by_country' => [
            'DE' => 200000, // €2,000.00
            'FR' => 300000, // €3,000.00
        ],
    ]);

    expect($threshold)->toBeInstanceOf(EuSalesThreshold::class);
    expect($threshold->user_id)->toBe(TestCase::USER_UUID_1);
    expect($threshold->fiscal_year)->toBe(2024);
    expect($threshold->total_amount)->toBeInstanceOf(FixedDecimal::class);
    expect($threshold->total_amount->isEqualTo(cents(500000)))->toBeTrue();
    expect($threshold->threshold_exceeded)->toBeFalse();
    expect($threshold->breakdown_by_country['DE'])->toBe(200000);
});

it('can find EU sales threshold by company and year', function () {
    EuSalesThreshold::create([
        'user_id'      => TestCase::USER_UUID_1,
        'fiscal_year'  => 2024,
        'total_amount' => cents(500000),
    ]);

    $found = EuSalesThreshold::findByUserAndYear(TestCase::USER_UUID_1, 2024);

    expect($found)->not->toBeNull();
    expect($found->user_id)->toBe(TestCase::USER_UUID_1);
    expect($found->fiscal_year)->toBe(2024);
});

it('can get or create EU sales threshold for company', function () {
    $threshold1 = EuSalesThreshold::getOrCreateForUser(TestCase::USER_UUID_2, 2024);

    expect($threshold1)->toBeInstanceOf(EuSalesThreshold::class);
    expect($threshold1->user_id)->toBe(TestCase::USER_UUID_2);
    expect($threshold1->fiscal_year)->toBe(2024);
    expect($threshold1->total_amount->isZero())->toBeTrue();

    $threshold2 = EuSalesThreshold::getOrCreateForUser(TestCase::USER_UUID_2, 2024);

    expect($threshold2->id)->toBe($threshold1->id);
});

it('can add sales amount', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'              => TestCase::USER_UUID_1,
        'fiscal_year'          => 2024,
        'total_amount'         => cents(500000),
        'breakdown_by_country' => [
            'DE' => 200000,
            'FR' => 300000,
        ],
    ]);

    $updated = $threshold->addSalesAmount('IT', cents(100000));

    expect($updated->total_amount->isEqualTo(cents(600000)))->toBeTrue();
    expect($updated->breakdown_by_country['IT'])->toBe(100000);
    expect($updated->breakdown_by_country['DE'])->toBe(200000);
    expect($updated->breakdown_by_country['FR'])->toBe(300000);
});

it('can update existing country amount', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'              => TestCase::USER_UUID_1,
        'fiscal_year'          => 2024,
        'total_amount'         => cents(500000),
        'breakdown_by_country' => [
            'DE' => 200000,
            'FR' => 300000,
        ],
    ]);

    $updated = $threshold->addSalesAmount('DE', cents(50000));

    expect($updated->total_amount->isEqualTo(cents(550000)))->toBeTrue();
    expect($updated->breakdown_by_country['DE'])->toBe(250000);
    expect($updated->breakdown_by_country['FR'])->toBe(300000);
});

it('can check if threshold is exceeded', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_1,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(500000),
        'threshold_exceeded' => false,
    ]);

    expect($threshold->isThresholdExceeded())->toBeFalse();

    $threshold->update(['total_amount' => cents(1200000), 'threshold_exceeded' => true]);

    expect($threshold->isThresholdExceeded())->toBeTrue();
});

it('can mark threshold as exceeded', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_1,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(1200000),
        'threshold_exceeded' => false,
    ]);

    $marked = $threshold->markThresholdExceeded();

    expect($marked->threshold_exceeded)->toBeTrue();
    expect($marked->exceeded_at)->not->toBeNull();
    expect($marked->exceeded_at)->toBeInstanceOf(Carbon::class);
});

it('can mark notification as sent', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'           => TestCase::USER_UUID_1,
        'fiscal_year'       => 2024,
        'notification_sent' => false,
    ]);

    $marked = $threshold->markNotificationSent();

    expect($marked->notification_sent)->toBeTrue();
});

it('can get sales amount by country', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'              => TestCase::USER_UUID_1,
        'fiscal_year'          => 2024,
        'total_amount'         => cents(500000),
        'breakdown_by_country' => [
            'DE' => 200000,
            'FR' => 300000,
        ],
    ]);

    expect($threshold->getSalesAmountByCountry('DE')->isEqualTo(cents(200000)))->toBeTrue();
    expect($threshold->getSalesAmountByCountry('FR')->isEqualTo(cents(300000)))->toBeTrue();
    expect($threshold->getSalesAmountByCountry('IT')->isZero())->toBeTrue();
});

it('can get all countries with sales', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'              => TestCase::USER_UUID_1,
        'fiscal_year'          => 2024,
        'total_amount'         => cents(500000),
        'breakdown_by_country' => [
            'DE' => 200000,
            'FR' => 300000,
        ],
    ]);

    $countries = $threshold->getCountriesWithSales();

    expect($countries)->toHaveCount(2);
    expect($countries)->toContain('DE');
    expect($countries)->toContain('FR');
});

it('can reset sales amounts', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'              => TestCase::USER_UUID_1,
        'fiscal_year'          => 2024,
        'total_amount'         => cents(1200000),
        'threshold_exceeded'   => true,
        'exceeded_at'          => now(),
        'notification_sent'    => true,
        'breakdown_by_country' => [
            'DE' => 200000,
            'FR' => 300000,
            'IT' => 700000,
        ],
    ]);

    $reset = $threshold->resetSalesAmounts();

    expect($reset->total_amount->isZero())->toBeTrue();
    expect($reset->threshold_exceeded)->toBeFalse();
    expect($reset->exceeded_at)->toBeNull();
    expect($reset->notification_sent)->toBeFalse();
    expect($reset->breakdown_by_country)->toBeEmpty();
});

it('can use scopes correctly', function () {
    EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_1,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(1200000),
        'threshold_exceeded' => true,
        'notification_sent'  => true,
    ]);

    EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_2,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(500000),
        'threshold_exceeded' => false,
        'notification_sent'  => false,
    ]);

    EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_3,
        'fiscal_year'        => 2023,
        'total_amount'       => cents(1500000),
        'threshold_exceeded' => true,
        'notification_sent'  => false,
    ]);

    expect(EuSalesThreshold::byFiscalYear(2024)->get())->toHaveCount(2);
    expect(EuSalesThreshold::thresholdExceeded()->get())->toHaveCount(2);
    expect(EuSalesThreshold::byUser(TestCase::USER_UUID_1)->get())->toHaveCount(1);
    expect(EuSalesThreshold::needsNotification()->get())->toHaveCount(1);
});

it('can get threshold statistics', function () {
    EuSalesThreshold::create([
        'user_id'              => TestCase::USER_UUID_1,
        'fiscal_year'          => 2024,
        'total_amount'         => cents(1200000),
        'threshold_exceeded'   => true,
        'breakdown_by_country' => [
            'DE' => 500000,
            'FR' => 700000,
        ],
    ]);

    EuSalesThreshold::create([
        'user_id'              => TestCase::USER_UUID_2,
        'fiscal_year'          => 2024,
        'total_amount'         => cents(500000),
        'threshold_exceeded'   => false,
        'breakdown_by_country' => [
            'IT' => 500000,
        ],
    ]);

    $stats = EuSalesThreshold::getThresholdStatistics(2024);

    expect($stats['total_companies'])->toBe(2);
    expect($stats['exceeded_companies'])->toBe(1);
    expect($stats['total_sales_amount']->isEqualTo(cents(1700000)))->toBeTrue();
    expect($stats['breakdown_by_country']['DE'])->toBe(500000);
    expect($stats['breakdown_by_country']['FR'])->toBe(700000);
    expect($stats['breakdown_by_country']['IT'])->toBe(500000);
});

it('can get companies exceeding threshold', function () {
    EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_1,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(1200000),
        'threshold_exceeded' => true,
    ]);

    EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_2,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(500000),
        'threshold_exceeded' => false,
    ]);

    $exceededCompanies = EuSalesThreshold::getCompaniesExceedingThreshold(2024);

    expect($exceededCompanies)->toHaveCount(1);
    expect($exceededCompanies->first()->user_id)->toBe(TestCase::USER_UUID_1);
});

it('can get companies needing notification', function () {
    EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_1,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(1200000),
        'threshold_exceeded' => true,
        'notification_sent'  => false,
    ]);

    EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_2,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(1200000),
        'threshold_exceeded' => true,
        'notification_sent'  => true,
    ]);

    $needsNotification = EuSalesThreshold::getCompaniesNeedingNotification(2024);

    expect($needsNotification)->toHaveCount(1);
    expect($needsNotification->first()->user_id)->toBe(TestCase::USER_UUID_1);
});

it('can calculate threshold percentage', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'      => TestCase::USER_UUID_1,
        'fiscal_year'  => 2024,
        'total_amount' => cents(750000), // €7,500 against default €10,000 threshold
    ]);

    expect($threshold->getThresholdPercentage())->toBe(75.0);

    $threshold->update(['total_amount' => cents(0)]);
    expect($threshold->getThresholdPercentage())->toBe(0.0);
});

it('can get remaining threshold amount', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'      => TestCase::USER_UUID_1,
        'fiscal_year'  => 2024,
        'total_amount' => cents(750000),
    ]);

    expect($threshold->getRemainingThresholdAmount()->isEqualTo(cents(250000)))->toBeTrue();

    $threshold->update(['total_amount' => cents(1200000)]);
    expect($threshold->getRemainingThresholdAmount()->isZero())->toBeTrue();
});

it('can get default threshold from config', function () {
    config(['larabill.destination_vat.default_threshold' => 1500000]); // €15,000.00 in base 100

    expect(EuSalesThreshold::getDefaultThreshold())->toBe(1500000);
});

it('can check if company needs threshold monitoring', function () {
    expect(EuSalesThreshold::companyNeedsThresholdMonitoring('company-new', 2024))->toBeTrue();

    EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_1,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(500000),
        'threshold_exceeded' => false,
    ]);

    expect(EuSalesThreshold::companyNeedsThresholdMonitoring(TestCase::USER_UUID_1, 2024))->toBeFalse();

    EuSalesThreshold::create([
        'user_id'            => TestCase::USER_UUID_2,
        'fiscal_year'        => 2024,
        'total_amount'       => cents(1200000),
        'threshold_exceeded' => true,
    ]);

    expect(EuSalesThreshold::companyNeedsThresholdMonitoring(TestCase::USER_UUID_2, 2024))->toBeFalse();
});

it('can get fiscal year dates', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'     => TestCase::USER_UUID_1,
        'fiscal_year' => 2024,
    ]);

    $startDate = $threshold->getFiscalYearStartDate();
    $endDate   = $threshold->getFiscalYearEndDate();

    expect($startDate)->toBeInstanceOf(Carbon::class);
    expect($endDate)->toBeInstanceOf(Carbon::class);
    expect($startDate->format('Y-m-d'))->toBe('2024-01-01');
    expect($endDate->format('Y-m-d'))->toBe('2024-12-31');
});

it('can check if date is within fiscal year', function () {
    $threshold = EuSalesThreshold::create([
        'user_id'     => TestCase::USER_UUID_1,
        'fiscal_year' => 2024,
    ]);

    expect($threshold->isWithinFiscalYear(Carbon::create(2024, 6, 15)))->toBeTrue();
    expect($threshold->isWithinFiscalYear(Carbon::create(2023, 12, 31)))->toBeFalse();
    expect($threshold->isWithinFiscalYear(Carbon::create(2025, 1, 1)))->toBeFalse();
});

it('can get top countries by sales', function () {
    EuSalesThreshold::create([
        'user_id'              => TestCase::USER_UUID_1,
        'fiscal_year'          => 2024,
        'total_amount'         => cents(1500000),
        'breakdown_by_country' => [
            'DE' => 800000,
            'FR' => 500000,
            'IT' => 200000,
        ],
    ]);

    $topCountries = EuSalesThreshold::getTopCountriesBySales(2024, 2);

    expect($topCountries)->toHaveCount(2);
    expect($topCountries[0]['country'])->toBe('DE');
    expect($topCountries[0]['amount']->isEqualTo(cents(800000)))->toBeTrue();
    expect($topCountries[1]['country'])->toBe('FR');
    expect($topCountries[1]['amount']->isEqualTo(cents(500000)))->toBeTrue();
});

it('compares total against threshold in the same base-100 unit (euro-vs-cents bug regression)', function () {
    // Regression for AID-240: the legacy model stored total_amount in cents
    // (fed by taxable_amount->unscaledValue()) but seeded threshold_amount from a
    // euro-denominated config default (10000.0), so €80 of sales falsely exceeded
    // a €10,000 threshold. With everything in base-100 minor units this holds.
    $threshold = EuSalesThreshold::getOrCreateForUser(TestCase::USER_UUID_1, 2024);

    // Default threshold is €10,000.00 (1,000,000 minor units).
    expect($threshold->threshold_amount->isEqualTo(cents(1000000)))->toBeTrue();

    // €8,000 of sales must NOT exceed the €10,000 threshold.
    $threshold->addAmount(cents(800000));
    expect($threshold->total_amount->isEqualTo(cents(800000)))->toBeTrue();
    expect($threshold->checkThreshold())->toBeFalse();
    expect($threshold->fresh()->threshold_exceeded)->toBeFalse();

    // A further €3,000 (total €11,000) crosses the threshold.
    $threshold->addAmount(cents(300000));
    expect($threshold->total_amount->isEqualTo(cents(1100000)))->toBeTrue();
    expect($threshold->checkThreshold())->toBeTrue();
    expect($threshold->fresh()->threshold_exceeded)->toBeTrue();
});

it('can get sales growth by company', function () {
    EuSalesThreshold::create([
        'user_id'      => TestCase::USER_UUID_1,
        'fiscal_year'  => 2023,
        'total_amount' => cents(800000),
    ]);

    EuSalesThreshold::create([
        'user_id'      => TestCase::USER_UUID_1,
        'fiscal_year'  => 2024,
        'total_amount' => cents(1200000),
    ]);

    $growth = EuSalesThreshold::getSalesGrowthByCompany(TestCase::USER_UUID_1, 2024);

    expect($growth['current_year'])->toBe(2024);
    expect($growth['current_amount']->isEqualTo(cents(1200000)))->toBeTrue();
    expect($growth['previous_year'])->toBe(2023);
    expect($growth['previous_amount']->isEqualTo(cents(800000)))->toBeTrue();
    expect($growth['growth_amount']->isEqualTo(cents(400000)))->toBeTrue();
    expect($growth['growth_percentage'])->toBe(50.0);
});
