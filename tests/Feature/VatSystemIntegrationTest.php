<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Feature;

use AichaDigital\Larabill\Models\{CompanyFiscalConfig, CountryVatRate, EuSalesThreshold, RoiQuery, UserRoiVerification, VatCategory};
use AichaDigital\Larabill\Services\{CacheService, CompanyConfigService, DestinationVatService, RoiVerificationService};
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Setup configuration
    config(['larabill.roi_verification.cache_duration_days' => 15]);
    config(['larabill.roi_verification.force_api_check' => false]);
    config(['larabill.destination_vat.default_threshold' => 10000]);
    config(['larabill.destination_vat.currency' => 'EUR']);
    config(['larabill.cache.driver' => 'array']);
});

it('can perform complete ROI verification workflow', function () {
    $roiService   = new RoiVerificationService;
    $cacheService = new CacheService;

    // Mock successful API response
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'address'    => 'Test Address 123, Madrid, 28001, ES',
            'vat_code' => 'ESB12345678',
        ], 200),
    ]);

    // Step 1: Verify ROI status
    $result = $roiService->verifyRoiStatus('user-123', 'ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result['is_roi'])->toBeTrue();
    expect($result['company_name'])->toBe('Test Company S.L.');
    expect($result['cache_hit'])->toBeFalse();

    // Step 2: Verify cache was created
    $cached = $cacheService->getRoiVerification('user-123', 'ESB12345678', 'ES');
    expect($cached)->not->toBeNull();
    expect($cached['is_roi'])->toBeTrue();

    // Step 3: Verify database records
    $verification = UserRoiVerification::where('user_id', 'user-123')
        ->where('vat_code', 'ESB12345678')
        ->first();

    expect($verification)->not->toBeNull();
    expect($verification->is_roi)->toBeTrue();

    $query = RoiQuery::where('user_id', 'user-123')
        ->where('vat_code', 'ESB12345678')
        ->first();

    expect($query)->not->toBeNull();
    expect($query->query_type)->toBe(RoiQuery::QUERY_TYPE_API);

    // Step 4: Verify cache hit on second call
    $result2 = $roiService->verifyRoiStatus('user-123', 'ESB12345678', 'ES');
    expect($result2['cache_hit'])->toBeTrue();
    expect($result2['api_source'])->toBe('cache');
});

it('can perform complete destination VAT workflow', function () {
    $destinationVatService = new DestinationVatService;
    $companyConfigService  = new CompanyConfigService;

    // Step 1: Create company configuration
    $config = $companyConfigService->createCompanyConfig('company-123', 2024, [
        'apply_destination_iva'  => false,
        'eu_sales_threshold'     => 10000.0, // Base100 cast: €10,000.00
        'auto_apply_destination' => true,
    ]);

    expect($config->apply_destination_iva)->toBeFalse();
    expect($config->eu_sales_threshold)->toBe(10000.0); // Base100 returns float

    // Step 2: Add country VAT rates
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

    // Step 3: Add VAT categories
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

    // Step 4: Check initial destination VAT status
    $shouldApply = $destinationVatService->shouldApplyDestinationVat('company-123', 2024);
    expect($shouldApply)->toBeFalse();

    // Step 5: Update EU sales to exceed threshold
    $updated = $destinationVatService->updateEuSalesAmount('company-123', 2024, 'ES', 6000.00);
    expect($updated->current_eu_sales_amount)->toBe(6000.0);

    // Step 6: Update EU sales to exceed threshold
    $exceeded = $destinationVatService->updateEuSalesAmount('company-123', 2024, 'FR', 5000.00);
    expect($exceeded->current_eu_sales_amount)->toBe(11000.0);
    expect($exceeded->checkThreshold())->toBeTrue();
    expect($exceeded->apply_destination_iva)->toBeTrue();

    // Step 7: Verify destination VAT should now be applied
    $shouldApplyAfter = $destinationVatService->shouldApplyDestinationVat('company-123', 2024);
    expect($shouldApplyAfter)->toBeTrue();

    // Step 8: Calculate VAT for different countries
    $esVat = $destinationVatService->calculateVatAmount(1000.00, 'ES');
    $frVat = $destinationVatService->calculateVatAmount(1000.00, 'FR');

    expect($esVat)->toBe(210.00);
    expect($frVat)->toBe(200.00);

    // Step 9: Calculate VAT for specific categories
    $standardVat = $destinationVatService->calculateVatAmount(1000.00, 'ES', 'Standard Goods');
    $reducedVat  = $destinationVatService->calculateVatAmount(1000.00, 'ES', 'Reduced Goods');

    expect($standardVat)->toBe(210.00);
    expect($reducedVat)->toBe(100.00);
});

