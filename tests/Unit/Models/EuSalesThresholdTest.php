<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\EuSalesThreshold;
use Carbon\Carbon;

it('can create an EU sales threshold', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'threshold_exceeded' => false,
        'notification_sent' => false,
        'breakdown_by_country' => [
            'DE' => 2000.00,
            'FR' => 3000.00,
        ],
    ]);

    expect($threshold)->toBeInstanceOf(EuSalesThreshold::class);
    expect($threshold->company_id)->toBe('company-123');
    expect($threshold->fiscal_year)->toBe(2024);
    expect($threshold->total_amount)->toBe(5000.00);
    expect($threshold->threshold_exceeded)->toBeFalse();
    expect($threshold->breakdown_by_country['DE'])->toBe(2000.00);
});

it('can find EU sales threshold by company and year', function () {
    EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
    ]);

    $found = EuSalesThreshold::findByCompanyAndYear('company-123', 2024);

    expect($found)->not->toBeNull();
    expect($found->company_id)->toBe('company-123');
    expect($found->fiscal_year)->toBe(2024);
});

it('can get or create EU sales threshold for company', function () {
    // First call should create
    $threshold1 = EuSalesThreshold::getOrCreateForCompany('company-456', 2024);

    expect($threshold1)->toBeInstanceOf(EuSalesThreshold::class);
    expect($threshold1->company_id)->toBe('company-456');
    expect($threshold1->fiscal_year)->toBe(2024);
    expect($threshold1->total_amount)->toBe(0.00);

    // Second call should return existing
    $threshold2 = EuSalesThreshold::getOrCreateForCompany('company-456', 2024);

    expect($threshold2->id)->toBe($threshold1->id);
});

it('can add sales amount', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'breakdown_by_country' => [
            'DE' => 2000.00,
            'FR' => 3000.00,
        ],
    ]);

    $updated = $threshold->addSalesAmount('IT', 1000.00);

    expect($updated->total_amount)->toBe(6000.00);
    expect($updated->breakdown_by_country['IT'])->toBe(1000.00);
    expect($updated->breakdown_by_country['DE'])->toBe(2000.00);
    expect($updated->breakdown_by_country['FR'])->toBe(3000.00);
});

it('can update existing country amount', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'breakdown_by_country' => [
            'DE' => 2000.00,
            'FR' => 3000.00,
        ],
    ]);

    $updated = $threshold->addSalesAmount('DE', 500.00);

    expect($updated->total_amount)->toBe(5500.00);
    expect($updated->breakdown_by_country['DE'])->toBe(2500.00);
    expect($updated->breakdown_by_country['FR'])->toBe(3000.00);
});

it('can check if threshold is exceeded', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'threshold_exceeded' => false,
    ]);

    expect($threshold->isThresholdExceeded())->toBeFalse();

    // Update to exceed threshold
    $threshold->update(['total_amount' => 12000.00, 'threshold_exceeded' => true]);

    expect($threshold->isThresholdExceeded())->toBeTrue();
});

it('can mark threshold as exceeded', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 12000.00,
        'threshold_exceeded' => false,
    ]);

    $marked = $threshold->markThresholdExceeded();

    expect($marked->threshold_exceeded)->toBeTrue();
    expect($marked->exceeded_at)->not->toBeNull();
    expect($marked->exceeded_at)->toBeInstanceOf(Carbon::class);
});

it('can mark notification as sent', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'notification_sent' => false,
    ]);

    $marked = $threshold->markNotificationSent();

    expect($marked->notification_sent)->toBeTrue();
});

it('can get sales amount by country', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'breakdown_by_country' => [
            'DE' => 2000.00,
            'FR' => 3000.00,
        ],
    ]);

    expect($threshold->getSalesAmountByCountry('DE'))->toBe(2000.00);
    expect($threshold->getSalesAmountByCountry('FR'))->toBe(3000.00);
    expect($threshold->getSalesAmountByCountry('IT'))->toBe(0.00);
});

