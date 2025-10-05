<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\{CompanyFiscalConfig, CountryVatRate, EuSalesThreshold, VatCategory};
use AichaDigital\Larabill\Services\DestinationVatService;

beforeEach(function () {
    CompanyFiscalConfig::truncate();
    EuSalesThreshold::truncate();
    CountryVatRate::truncate();
    VatCategory::truncate();

    config(['larabill.destination_vat.default_threshold' => 10000]);
    config(['larabill.destination_vat.currency' => 'EUR']);
    config(['larabill.destination_vat.fiscal_year_start' => '01-01']);
    config(['larabill.destination_vat.auto_apply_destination' => true]);
});

it('can determine if destination VAT should be applied', function () {
    $service = new DestinationVatService;

    // Company with destination VAT enabled
    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => true,
        'auto_apply_destination'  => false,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 5000.00,
    ]);

    $shouldApply = $service->shouldApplyDestinationVat('company-123', 2024);

    expect($shouldApply)->toBeTrue();
});

it('can determine if destination VAT should not be applied', function () {
    $service = new DestinationVatService;

    // Company with destination VAT disabled and threshold not exceeded
    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => false,
        'auto_apply_destination'  => true,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 5000.00,
    ]);

    $shouldApply = $service->shouldApplyDestinationVat('company-123', 2024);

    expect($shouldApply)->toBeFalse();
});

it('can apply destination VAT automatically when threshold is exceeded', function () {
    $service = new DestinationVatService;

    // Company with auto-apply enabled and threshold exceeded
    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => false,
        'auto_apply_destination'  => true,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 12000.00,
    ]);

    $shouldApply = $service->shouldApplyDestinationVat('company-123', 2024);

    expect($shouldApply)->toBeTrue();
});

it('can calculate VAT rate for destination country', function () {
    $service = new DestinationVatService;

    // Create country VAT rates
    CountryVatRate::create([
        'country_code'  => 'ES',
        'country_name'  => 'Spain',
        'standard_rate' => 21.00,
        'reduced_rates' => ['general' => 10.00],
        'is_active'     => true,
    ]);

    CountryVatRate::create([
        'country_code'  => 'FR',
        'country_name'  => 'France',
        'standard_rate' => 20.00,
        'reduced_rates' => ['general' => 5.50],
        'is_active'     => true,
    ]);

    $esRate = $service->getVatRateForCountry('ES');
    $frRate = $service->getVatRateForCountry('FR');

    expect($esRate)->toBe(21.00);
    expect($frRate)->toBe(20.00);
});

it('can calculate VAT rate for specific category', function () {
    $service = new DestinationVatService;

    // Create VAT categories
    VatCategory::create([
        'name'          => 'Standard Goods',
        'country_code'  => 'ES',
        'vat_rate'      => 21.00,
        'category_type' => VatCategory::CATEGORY_TYPE_STANDARD,
        'is_active'     => true,
    ]);

    VatCategory::create([
        'name'          => 'Reduced Goods',
        'country_code'  => 'ES',
        'vat_rate'      => 10.00,
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'     => true,
    ]);

    $standardRate = $service->getVatRateForCategory('ES', 'Standard Goods');
    $reducedRate  = $service->getVatRateForCategory('ES', 'Reduced Goods');

    expect($standardRate)->toBe(21.00);
    expect($reducedRate)->toBe(10.00);
});

it('can calculate VAT amount for destination country', function () {
    $service = new DestinationVatService;

    CountryVatRate::create([
        'country_code'  => 'ES',
        'country_name'  => 'Spain',
        'standard_rate' => 21.00,
        'is_active'     => true,
    ]);

    $vatAmount = $service->calculateVatAmount(1000.00, 'ES');

    expect($vatAmount)->toBe(210.00);
});

