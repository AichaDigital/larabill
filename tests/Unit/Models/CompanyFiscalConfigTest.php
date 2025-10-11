<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use Carbon\Carbon;

it('can create a company fiscal config', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => false,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
        'auto_apply_destination'  => true,
        'currency'                => 'EUR',
        'fiscal_year_start'       => '01-01',
    ]);

    expect($config)->toBeInstanceOf(CompanyFiscalConfig::class);
    expect($config->company_id)->toBe('company-123');
    expect($config->fiscal_year)->toBe(2024);
    expect($config->eu_sales_threshold)->toBe(10000.0); // Base100 cast returns float
    expect($config->current_eu_sales_amount)->toBe(5000.0); // Base100 cast returns float
});

it('can find fiscal config by company and year', function () {
    CompanyFiscalConfig::create([
        'company_id'         => 'company-123',
        'fiscal_year'        => 2024,
        'eu_sales_threshold' => 10000.0, // €10,000.00 in decimal format
    ]);

    $found = CompanyFiscalConfig::findByCompanyAndYear('company-123', 2024);

    expect($found)->not->toBeNull();
    expect($found->company_id)->toBe('company-123');
    expect($found->fiscal_year)->toBe(2024);
});

it('can get or create fiscal config for company', function () {
    // First call should create
    $config1 = CompanyFiscalConfig::getOrCreateForCompany('company-456', 2024);

    expect($config1)->toBeInstanceOf(CompanyFiscalConfig::class);
    expect($config1->company_id)->toBe('company-456');
    expect($config1->fiscal_year)->toBe(2024);

    // Second call should return existing
    $config2 = CompanyFiscalConfig::getOrCreateForCompany('company-456', 2024);

    expect($config2->id)->toBe($config1->id);
});

it('can check if threshold has been exceeded', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
    ]);

    expect($config->checkThreshold())->toBeFalse();

    // Update to exceed threshold
    $config->update(['current_eu_sales_amount' => 12000.0]); // Base100 cast: €12,000.00

    expect($config->checkThreshold())->toBeTrue()
        ->and($config->threshold_exceeded_at)->not->toBeNull();
});

it('can update EU sales amount', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
    ]);

    $updated = $config->updateEuSales(300.00); // €300.00

    expect($updated->current_eu_sales_amount)->toBe(5300.0) // €5,300.00 (Base100 returns float)
        ->and($updated->checkThreshold())->toBeFalse();

    // Update to exceed threshold
    $updated->updateEuSales(5000.00); // €5,000.00

    expect($updated->current_eu_sales_amount)->toBe(10300.0) // €10,300.00 (Base100 returns float)
        ->and($updated->checkThreshold())->toBeTrue();
});

it('can reset EU sales amount', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 15000.0, // Base100 cast: €15,000.00
        'threshold_exceeded_at'   => now(),
        'notification_sent'       => true,
    ]);

    $reset = $config->resetEuSales();

    expect($reset->current_eu_sales_amount)->toBe(0.0) // Base100 returns float
        ->and($reset->threshold_exceeded_at)->toBeNull()
        ->and($reset->notification_sent)->toBeFalse();
});

it('can check if destination VAT should be applied', function () {
    // Test with apply_destination_iva = true
    $config1 = CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => true,
        'auto_apply_destination'  => false,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
    ]);

    expect($config1->shouldApplyDestinationVat())->toBeTrue();

    // Test with auto_apply_destination = true and threshold exceeded
    $config2 = CompanyFiscalConfig::create([
        'company_id'              => 'company-456',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => false,
        'auto_apply_destination'  => true,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 12000.0, // Base100 cast: €12,000.00
    ]);

    expect($config2->shouldApplyDestinationVat())->toBeTrue();

    // Test with auto_apply_destination = true but threshold not exceeded
    $config3 = CompanyFiscalConfig::create([
        'company_id'              => 'company-789',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => false,
        'auto_apply_destination'  => true,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
    ]);

    expect($config3->shouldApplyDestinationVat())->toBeFalse();
});

it('can enable destination VAT', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => false,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
    ]);

    $enabled = $config->enableDestinationVat();

    expect($enabled->apply_destination_iva)->toBeTrue()
        ->and($enabled->threshold_exceeded_at)->toBeNull();
    // Should not set if not exceeded
});

it('can disable destination VAT', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => true,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
    ]);

    $disabled = $config->disableDestinationVat();

    expect($disabled->apply_destination_iva)->toBeFalse();
});

it('can mark notification as sent', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'        => 'company-123',
        'fiscal_year'       => 2024,
        'notification_sent' => false,
    ]);

    $marked = $config->markNotificationSent();

    expect($marked->notification_sent)->toBeTrue();
});