it('can get all countries with sales', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'breakdown_by_country' => [
            'DE' => 2000.00,
            'FR' => 3000.00,
        ],
    ]);

    $countries = $threshold->getCountriesWithSales();

    expect($countries)->toHaveCount(2);
    expect($countries)->toContain('DE');
    expect($countries)->toContain('FR');
});

it('can reset sales amounts', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 12000.00,
        'threshold_exceeded' => true,
        'exceeded_at' => now(),
        'notification_sent' => true,
        'breakdown_by_country' => [
            'DE' => 2000.00,
            'FR' => 3000.00,
            'IT' => 7000.00,
        ],
    ]);

    $reset = $threshold->resetSalesAmounts();

    expect($reset->total_amount)->toBe(0.00);
    expect($reset->threshold_exceeded)->toBeFalse();
    expect($reset->exceeded_at)->toBeNull();
    expect($reset->notification_sent)->toBeFalse();
    expect($reset->breakdown_by_country)->toBeEmpty();
});

it('can use scopes correctly', function () {
    EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 12000.00,
        'threshold_exceeded' => true,
        'notification_sent' => true,
    ]);

    EuSalesThreshold::create([
        'company_id' => 'company-456',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'threshold_exceeded' => false,
        'notification_sent' => false,
    ]);

    EuSalesThreshold::create([
        'company_id' => 'company-789',
        'fiscal_year' => 2023,
        'total_amount' => 15000.00,
        'threshold_exceeded' => true,
        'notification_sent' => false,
    ]);

    // Test by fiscal year scope
    $thresholds2024 = EuSalesThreshold::byFiscalYear(2024)->get();
    expect($thresholds2024)->toHaveCount(2);

    // Test threshold exceeded scope
    $exceededThresholds = EuSalesThreshold::thresholdExceeded()->get();
    expect($exceededThresholds)->toHaveCount(2);

    // Test by company scope
    $companyThresholds = EuSalesThreshold::byCompany('company-123')->get();
    expect($companyThresholds)->toHaveCount(1);

    // Test needs notification scope
    $needsNotificationThresholds = EuSalesThreshold::needsNotification()->get();
    expect($needsNotificationThresholds)->toHaveCount(1); // Only company-789
});

it('can get threshold statistics', function () {
    EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 12000.00,
        'threshold_exceeded' => true,
        'breakdown_by_country' => [
            'DE' => 5000.00,
            'FR' => 7000.00,
        ],
    ]);

    EuSalesThreshold::create([
        'company_id' => 'company-456',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'threshold_exceeded' => false,
        'breakdown_by_country' => [
            'IT' => 5000.00,
        ],
    ]);

    $stats = EuSalesThreshold::getThresholdStatistics(2024);

    expect($stats['total_companies'])->toBe(2);
    expect($stats['exceeded_companies'])->toBe(1);
    expect($stats['total_sales_amount'])->toBe(17000.00);
    expect($stats['breakdown_by_country']['DE'])->toBe(5000.00);
    expect($stats['breakdown_by_country']['FR'])->toBe(7000.00);
    expect($stats['breakdown_by_country']['IT'])->toBe(5000.00);
});

it('can get companies exceeding threshold', function () {
    EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 12000.00,
        'threshold_exceeded' => true,
    ]);

    EuSalesThreshold::create([
        'company_id' => 'company-456',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'threshold_exceeded' => false,
    ]);

    $exceededCompanies = EuSalesThreshold::getCompaniesExceedingThreshold(2024);

    expect($exceededCompanies)->toHaveCount(1);
    expect($exceededCompanies->first()->company_id)->toBe('company-123');
});

it('can get companies needing notification', function () {
    EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 12000.00,
        'threshold_exceeded' => true,
        'notification_sent' => false,
    ]);

    EuSalesThreshold::create([
        'company_id' => 'company-456',
        'fiscal_year' => 2024,
        'total_amount' => 12000.00,
        'threshold_exceeded' => true,
        'notification_sent' => true,
    ]);

    $needsNotification = EuSalesThreshold::getCompaniesNeedingNotification(2024);

    expect($needsNotification)->toHaveCount(1);
    expect($needsNotification->first()->company_id)->toBe('company-123');
});