it('can perform complete EU sales threshold monitoring workflow', function () {
    $destinationVatService = new DestinationVatService;
    $companyConfigService  = new CompanyConfigService;

    // Step 1: Create company configuration
    $config = $companyConfigService->createCompanyConfig('company-123', 2024, [
        'eu_sales_threshold'     => 10000.0, // Base100 cast: €10,000.00
        'auto_apply_destination' => true,
    ]);

    // Step 2: Create EU sales threshold tracking
    $threshold = EuSalesThreshold::create([
        'company_id'           => 'company-123',
        'fiscal_year'          => 2024,
        'total_amount'         => 0.00,
        'breakdown_by_country' => [],
    ]);

    // Step 3: Add sales incrementally
    $destinationVatService->updateEuSalesAmount('company-123', 2024, 'ES', 3000.00);
    $destinationVatService->updateEuSalesAmount('company-123', 2024, 'FR', 2000.00);
    $destinationVatService->updateEuSalesAmount('company-123', 2024, 'DE', 1000.00);

    // Step 4: Check threshold status
    $status = $destinationVatService->getEuSalesThresholdStatus('company-123', 2024);
    expect($status['current_amount'])->toBe(6000.00);
    expect($status['percentage'])->toBe(60.0);
    expect($status['remaining'])->toBe(4000.00);
    expect($status['exceeded'])->toBeFalse();

    // Step 5: Add more sales to exceed threshold
    $destinationVatService->updateEuSalesAmount('company-123', 2024, 'IT', 5000.00);

    // Step 6: Check threshold exceeded
    $statusAfter = $destinationVatService->getEuSalesThresholdStatus('company-123', 2024);
    expect($statusAfter['current_amount'])->toBe(11000.00);
    expect($statusAfter['percentage'])->toBe(110.0);
    expect($statusAfter['remaining'])->toBe(0);
    expect($statusAfter['exceeded'])->toBeTrue();

    // Step 7: Get breakdown by country
    $breakdown = $destinationVatService->getEuSalesBreakdownByCountry('company-123', 2024);
    expect($breakdown['total'])->toBe(11000.00);
    expect($breakdown['countries']['ES'])->toBe(3000.00);
    expect($breakdown['countries']['FR'])->toBe(2000.00);
    expect($breakdown['countries']['DE'])->toBe(1000.00);
    expect($breakdown['countries']['IT'])->toBe(5000.00);

    // Step 8: Get companies exceeding threshold
    $exceededCompanies = $destinationVatService->getCompaniesExceedingThreshold(2024);
    expect($exceededCompanies)->toHaveCount(1);
    expect($exceededCompanies->first()->company_id)->toBe('company-123');
});

