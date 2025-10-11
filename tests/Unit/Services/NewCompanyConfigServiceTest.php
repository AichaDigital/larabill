<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\FiscalSettings;
use AichaDigital\Larabill\Services\CompanyConfigService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Clear any existing company config
    FiscalSettings::truncate();
});

it('can get current company configuration', function () {
    $service = new CompanyConfigService;

    $config = $service->getCurrentConfig();

    expect($config)->toBeInstanceOf(FiscalSettings::class);
    expect($config->is_oss)->toBeFalse();
    expect($config->is_roi)->toBeFalse();
    expect($config->eu_sales_threshold)->toBe(10000.0);
    expect($config->current_eu_sales_amount)->toBe(0.0);
});

it('can update company configuration', function () {
    $service = new CompanyConfigService;

    $data = [
        'is_oss'             => true,
        'eu_sales_threshold' => 15000.0,
    ];

    $config = $service->updateConfig($data);

    expect($config->is_oss)->toBeTrue();
    expect($config->eu_sales_threshold)->toBe(15000.0);
});

it('can enable OSS registration', function () {
    $service = new CompanyConfigService;

    $config = $service->enableOSS();

    expect($config->is_oss)->toBeTrue();
});

it('can disable OSS registration', function () {
    $service = new CompanyConfigService;

    // First enable OSS
    $service->enableOSS();

    // Then disable it
    $config = $service->disableOSS();

    expect($config->is_oss)->toBeFalse();
});

it('can enable ROI status', function () {
    $service = new CompanyConfigService;

    $config = $service->enableROI();

    expect($config->is_roi)->toBeTrue();
});

it('can disable ROI status', function () {
    $service = new CompanyConfigService;

    // First enable ROI
    $service->enableROI();

    // Then disable it
    $config = $service->disableROI();

    expect($config->is_roi)->toBeFalse();
});

it('can update EU sales threshold', function () {
    $service = new CompanyConfigService;

    $config = $service->updateThreshold(12000.0);

    expect($config->eu_sales_threshold)->toBe(12000.0);
});

it('can update EU sales amount', function () {
    $service = new CompanyConfigService;

    $config = $service->updateAmount(5000.0);

    expect($config->current_eu_sales_amount)->toBe(5000.0);
});

it('can reset EU sales for new fiscal year', function () {
    $service = new CompanyConfigService;

    // First add some sales
    $service->updateAmount(8000.0);

    // Then reset for new year
    $config = $service->resetEuSalesForNewYear(2025);

    expect($config->current_eu_sales_amount)->toBe(0.0);
    expect($config->fiscal_year)->toBe(2025);
    expect($config->threshold_exceeded)->toBeFalse();
    expect($config->notification_sent)->toBeFalse();
});

it('can check if destination VAT should be applied when OSS is enabled', function () {
    $service = new CompanyConfigService;

    // Enable OSS
    $service->enableOSS();

    $shouldApply = $service->shouldApplyDestinationVat();

    expect($shouldApply)->toBeTrue();
});

it('can check if destination VAT should be applied when threshold is exceeded', function () {
    $service = new CompanyConfigService;

    // Set threshold and exceed it
    $service->updateThreshold(10000.0);
    $service->updateAmount(12000.0);

    $shouldApply = $service->shouldApplyDestinationVat();

    expect($shouldApply)->toBeTrue();
});

it('should not apply destination VAT when threshold not exceeded and OSS disabled', function () {
    $service = new CompanyConfigService;

    // Set threshold but don't exceed it
    $service->updateThreshold(10000.0);
    $service->updateAmount(5000.0);

    $shouldApply = $service->shouldApplyDestinationVat();

    expect($shouldApply)->toBeFalse();
});

it('can get threshold percentage', function () {
    $service = new CompanyConfigService;

    // Set threshold to 10000 and current sales to 7500
    $service->updateThreshold(10000.0);
    $service->updateAmount(7500.0);

    $percentage = $service->getThresholdPercentage();

    expect($percentage)->toBe(75.0);
});

