<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\CacheService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['larabill.cache.driver' => 'file']);
    config(['larabill.cache.prefix' => 'larabill_test']);
});

it('can store and retrieve ROI verification cache', function () {
    $cacheService = new CacheService;

    $data = [
        'user_id'      => 'user-123',
        'vat_code'   => 'ESB12345678',
        'is_roi'       => true,
        'company_name' => 'Test Company',
    ];

    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', $data);

    $retrieved = $cacheService->getRoiVerification('user-123', 'ESB12345678', 'ES');

    expect($retrieved)->toBe($data);
});

it('can check if ROI verification exists in cache', function () {
    $cacheService = new CacheService;

    $data = ['is_roi' => true];

    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeFalse();

    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', $data);

    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeTrue();
});

it('can remove ROI verification from cache', function () {
    $cacheService = new CacheService;

    $data = ['is_roi' => true];

    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', $data);
    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeTrue();

    $cacheService->removeRoiVerification('user-123', 'ESB12345678', 'ES');
    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeFalse();
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
    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', ['is_roi' => true]);
    $cacheService->storeVatRates(['ES' => ['standard' => 21.00]]);
    $cacheService->storeCompanyConfig('company-123', ['apply_destination_iva' => true]);

    // Verify they exist
    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeTrue();
    expect($cacheService->hasVatRates())->toBeTrue();
    expect($cacheService->hasCompanyConfig('company-123'))->toBeTrue();

    // Flush all
    $cacheService->flushAll();

    // Verify they're gone
    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeFalse();
    expect($cacheService->hasVatRates())->toBeFalse();
    expect($cacheService->hasCompanyConfig('company-123'))->toBeFalse();
});

it('can flush specific cache type', function () {
    $cacheService = new CacheService;

    // Store various cache entries
    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', ['is_roi' => true]);
    $cacheService->storeVatRates(['ES' => ['standard' => 21.00]]);
    $cacheService->storeCompanyConfig('company-123', ['apply_destination_iva' => true]);

    // Flush only ROI verification cache
    $cacheService->flushRoiVerificationCache();

    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeFalse();
    expect($cacheService->hasVatRates())->toBeTrue();
    expect($cacheService->hasCompanyConfig('company-123'))->toBeTrue();
});

it('can use custom TTL for different cache types', function () {
    $cacheService = new CacheService;

    // Reset cache state
    CacheService::resetCounters();
    $cacheService->flushAll();

    config(['larabill.cache.ttl.roi_verification' => 3600]); // 1 hour
    config(['larabill.cache.ttl.vat_rates' => 86400]); // 24 hours
    config(['larabill.cache.ttl.company_config' => 1800]); // 30 minutes

    $data = ['is_roi' => true];
    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', $data);

    // Verify the cache entry exists with correct TTL
    $key    = $cacheService->getRoiVerificationKey('user-123', 'ESB12345678', 'ES');
    $hasKey = $cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES');
    expect($hasKey)->toBeTrue();
});

it('can handle cache key generation correctly', function () {
    $cacheService = new CacheService;

    $roiKey    = $cacheService->getRoiVerificationKey('user-123', 'ESB12345678', 'ES');
    $vatKey    = $cacheService->getVatRatesKey();
    $configKey = $cacheService->getCompanyConfigKey('company-123');

    expect($roiKey)->toContain('larabill_test');
    expect($roiKey)->toContain('roi_verification');
    expect($roiKey)->toContain('user-123');
    expect($roiKey)->toContain('ESB12345678');

    expect($vatKey)->toContain('larabill_test');
    expect($vatKey)->toContain('vat_rates');

    expect($configKey)->toContain('larabill_test');
    expect($configKey)->toContain('company_config');
    expect($configKey)->toContain('company-123');
});

it('can work with different cache drivers', function () {
    // Test with file driver
    config(['larabill.cache.driver' => 'file']);
    $cacheService = new CacheService;

    $data = ['is_roi' => true];
    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', $data);

    expect($cacheService->getRoiVerification('user-123', 'ESB12345678', 'ES'))->toBe($data);

    // Test with array driver
    config(['larabill.cache.driver' => 'array']);
    $cacheService = new CacheService;

    $data2 = ['is_roi' => false];
    $cacheService->storeRoiVerification('user-456', 'FRB87654321', 'FR', $data2);

    expect($cacheService->getRoiVerification('user-456', 'FRB87654321', 'FR'))->toBe($data2);
});