it('can perform complete cache management workflow', function () {
    $cacheService = new CacheService;
    $roiService   = new RoiVerificationService;

    // Reset cache state
    CacheService::resetCounters();
    $cacheService->flushAll();

    // Step 1: Store various cache entries
    $roiData = ['is_roi' => true, 'company_name' => 'Test Company'];
    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', $roiData);

    $vatRates = ['ES' => ['standard' => 21.00], 'FR' => ['standard' => 20.00]];
    $cacheService->storeVatRates($vatRates);

    $companyConfig = ['apply_destination_iva' => true, 'eu_sales_threshold' => 10000.00];
    $cacheService->storeCompanyConfig('company-123', $companyConfig);

    // Step 2: Verify cache entries exist
    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeTrue();
    expect($cacheService->hasVatRates())->toBeTrue();
    expect($cacheService->hasCompanyConfig('company-123'))->toBeTrue();

    // Step 3: Retrieve cache entries
    $retrievedRoi      = $cacheService->getRoiVerification('user-123', 'ESB12345678', 'ES');
    $retrievedVatRates = $cacheService->getVatRates();
    $retrievedConfig   = $cacheService->getCompanyConfig('company-123');

    expect($retrievedRoi)->toBe($roiData);
    expect($retrievedVatRates)->toBe($vatRates);
    expect($retrievedConfig)->toBe($companyConfig);

    // Step 4: Get cache statistics
    $stats = $cacheService->getCacheStatistics();
    expect($stats['roi_verifications'])->toBe(1);
    expect($stats['vat_rates'])->toBe(1);
    expect($stats['company_configs'])->toBe(1);
    expect($stats['total_entries'])->toBe(3);

    // Step 5: Flush specific cache type
    $cacheService->flushRoiVerificationCache();
    expect($cacheService->hasRoiVerification('user-123', 'ESB12345678', 'ES'))->toBeFalse();
    expect($cacheService->hasVatRates())->toBeTrue();
    expect($cacheService->hasCompanyConfig('company-123'))->toBeTrue();

    // Step 6: Flush all cache
    $cacheService->flushAll();
    expect($cacheService->hasVatRates())->toBeFalse();
    expect($cacheService->hasCompanyConfig('company-123'))->toBeFalse();
});

it('can perform complete legal compliance workflow', function () {
    $roiService = new RoiVerificationService;

    // Step 1: Perform multiple ROI verifications
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'vat_code' => 'ESB12345678',
        ], 200),
    ]);

    $roiService->verifyRoiStatus('user-123', 'ESB12345678', 'ES');
    $roiService->verifyRoiStatus('user-456', 'FRB87654321', 'FR');
    $roiService->verifyRoiStatus('user-789', 'DEB123456789', 'DE');

    // Step 2: Verify all queries were logged
    $queries = RoiQuery::all();
    expect($queries)->toHaveCount(3);

    foreach ($queries as $query) {
        expect($query->query_type)->toBe(RoiQuery::QUERY_TYPE_API);
        expect($query->legal_retention_until)->toBeGreaterThan(now());
    }

    // Step 3: Get query statistics
    $stats = $roiService->getRoiQueryStatistics('user-123');
    expect($stats['total'])->toBe(1);
    expect($stats['api_queries'])->toBe(1);
    expect($stats['cache_queries'])->toBe(0);

    // Step 4: Create expired query for cleanup test
    RoiQuery::create([
        'user_id'               => 'user-old',
        'vat_code'            => 'ESB99999999',
        'country_code'          => 'ES',
        'query_type'            => RoiQuery::QUERY_TYPE_API,
        'queried_at'            => now()->subDays(3000), // 8+ years ago
        'legal_retention_until' => now()->subDays(445), // Expired
    ]);

    // Step 5: Cleanup expired queries
    $deletedCount = $roiService->cleanupExpiredLegalRetentionQueries();
    expect($deletedCount)->toBe(1);
    expect(RoiQuery::count())->toBe(3); // Original 3 queries remain
});