it('can calculate VAT amount for specific category', function () {
    $service = new DestinationVatService;

    VatCategory::create([
        'name'          => 'Reduced Goods',
        'country_code'  => 'ES',
        'vat_rate'      => 10.00,
        'category_type' => VatCategory::CATEGORY_TYPE_REDUCED,
        'is_active'     => true,
    ]);

    $vatAmount = $service->calculateVatAmount(1000.00, 'ES', 'Reduced Goods');

    expect($vatAmount)->toBe(100.00);
});

it('can update EU sales amount', function () {
    $service = new DestinationVatService;

    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 5000.00,
    ]);

    $updated = $service->updateEuSalesAmount('company-123', 2024, 'ES', 2000.00);

    expect($updated->current_eu_sales_amount)->toBe(7000);
    expect($updated->checkThreshold())->toBeFalse();
});

it('can update EU sales amount and exceed threshold', function () {
    $service = new DestinationVatService;

    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 5000.00,
        'auto_apply_destination'  => true,
    ]);

    $updated = $service->updateEuSalesAmount('company-123', 2024, 'ES', 6000.00);

    expect($updated->current_eu_sales_amount)->toBe(11000);
    expect($updated->checkThreshold())->toBeTrue();
    expect($updated->apply_destination_iva)->toBeTrue();
});

it('can get EU sales threshold status', function () {
    $service = new DestinationVatService;

    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 7500.00,
    ]);

    $status = $service->getEuSalesThresholdStatus('company-123', 2024);

    expect($status)->toBeArray();
    expect($status['threshold'])->toBe(10000.00);
    expect($status['current_amount'])->toBe(7500.00);
    expect($status['percentage'])->toBe(75.0);
    expect($status['remaining'])->toBe(2500.00);
    expect($status['exceeded'])->toBeFalse();
});

it('can get EU sales breakdown by country', function () {
    $service = new DestinationVatService;

    EuSalesThreshold::create([
        'company_id'           => 'company-123',
        'fiscal_year'          => 2024,
        'total_amount'         => 15000.00,
        'breakdown_by_country' => [
            'ES' => 8000.00,
            'FR' => 5000.00,
            'DE' => 2000.00,
        ],
    ]);

    $breakdown = $service->getEuSalesBreakdownByCountry('company-123', 2024);

    expect($breakdown)->toBeArray();
    expect($breakdown['total'])->toBe(15000.00);
    expect($breakdown['countries']['ES'])->toBe(8000.00);
    expect($breakdown['countries']['FR'])->toBe(5000.00);
    expect($breakdown['countries']['DE'])->toBe(2000.00);
});

it('can get companies exceeding threshold', function () {
    $service = new DestinationVatService;

    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 12000.00,
        'threshold_exceeded'      => true,
    ]);

    CompanyFiscalConfig::create([
        'company_id'              => 'company-456',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 5000.00,
        'threshold_exceeded'      => false,
    ]);

    $exceededCompanies = $service->getCompaniesExceedingThreshold(2024);

    expect($exceededCompanies)->toHaveCount(1);
    expect($exceededCompanies->first()->company_id)->toBe('company-123');
});

it('can get companies needing notification', function () {
    $service = new DestinationVatService;

    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 12000.00,
        'threshold_exceeded'      => true,
        'notification_sent'       => false,
    ]);

    CompanyFiscalConfig::create([
        'company_id'              => 'company-456',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 12000.00,
        'threshold_exceeded'      => true,
        'notification_sent'       => true,
    ]);

    $needsNotification = $service->getCompaniesNeedingNotification(2024);

    expect($needsNotification)->toHaveCount(1);
    expect($needsNotification->first()->company_id)->toBe('company-123');
});

it('can send threshold exceeded notification', function () {
    $service = new DestinationVatService;

    $config = CompanyFiscalConfig::create([
        'company_id'                   => 'company-123',
        'fiscal_year'                  => 2024,
        'eu_sales_threshold'           => 10000.00,
        'current_eu_sales_amount'      => 12000.00,
        'threshold_exceeded'           => true,
        'notification_sent'            => false,
        'threshold_notification_email' => 'admin@company.com',
    ]);

    $sent = $service->sendThresholdExceededNotification($config);

    expect($sent)->toBeTrue();
    expect($config->fresh()->notification_sent)->toBeTrue();
});

