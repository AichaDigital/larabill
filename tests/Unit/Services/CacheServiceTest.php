<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\CacheService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['larabill.cache.driver' => 'file']);
    config(['larabill.cache.prefix' => 'larabill_test']);
});

it('can store and retrieve VAT rates cache', function () {
    $cacheService = new CacheService;

    $vatRates = [
        'ES' => ['standard' => 21.00, 'reduced' => 10.00],
        'FR' => ['standard' => 20.00, 'reduced' => 5.50],
    ];

    $cacheService->storeVatRates($vatRates);

    $retrieved = $cacheService->getVatRates();

    expect($retrieved)->toBe($vatRates);
});

it('can check if VAT rates exist in cache', function () {
    CacheService::resetCounters();
    $cacheService = new CacheService;

    expect($cacheService->hasVatRates())->toBeFalse();

    $vatRates = ['ES' => ['standard' => 21.00]];
    $cacheService->storeVatRates($vatRates);

    expect($cacheService->hasVatRates())->toBeTrue();
});

it('can remove VAT rates from cache', function () {
    $cacheService = new CacheService;

    $vatRates = ['ES' => ['standard' => 21.00]];
    $cacheService->storeVatRates($vatRates);
    expect($cacheService->hasVatRates())->toBeTrue();

    $cacheService->removeVatRates();
    expect($cacheService->hasVatRates())->toBeFalse();
});

it('can store and retrieve company configuration cache', function () {
    $cacheService = new CacheService;

    $config = [
        'company_id'            => 'company-123',
        'apply_destination_iva' => true,
        'eu_sales_threshold'    => 10000.00,
    ];

    $cacheService->storeCompanyConfig('company-123', $config);

    $retrieved = $cacheService->getCompanyConfig('company-123');

    expect($retrieved)->toBe($config);
});

it('can check if company configuration exists in cache', function () {
    $cacheService = new CacheService;

    expect($cacheService->hasCompanyConfig('company-123'))->toBeFalse();

    $config = ['apply_destination_iva' => true];
    $cacheService->storeCompanyConfig('company-123', $config);

    expect($cacheService->hasCompanyConfig('company-123'))->toBeTrue();
});

it('can remove company configuration from cache', function () {
    $cacheService = new CacheService;

    $config = ['apply_destination_iva' => true];
    $cacheService->storeCompanyConfig('company-123', $config);
    expect($cacheService->hasCompanyConfig('company-123'))->toBeTrue();

    $cacheService->removeCompanyConfig('company-123');
    expect($cacheService->hasCompanyConfig('company-123'))->toBeFalse();
});

it('can flush all cache', function () {
    $cacheService = new CacheService;

    // Store various cache entries
    $cacheService->storeVatRates(['ES' => ['standard' => 21.00]]);
    $cacheService->storeCompanyConfig('company-123', ['apply_destination_iva' => true]);

    // Verify they exist
    expect($cacheService->hasVatRates())->toBeTrue();
    expect($cacheService->hasCompanyConfig('company-123'))->toBeTrue();

    // Flush all
    $cacheService->flushAll();

    // Verify they're gone
    expect($cacheService->hasVatRates())->toBeFalse();
    expect($cacheService->hasCompanyConfig('company-123'))->toBeFalse();
});

it('can handle cache key generation correctly', function () {
    $cacheService = new CacheService;

    $vatKey    = $cacheService->getVatRatesKey();
    $configKey = $cacheService->getCompanyConfigKey('company-123');

    expect($vatKey)->toContain('larabill_test');
    expect($vatKey)->toContain('vat_rates');

    expect($configKey)->toContain('larabill_test');
    expect($configKey)->toContain('company_config');
    expect($configKey)->toContain('company-123');
});

it('can handle cache misses gracefully', function () {
    $cacheService = new CacheService;

    expect($cacheService->getVatRates())->toBeNull();
    expect($cacheService->getCompanyConfig('nonexistent'))->toBeNull();
});

it('can get cache statistics', function () {
    $cacheService = new CacheService;

    // Reset cache state
    CacheService::resetCounters();
    $cacheService->flushAll();

    // Store some cache entries
    $cacheService->storeVatRates(['ES' => ['standard' => 21.00]]);
    $cacheService->storeCompanyConfig('company-123', ['apply_destination_iva' => true]);

    $stats = $cacheService->getCacheStatistics();

    expect($stats)->toBeArray();
    expect($stats['vat_rates'])->toBe(1);
    expect($stats['company_configs'])->toBe(1);
    expect($stats['total_entries'])->toBe(2);
});

it('can validate cache configuration', function () {
    $cacheService = new CacheService;

    // Test with valid configuration
    config(['larabill.cache.driver' => 'file']);
    config(['larabill.cache.prefix' => 'larabill_test']);

    expect($cacheService->isConfigurationValid())->toBeTrue();

    // Test with invalid driver
    config(['larabill.cache.driver' => 'invalid_driver']);

    expect($cacheService->isConfigurationValid())->toBeFalse();

    // Test with empty prefix
    config(['larabill.cache.driver' => 'file']);
    config(['larabill.cache.prefix' => '']);

    expect($cacheService->isConfigurationValid())->toBeFalse();
});

it('can get cache driver information', function () {
    config(['larabill.cache.driver' => 'file']);
    $cacheService = new CacheService;

    $driverInfo = $cacheService->getDriverInfo();

    expect($driverInfo)->toBeArray();
    expect($driverInfo['driver'])->toBe('file');
    expect($driverInfo['prefix'])->toBe('larabill_test');
    expect($driverInfo['ttl'])->toBeArray();
});