it('can perform complete multi-company workflow', function () {
    $destinationVatService = new DestinationVatService;
    $companyConfigService  = new CompanyConfigService;

    // Step 1: Create multiple company configurations
    $config1 = $companyConfigService->createCompanyConfig('company-123', 2024, [
        'eu_sales_threshold'     => 10000.0, // Base100 cast: €10,000.00
        'auto_apply_destination' => true,
    ]);

    $config2 = $companyConfigService->createCompanyConfig('company-456', 2024, [
        'eu_sales_threshold'     => 15000.0, // Base100 cast: €15,000.00
        'auto_apply_destination' => true,
    ]);

    $config3 = $companyConfigService->createCompanyConfig('company-789', 2024, [
        'eu_sales_threshold'     => 8000.0, // Base100 cast: €8,000.00
        'auto_apply_destination' => true,
    ]);

    // Step 2: Add different EU sales amounts
    $destinationVatService->updateEuSalesAmount('company-123', 2024, 'ES', 12000.00); // Exceeds threshold
    $destinationVatService->updateEuSalesAmount('company-456', 2024, 'FR', 8000.00); // Below threshold
    $destinationVatService->updateEuSalesAmount('company-789', 2024, 'DE', 9000.00); // Exceeds threshold

    // Step 3: Get companies exceeding threshold
    $exceededCompanies = $destinationVatService->getCompaniesExceedingThreshold(2024);
    expect($exceededCompanies)->toHaveCount(2);
    expect($exceededCompanies->pluck('company_id')->toArray())->toContain('company-123');
    expect($exceededCompanies->pluck('company_id')->toArray())->toContain('company-789');

    // Step 4: Get companies needing notification
    $needsNotification = $destinationVatService->getCompaniesNeedingNotification(2024);
    expect($needsNotification)->toHaveCount(2);

    // Step 5: Get destination VAT statistics
    $stats = $destinationVatService->getDestinationVatStatistics(2024);
    expect($stats['total_companies'])->toBe(3);
    expect($stats['companies_exceeding_threshold'])->toBe(2);
    expect($stats['total_eu_sales'])->toBe(29000.00); // 12000 + 8000 + 9000

    // Step 6: Reset EU sales for new fiscal year
    $destinationVatService->resetEuSalesForNewFiscalYear('company-123', 2025);
    $destinationVatService->resetEuSalesForNewFiscalYear('company-456', 2025);
    $destinationVatService->resetEuSalesForNewFiscalYear('company-789', 2025);

    // Step 7: Verify reset
    $newConfig1 = CompanyFiscalConfig::findByCompanyAndYear('company-123', 2025);
    $newConfig2 = CompanyFiscalConfig::findByCompanyAndYear('company-456', 2025);
    $newConfig3 = CompanyFiscalConfig::findByCompanyAndYear('company-789', 2025);

    expect($newConfig1->current_eu_sales_amount)->toBe(0.0);
    expect($newConfig2->current_eu_sales_amount)->toBe(0.0);
    expect($newConfig3->current_eu_sales_amount)->toBe(0.0);
    expect($newConfig1->threshold_exceeded)->toBeFalse();
    expect($newConfig2->threshold_exceeded)->toBeFalse();
    expect($newConfig3->threshold_exceeded)->toBeFalse();
});

it('can handle error scenarios gracefully', function () {
    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'real_api_key_123']);
    config(['larabill.vat_apis.apilayer.key' => 'real_api_key_456']);

    // Create services after configuration
    $roiService            = new RoiVerificationService;
    $destinationVatService = new DestinationVatService;
    $companyConfigService  = new CompanyConfigService;

    // Step 1: Test ROI verification with API failure
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 500),
        'http://apilayer.net/api/validate*'         => Http::response([
            'valid'        => false,
            'vat_code'   => 'ESINVALID1',
            'company_name' => null,
        ], 200),
    ]);

    $result = $roiService->verifyRoiStatus('user-123', 'ESINVALID1', 'ES');
    expect($result['is_roi'])->toBeFalse();
    // Note: When fallback succeeds, there's no error key
    expect($result['api_source'])->toBe('apilayer');

    // Step 2: Test company config with non-existent company (before any auto-creation)
    $config = $companyConfigService->getCompanyConfig('nonexistent', 2024);
    expect($config)->toBeNull();

    // Step 3: Test destination VAT with non-existent company
    $shouldApply = $destinationVatService->shouldApplyDestinationVat('nonexistent', 2024);
    expect($shouldApply)->toBeFalse();

    // Step 4: Test VAT calculation with non-existent country
    $vatAmount = $destinationVatService->calculateVatAmount(1000.00, 'XX');
    expect($vatAmount)->toBe(0.00);

    // Step 5: Test cache with non-existent entries
    $cacheService = new CacheService;
    $cached       = $cacheService->getRoiVerification('nonexistent', 'INVALID', 'XX');
    expect($cached)->toBeNull();
});