it('can reset EU sales for new fiscal year', function () {
    $service = new DestinationVatService;

    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 15000.00,
        'threshold_exceeded'      => true,
        'notification_sent'       => true,
    ]);

    EuSalesThreshold::create([
        'company_id'           => 'company-123',
        'fiscal_year'          => 2024,
        'total_amount'         => 15000.00,
        'threshold_exceeded'   => true,
        'notification_sent'    => true,
        'breakdown_by_country' => [
            'ES' => 8000.00,
            'FR' => 7000.00,
        ],
    ]);

    $reset = $service->resetEuSalesForNewFiscalYear('company-123', 2025);

    expect($reset)->toBeTrue();

    $newConfig = CompanyFiscalConfig::findByCompanyAndYear('company-123', 2025);
    expect($newConfig->current_eu_sales_amount)->toBe(0);
    expect($newConfig->threshold_exceeded)->toBeFalse();
    expect($newConfig->notification_sent)->toBeFalse();

    $newThreshold = EuSalesThreshold::findByCompanyAndYear('company-123', 2025);
    expect($newThreshold->total_amount)->toBe(0.00);
    expect($newThreshold->threshold_exceeded)->toBeFalse();
    expect($newThreshold->notification_sent)->toBeFalse();
    expect($newThreshold->breakdown_by_country)->toBeEmpty();
});

it('can get destination VAT statistics', function () {
    $service = new DestinationVatService;

    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => true,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 12000.00,
        'threshold_exceeded'      => true,
    ]);

    CompanyFiscalConfig::create([
        'company_id'              => 'company-456',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => false,
        'eu_sales_threshold'      => 10000.00,
        'current_eu_sales_amount' => 5000.00,
        'threshold_exceeded'      => false,
    ]);

    $stats = $service->getDestinationVatStatistics(2024);

    expect($stats)->toBeArray();
    expect($stats['total_companies'])->toBe(2);
    expect($stats['companies_using_destination_vat'])->toBe(1);
    expect($stats['companies_exceeding_threshold'])->toBe(1);
    expect($stats['total_eu_sales'])->toBe(17000.00);
    expect($stats['average_threshold_percentage'])->toBe(85.0);
});

it('can validate destination country', function () {
    $service = new DestinationVatService;

    expect($service->isValidDestinationCountry('ES'))->toBeTrue();
    expect($service->isValidDestinationCountry('FR'))->toBeTrue();
    expect($service->isValidDestinationCountry('DE'))->toBeTrue();
    expect($service->isValidDestinationCountry('XX'))->toBeFalse();
    expect($service->isValidDestinationCountry(''))->toBeFalse();
});

it('can get available destination countries', function () {
    $service = new DestinationVatService;

    CountryVatRate::create([
        'country_code'  => 'ES',
        'country_name'  => 'Spain',
        'standard_rate' => 21.00,
        'is_active'     => true,
    ]);

    CountryVatRate::create([
        'country_code'  => 'FR',
        'country_name'  => 'France',
        'standard_rate' => 20.00,
        'is_active'     => true,
    ]);

    CountryVatRate::create([
        'country_code'  => 'DE',
        'country_name'  => 'Germany',
        'standard_rate' => 19.00,
        'is_active'     => false,
    ]);

    $countries = $service->getAvailableDestinationCountries();

    expect($countries)->toHaveCount(2);
    expect($countries)->toContain('ES');
    expect($countries)->toContain('FR');
    expect($countries)->not->toContain('DE');
});