it('can use scopes correctly', function () {
    CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => true,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 12000.0, // Base100 cast: €12,000.00
    ]);

    CompanyFiscalConfig::create([
        'company_id'              => 'company-456',
        'fiscal_year'             => 2024,
        'apply_destination_iva'   => false,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 5000.0, // Base100 cast: €5,000.00
    ]);

    CompanyFiscalConfig::create([
        'company_id'              => 'company-789',
        'fiscal_year'             => 2023,
        'apply_destination_iva'   => true,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 15000.0, // Base100 cast: €15,000.00
    ]);

    // Test by fiscal year scope
    $configs2024 = CompanyFiscalConfig::byFiscalYear(2024)->get();
    expect($configs2024)->toHaveCount(2);

    // Test threshold exceeded scope
    $exceededConfigs = CompanyFiscalConfig::thresholdExceeded()->get();
    expect($exceededConfigs)->toHaveCount(2);

    // Test by company scope
    $companyConfigs = CompanyFiscalConfig::byCompany('company-123')->get();
    expect($companyConfigs)->toHaveCount(1);

    // Test apply destination VAT scope
    $destinationVatConfigs = CompanyFiscalConfig::applyDestinationVat()->get();
    expect($destinationVatConfigs)->toHaveCount(2);

    // Test auto apply enabled scope
    $autoApplyConfigs = CompanyFiscalConfig::autoApplyEnabled()->get();
    expect($autoApplyConfigs)->toHaveCount(3); // All have auto_apply_destination = true by default

    // Test needs notification scope
    $needsNotificationConfigs = CompanyFiscalConfig::needsNotification()->get();
    expect($needsNotificationConfigs)->toHaveCount(2); // Two that exceeded threshold
});

it('can get fiscal year dates', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'        => 'company-123',
        'fiscal_year'       => 2024,
        'fiscal_year_start' => '01-01',
    ]);

    $startDate = $config->getFiscalYearStartDate();
    $endDate   = $config->getFiscalYearEndDate();

    expect($startDate)->toBeInstanceOf(Carbon::class);
    expect($endDate)->toBeInstanceOf(Carbon::class);
    expect($startDate->format('Y-m-d'))->toBe('2024-01-01');
    expect($endDate->format('Y-m-d'))->toBe('2024-12-31');
});

it('can check if date is within fiscal year', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'        => 'company-123',
        'fiscal_year'       => 2024,
        'fiscal_year_start' => '01-01',
    ]);

    expect($config->isWithinFiscalYear(Carbon::create(2024, 6, 15)))->toBeTrue();
    expect($config->isWithinFiscalYear(Carbon::create(2023, 12, 31)))->toBeFalse();
    expect($config->isWithinFiscalYear(Carbon::create(2025, 1, 1)))->toBeFalse();
});

it('can get threshold percentage', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 7500.0, // Base100 cast: €7,500.00
    ]);

    expect($config->getThresholdPercentage())->toBe(75.0);

    // Test with zero threshold
    $config->update(['eu_sales_threshold' => 0]);
    expect($config->getThresholdPercentage())->toBe(0.0);
});

it('can get remaining threshold amount', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'              => 'company-123',
        'fiscal_year'             => 2024,
        'eu_sales_threshold'      => 10000.0, // Base100 cast: €10,000.00
        'current_eu_sales_amount' => 7500.0, // Base100 cast: €7,500.00
    ]);

    expect($config->getRemainingThresholdAmount())->toBe(2500.00);

    // Test when threshold is exceeded
    $config->update(['current_eu_sales_amount' => 12000.0]); // Base100 cast: €12,000.00
    expect($config->getRemainingThresholdAmount())->toBe(0.0);
});

it('can manage custom threshold rules', function () {
    $config = CompanyFiscalConfig::create([
        'company_id'             => 'company-123',
        'fiscal_year'            => 2024,
        'custom_threshold_rules' => [
            'DE' => ['threshold' => 15000, 'special_rate' => 19],
            'FR' => ['threshold' => 8000, 'special_rate' => 20],
        ],
    ]);

    expect($config->getCustomThresholdRule('DE'))->toBe(['threshold' => 15000, 'special_rate' => 19]);
    expect($config->getCustomThresholdRule('IT'))->toBeNull();

    $config->setCustomThresholdRule('IT', ['threshold' => 12000, 'special_rate' => 22]);

    expect($config->getCustomThresholdRule('IT'))->toBe(['threshold' => 12000, 'special_rate' => 22]);
});

it('can get default values from config', function () {
    config(['larabill.destination_vat.default_threshold' => 15000.0]); // €15,000.00 in base 100
    config(['larabill.destination_vat.currency' => 'USD']);
    config(['larabill.destination_vat.fiscal_year_start' => '04-01']);

    expect(CompanyFiscalConfig::getDefaultThreshold())->toBe(15000.0); // €15,000.00 in base 100
    expect(CompanyFiscalConfig::getDefaultCurrency())->toBe('USD');
    expect(CompanyFiscalConfig::getDefaultFiscalYearStart())->toBe('04-01');
});

it('sets default values on creation', function () {
    config(['larabill.destination_vat.default_threshold' => 15000.0]); // €15,000.00 in base 100
    config(['larabill.destination_vat.currency' => 'USD']);
    config(['larabill.destination_vat.fiscal_year_start' => '04-01']);

    $config = CompanyFiscalConfig::create([
        'company_id'             => 'company-123',
        'fiscal_year'            => 2024,
        'auto_apply_destination' => true, // Explicitly set to ensure it's not null
    ]);

    expect($config->eu_sales_threshold)->toBe(15000.0); // €15,000.00 in base 100
    expect($config->currency)->toBe('USD');
    expect($config->fiscal_year_start)->toBe('04-01');
    expect($config->auto_apply_destination)->toBeTrue();
    expect($config->apply_destination_iva)->toBeFalse();
    expect($config->current_eu_sales_amount)->toBe(0.0); // Base100 returns float
});