it('can perform complete performance optimization workflow', function () {
    $cacheService = new CacheService;
    $roiService   = new RoiVerificationService;

    // Reset cache state
    CacheService::resetCounters();
    $cacheService->flushAll();

    // Step 1: Store cache entries for performance
    $roiData = ['is_roi' => true, 'company_name' => 'Test Company'];
    $cacheService->storeRoiVerification('user-123', 'ESB12345678', 'ES', $roiData);

    $vatRates = ['ES' => ['standard' => 21.00], 'FR' => ['standard' => 20.00]];
    $cacheService->storeVatRates($vatRates);

    // Step 2: Perform multiple cache hits for performance testing
    $startTime = microtime(true);

    for ($i = 0; $i < 100; $i++) {
        $cacheService->getRoiVerification('user-123', 'ESB12345678', 'ES');
        $cacheService->getVatRates();
    }

    $endTime       = microtime(true);
    $executionTime = $endTime - $startTime;

    // Step 3: Verify performance is acceptable (should be very fast with cache)
    expect($executionTime)->toBeLessThan(1.0); // Should complete in less than 1 second

    // Step 4: Test cache statistics
    $stats = $cacheService->getCacheStatistics();
    expect($stats['total_entries'])->toBe(2); // ROI + VAT rates
});

it('can perform complete data consistency workflow', function () {
    $destinationVatService = new DestinationVatService;
    $companyConfigService  = new CompanyConfigService;

    // Step 1: Create company configuration
    $config = $companyConfigService->createCompanyConfig('company-123', 2024, [
        'eu_sales_threshold'     => 10000.00,
        'auto_apply_destination' => true,
    ]);

    // Step 2: Create EU sales threshold
    $threshold = EuSalesThreshold::create([
        'company_id'           => 'company-123',
        'fiscal_year'          => 2024,
        'total_amount'         => 0.00,
        'breakdown_by_country' => [],
    ]);

    // Step 3: Update EU sales amount
    $destinationVatService->updateEuSalesAmount('company-123', 2024, 'ES', 5000.00);

    // Step 4: Verify data consistency between models
    $updatedConfig    = CompanyFiscalConfig::findByCompanyAndYear('company-123', 2024);
    $updatedThreshold = EuSalesThreshold::findByCompanyAndYear('company-123', 2024);

    expect($updatedConfig->current_eu_sales_amount)->toBe(5000.0);
    expect($updatedThreshold->total_amount)->toBe(5000.0);
    expect($updatedThreshold->breakdown_by_country['ES'])->toBe(5000.0);

    // Step 5: Update EU sales amount again
    $destinationVatService->updateEuSalesAmount('company-123', 2024, 'FR', 3000.00);

    // Step 6: Verify data consistency after second update
    $finalConfig    = CompanyFiscalConfig::findByCompanyAndYear('company-123', 2024);
    $finalThreshold = EuSalesThreshold::findByCompanyAndYear('company-123', 2024);

    expect($finalConfig->current_eu_sales_amount)->toBe(8000.0);
    expect($finalThreshold->total_amount)->toBe(8000.0);
    expect($finalThreshold->breakdown_by_country['ES'])->toBe(5000.0);
    expect($finalThreshold->breakdown_by_country['FR'])->toBe(3000.0);
});