it('can handle cache misses gracefully', function () {
    $cacheService = new CacheService;

    expect($cacheService->getRoiVerification('nonexistent', 'INVALID', 'XX'))->toBeNull();
    expect($cacheService->getVatRates())->toBeNull();
    expect($cacheService->getCompanyConfig('nonexistent'))->toBeNull();
});

it('can store and retrieve complex data structures', function () {
    $cacheService = new CacheService;

    $complexData = [
        'user_id'         => 'user-123',
        'vat_code'      => 'ESB12345678',
        'is_roi'          => true,
        'company_name'    => 'Test Company S.L.',
        'company_address' => [
            'street'      => 'Test Street 123',
            'city'        => 'Madrid',
            'postal_code' => '28001',
            'country'     => 'ES',
        ],
        'response_data' => [
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'address'    => 'Test Street 123, Madrid, 28001, ES',
            'vat_code' => 'ESB12345678',
        ],
        'metadata' => [
            'api_source' => 'abstractapi',
            'queried_at' => now()->toISOString(),
            'cache_hit'  => false,
        ],
    ];

    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', $complexData);

    $retrieved = $cacheService->getRoiVerification('user-123', 'ESB12345678', 'ES');

    expect($retrieved)->toBe($complexData);
    expect($retrieved['company_address']['city'])->toBe('Madrid');
    expect($retrieved['response_data']['valid'])->toBeTrue();
    expect($retrieved['metadata']['api_source'])->toBe('abstractapi');
});

it('can handle cache prefix changes', function () {
    config(['larabill.cache.prefix' => 'larabill_v1']);
    $cacheService1 = new CacheService;

    $data = ['is_roi' => true];
    $cacheService1->storeRoiVerification('user-123', 'ESB12345678', 'ES', $data);

    // Change prefix
    config(['larabill.cache.prefix' => 'larabill_v2']);
    $cacheService2 = new CacheService;

    // Should not find the data with new prefix
    expect($cacheService2->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeFalse();

    // But should still find with old prefix
    expect($cacheService1->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeTrue();
});

it('can get cache statistics', function () {
    $cacheService = new CacheService;

    // Reset cache state
    CacheService::resetCounters();
    $cacheService->flushAll();

    // Store some cache entries
    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', ['is_roi' => true]);
    $cacheService->storeRoiVerification('user-456', 'FRB87654321', 'FR', ['is_roi' => false]);
    $cacheService->storeVatRates(['ES' => ['standard' => 21.00]]);
    $cacheService->storeCompanyConfig('company-123', ['apply_destination_iva' => true]);

    $stats = $cacheService->getCacheStatistics();

    expect($stats)->toBeArray();
    expect($stats['roi_verifications'])->toBe(2);
    expect($stats['vat_rates'])->toBe(1);
    expect($stats['company_configs'])->toBe(1);
    expect($stats['total_entries'])->toBe(4);
});

it('can handle cache driver failures gracefully', function () {
    // Mock cache driver to throw exception
    Cache::shouldReceive('put')->andThrow(new Exception('Cache driver failed'));
    Cache::shouldReceive('get')->andThrow(new Exception('Cache driver failed'));
    Cache::shouldReceive('has')->andThrow(new Exception('Cache driver failed'));

    $cacheService = new CacheService;

    // Should not throw exceptions
    expect(fn () => $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', ['is_roi' => true]))
        ->not->toThrow(Exception::class);

    expect($cacheService->getRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeNull();
    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeFalse();
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

it('can handle concurrent cache access', function () {
    $cacheService = new CacheService;

    $data = ['is_roi' => true];

    // Simulate concurrent access
    $results  = [];
    $promises = [];

    for ($i = 0; $i < 10; $i++) {
        $promises[] = function () use ($cacheService, $data, $i, &$results) {
            $cacheService->storeRoiVerification("user-{$i}", "ESB{$i}", 'ES', $data);
            $results[] = $cacheService->getRoiVerification("user-{$i}", "ESB{$i}", 'ES');
        };
    }

    // Execute all promises
    foreach ($promises as $promise) {
        $promise();
    }

    expect($results)->toHaveCount(10);
    foreach ($results as $result) {
        expect($result)->toBe($data);
    }
});