it('can get remaining amount until threshold', function () {
    $service = new CompanyConfigService;

    // Set threshold to 10000 and current sales to 7500
    $service->updateThreshold(10000.0);
    $service->updateAmount(7500.0);

    $remaining = $service->getRemainingThresholdAmount();

    expect($remaining)->toBe(2500.0);
});

it('can check if notification is needed', function () {
    $service = new CompanyConfigService;

    // Exceed threshold
    $service->updateThreshold(10000.0);
    $service->updateAmount(12000.0);

    $needsNotification = $service->needsNotification();

    expect($needsNotification)->toBeTrue();
});

it('can mark notification as sent', function () {
    $service = new CompanyConfigService;

    // Exceed threshold first
    $service->updateThreshold(10000.0);
    $service->updateAmount(12000.0);

    // Mark notification as sent
    $config = $service->markNotificationSent();

    expect($config->notification_sent)->toBeTrue();
});

it('can get companies needing notification', function () {
    $service = new CompanyConfigService;

    // Exceed threshold
    $service->updateThreshold(10000.0);
    $service->updateAmount(12000.0);

    $companies = $service->getCompaniesNeedingNotification();

    expect($companies)->toHaveCount(1);
    expect($companies[0])->toBeInstanceOf(FiscalSettings::class);
});

it('can get company statistics', function () {
    $service = new CompanyConfigService;

    // Configure company
    $service->updateThreshold(10000.0);
    $service->updateAmount(7500.0);
    $service->enableROI();

    $stats = $service->getCompanyStatistics();

    expect($stats)->toBeArray();
    expect($stats['is_roi'])->toBeTrue();
    expect($stats['eu_sales_threshold'])->toBe(10000.0);
    expect($stats['current_eu_sales_amount'])->toBe(7500.0);
    expect($stats['threshold_percentage'])->toBe(75.0);
    expect($stats['should_apply_destination_vat'])->toBeFalse();
});

it('can validate configuration data', function () {
    $service = new CompanyConfigService;

    $validData = [
        'is_oss'             => true,
        'eu_sales_threshold' => 15000.0,
        'currency'           => 'EUR',
    ];

    $errors = $service->validateConfigData($validData);

    expect($errors)->toBeEmpty();
});

it('can detect invalid configuration data', function () {
    $service = new CompanyConfigService;

    $invalidData = [
        'is_oss'             => 'invalid', // Should be boolean
        'eu_sales_threshold' => 'invalid', // Should be numeric
        'currency'           => 'INVALID', // Should be 3 letters
    ];

    $errors = $service->validateConfigData($invalidData);

    expect($errors)->toHaveCount(3);
    expect($errors[0])->toContain('eu_sales_threshold');
    expect($errors[1])->toContain('is_oss');
    expect($errors[2])->toContain('currency');
});

it('can get default configuration', function () {
    $service = new CompanyConfigService;

    $default = $service->getDefaultConfig();

    expect($default)->toBeArray();
    expect($default['is_oss'])->toBeFalse();
    expect($default['is_roi'])->toBeFalse();
    expect($default['eu_sales_threshold'])->toBe(10000.0);
    expect($default['currency'])->toBe('EUR');
});

it('can handle service errors gracefully', function () {
    $service = new CompanyConfigService;

    $exception = new \Exception('Test error');
    $result    = $service->handleError($exception);

    expect($result)->toBeArray();
    expect($result['error'])->toBeTrue();
    expect($result['message'])->toBe('Test error');
    expect($result['config'])->toBeArray();
});

it('caches company configuration', function () {
    $service = new CompanyConfigService;

    // Clear cache first
    Cache::flush();

    // First call should create cache
    $config1 = $service->getCurrentConfig();

    // Second call should use cache
    $config2 = $service->getCurrentConfig();

    expect($config1->id)->toBe($config2->id);
});

it('clears cache when configuration is updated', function () {
    $service = new CompanyConfigService;

    // Get initial config
    $config1 = $service->getCurrentConfig();

    // Update config
    $service->updateThreshold(15000.0);

    // Get updated config
    $config2 = $service->getCurrentConfig();

    expect($config2->eu_sales_threshold)->toBe(15000.0);
});