it('can calculate threshold percentage', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 7500.00,
    ]);

    // Assuming default threshold is 10000
    expect($threshold->getThresholdPercentage())->toBe(75.0);

    // Test with zero threshold
    $threshold->update(['total_amount' => 0]);
    expect($threshold->getThresholdPercentage())->toBe(0);
});

it('can get remaining threshold amount', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 7500.00,
    ]);

    // Assuming default threshold is 10000
    expect($threshold->getRemainingThresholdAmount())->toBe(2500.00);

    // Test when threshold is exceeded
    $threshold->update(['total_amount' => 12000.00]);
    expect($threshold->getRemainingThresholdAmount())->toBe(0);
});

it('can get default threshold from config', function () {
    config(['larabill.destination_vat.default_threshold' => 15000]);

    expect(EuSalesThreshold::getDefaultThreshold())->toBe(15000);
});

it('can check if company needs threshold monitoring', function () {
    // Company with no threshold record
    expect(EuSalesThreshold::companyNeedsThresholdMonitoring('company-new', 2024))->toBeTrue();

    // Company with threshold record but not exceeded
    EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 5000.00,
        'threshold_exceeded' => false,
    ]);

    expect(EuSalesThreshold::companyNeedsThresholdMonitoring('company-123', 2024))->toBeTrue();

    // Company with threshold exceeded
    EuSalesThreshold::create([
        'company_id' => 'company-456',
        'fiscal_year' => 2024,
        'total_amount' => 12000.00,
        'threshold_exceeded' => true,
    ]);

    expect(EuSalesThreshold::companyNeedsThresholdMonitoring('company-456', 2024))->toBeFalse();
});

it('can get fiscal year dates', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
    ]);

    $startDate = $threshold->getFiscalYearStartDate();
    $endDate = $threshold->getFiscalYearEndDate();

    expect($startDate)->toBeInstanceOf(Carbon::class);
    expect($endDate)->toBeInstanceOf(Carbon::class);
    expect($startDate->format('Y-m-d'))->toBe('2024-01-01');
    expect($endDate->format('Y-m-d'))->toBe('2024-12-31');
});

it('can check if date is within fiscal year', function () {
    $threshold = EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
    ]);

    expect($threshold->isWithinFiscalYear(Carbon::create(2024, 6, 15)))->toBeTrue();
    expect($threshold->isWithinFiscalYear(Carbon::create(2023, 12, 31)))->toBeFalse();
    expect($threshold->isWithinFiscalYear(Carbon::create(2025, 1, 1)))->toBeFalse();
});

it('can get top countries by sales', function () {
    EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 15000.00,
        'breakdown_by_country' => [
            'DE' => 8000.00,
            'FR' => 5000.00,
            'IT' => 2000.00,
        ],
    ]);

    $topCountries = EuSalesThreshold::getTopCountriesBySales(2024, 2);

    expect($topCountries)->toHaveCount(2);
    expect($topCountries[0]['country'])->toBe('DE');
    expect($topCountries[0]['amount'])->toBe(8000.00);
    expect($topCountries[1]['country'])->toBe('FR');
    expect($topCountries[1]['amount'])->toBe(5000.00);
});

it('can get sales growth by company', function () {
    // Create previous year data
    EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2023,
        'total_amount' => 8000.00,
    ]);

    // Create current year data
    EuSalesThreshold::create([
        'company_id' => 'company-123',
        'fiscal_year' => 2024,
        'total_amount' => 12000.00,
    ]);

    $growth = EuSalesThreshold::getSalesGrowthByCompany('company-123', 2024);

    expect($growth['current_year'])->toBe(2024);
    expect($growth['current_amount'])->toBe(12000.00);
    expect($growth['previous_year'])->toBe(2023);
    expect($growth['previous_amount'])->toBe(8000.00);
    expect($growth['growth_amount'])->toBe(4000.00);
    expect($growth['growth_percentage'])->toBe(50.0);
});