it('can get VAT rate comparison between countries', function () {
    $service = new DestinationVatService;

    CountryVatRate::create([
        'country_code'  => 'ES',
        'country_name'  => 'Spain',
        'standard_rate' => 21.00,
        'is_active'     => true,
    ]);

    CountryVatRate::create([
        'country_code'  => 'FR',
        'country_name'  => 'France',
        'standard_rate' => 20.00,
        'is_active'     => true,
    ]);

    CountryVatRate::create([
        'country_code'  => 'DE',
        'country_name'  => 'Germany',
        'standard_rate' => 19.00,
        'is_active'     => true,
    ]);

    $comparison = $service->getVatRateComparison(['ES', 'FR', 'DE']);

    expect($comparison)->toBeArray();
    expect($comparison['ES'])->toBe(21.00);
    expect($comparison['FR'])->toBe(20.00);
    expect($comparison['DE'])->toBe(19.00);
    expect($comparison['average'])->toBe(20.00);
    expect($comparison['highest'])->toBe(21.00);
    expect($comparison['lowest'])->toBe(19.00);
});

it('can calculate VAT savings for different countries', function () {
    $service = new DestinationVatService;

    CountryVatRate::create([
        'country_code'  => 'ES',
        'country_name'  => 'Spain',
        'standard_rate' => 21.00,
        'is_active'     => true,
    ]);

    CountryVatRate::create([
        'country_code'  => 'FR',
        'country_name'  => 'France',
        'standard_rate' => 20.00,
        'is_active'     => true,
    ]);

    $savings = $service->calculateVatSavings(1000.00, 'ES', 'FR');

    expect($savings)->toBeArray();
    expect($savings['amount'])->toBe(10.00); // 210 - 200
    expect($savings['percentage'])->toBe(1.0); // 1% difference
    expect($savings['from_country'])->toBe('ES');
    expect($savings['to_country'])->toBe('FR');
});

it('can get fiscal year information', function () {
    $service     = new DestinationVatService;
    $currentYear = (int) now()->format('Y');

    $fiscalYear = $service->getFiscalYearInfo($currentYear);

    expect($fiscalYear)->toBeArray();
    expect($fiscalYear['year'])->toBe($currentYear);
    expect($fiscalYear['start_date'])->toBe($currentYear.'-01-01');
    expect($fiscalYear['end_date'])->toBe($currentYear.'-12-31');
    expect($fiscalYear['is_current'])->toBeTrue();
});

it('can check if date is within fiscal year', function () {
    $service     = new DestinationVatService;
    $currentYear = (int) now()->format('Y');

    expect($service->isWithinFiscalYear($currentYear, now()))->toBeTrue();
    expect($service->isWithinFiscalYear($currentYear, now()->subYear()))->toBeFalse();
    expect($service->isWithinFiscalYear($currentYear, now()->addYear()))->toBeFalse();
});

it('can get service configuration', function () {
    $service = new DestinationVatService;

    $config = $service->getConfiguration();

    expect($config)->toBeArray();
    expect($config['default_threshold'])->toBe(10000);
    expect($config['currency'])->toBe('EUR');
    expect($config['fiscal_year_start'])->toBe('01-01');
    expect($config['auto_apply_destination'])->toBeTrue();
});

it('can update service configuration', function () {
    $service = new DestinationVatService;

    $newConfig = [
        'default_threshold'      => 15000,
        'currency'               => 'USD',
        'fiscal_year_start'      => '04-01',
        'auto_apply_destination' => false,
    ];

    $service->updateConfiguration($newConfig);

    expect(config('larabill.destination_vat.default_threshold'))->toBe(15000);
    expect(config('larabill.destination_vat.currency'))->toBe('USD');
    expect(config('larabill.destination_vat.fiscal_year_start'))->toBe('04-01');
    expect(config('larabill.destination_vat.auto_apply_destination'))->toBeFalse();
});

it('can handle service errors gracefully', function () {
    $service = new DestinationVatService;

    expect(fn () => $service->shouldApplyDestinationVat('nonexistent', 2024))
        ->not->toThrow(Exception::class);

    $result = $service->shouldApplyDestinationVat('nonexistent', 2024);
    expect($result)->toBeFalse();
});
