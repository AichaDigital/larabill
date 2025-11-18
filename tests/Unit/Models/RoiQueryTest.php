<?php

declare(strict_types=1);

/**
 * @deprecated v0.4.0 - ROI queries refactored
 * These tests use legacy API/tables that no longer exist in v0.4.0.
 */

// Skip all tests - legacy code
beforeEach(function () {
    $this->markTestSkipped('Legacy test - deprecated in v0.4.0');
});

use AichaDigital\Larabill\Models\RoiQuery;

it('can create a ROI query', function () {
    $query = RoiQuery::create([
        'user_id'       => 'user-123',
        'vat_code'      => 'ESB12345678',
        'country_code'  => 'ES',
        'query_type'    => RoiQuery::QUERY_TYPE_API,
        'api_source'    => 'abstractapi',
        'response_data' => ['valid' => true],
        'queried_at'    => now(),
        'cache_used'    => false,
    ]);

    expect($query)->toBeInstanceOf(RoiQuery::class);
    expect($query->query_type)->toBe(RoiQuery::QUERY_TYPE_API);
    expect($query->api_source)->toBe('abstractapi');
    expect($query->cache_used)->toBeFalse();
    expect($query->legal_retention_until)->toBeGreaterThan(now());
});

it('can create API query', function () {
    $query = RoiQuery::createApiQuery(
        'user-123',
        'ESB12345678',
        'ES',
        'abstractapi',
        ['valid' => true, 'company' => 'Test Company']
    );

    expect($query)->toBeInstanceOf(RoiQuery::class);
    expect($query->query_type)->toBe(RoiQuery::QUERY_TYPE_API);
    expect($query->api_source)->toBe('abstractapi');
    expect($query->cache_used)->toBeFalse();
    expect($query->response_data['company'])->toBe('Test Company');
});

it('can create cache query', function () {
    $query = RoiQuery::createCacheQuery(
        'user-123',
        'ESB12345678',
        'ES',
        ['cached' => true, 'expired_at' => now()->addDays(15)]
    );

    expect($query)->toBeInstanceOf(RoiQuery::class);
    expect($query->query_type)->toBe(RoiQuery::QUERY_TYPE_CACHE);
    expect($query->api_source)->toBe('cache');
    expect($query->cache_used)->toBeTrue();
    expect($query->response_data['cached'])->toBeTrue();
});

it('can find queries by user and date range', function () {
    $startDate = now()->subDays(10);
    $endDate   = now();

    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
        'queried_at'   => now()->subDays(5), // Within range
    ]);

    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB87654321',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_CACHE,
        'queried_at'   => now()->subDays(15), // Outside range
    ]);

    $queries = RoiQuery::findByUserAndDateRange('user-123', $startDate, $endDate)->get();

    expect($queries)->toHaveCount(1);
    expect($queries->first()->vat_code)->toBe('ESB12345678');
});

it('can find queries by user and VAT', function () {
    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
        'queried_at'   => now()->subDays(5),
    ]);

    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB87654321',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_CACHE,
        'queried_at'   => now()->subDays(3),
    ]);

    $queries = RoiQuery::findByUserAndVat('user-123', 'ESB12345678', 'ES')->get();

    expect($queries)->toHaveCount(1);
    expect($queries->first()->vat_code)->toBe('ESB12345678');
});

it('can use scopes correctly', function () {
    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
        'queried_at'   => now()->subDays(5),
    ]);

    RoiQuery::create([
        'user_id'      => 'user-456',
        'vat_code'     => 'ESB87654321',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_CACHE,
        'cache_used'   => true,
        'queried_at'   => now()->subDays(3),
    ]);

    RoiQuery::create([
        'user_id'      => 'user-789',
        'vat_code'     => 'FRB12345678',
        'country_code' => 'FR',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
        'queried_at'   => now()->subDays(1),
    ]);

    // Test by query type scope
    $apiQueries = RoiQuery::apiQueries()->get();
    expect($apiQueries)->toHaveCount(2);

    $cacheQueries = RoiQuery::cacheQueries()->get();
    expect($cacheQueries)->toHaveCount(1);

    // Test by country scope
    $esQueries = RoiQuery::byCountry('ES')->get();
    expect($esQueries)->toHaveCount(2);

    // Test by user scope
    $userQueries = RoiQuery::byUser('user-123')->get();
    expect($userQueries)->toHaveCount(1);

    // Test used cache scope
    $usedCacheQueries = RoiQuery::usedCache()->get();
    expect($usedCacheQueries)->toHaveCount(1);
});

it('can check if within legal retention period', function () {
    $query = RoiQuery::create([
        'user_id'               => 'user-123',
        'vat_code'              => 'ESB12345678',
        'country_code'          => 'ES',
        'query_type'            => RoiQuery::QUERY_TYPE_API,
        'queried_at'            => now(),
        'legal_retention_until' => now()->addDays(2555), // 7 years
    ]);

    expect($query->isWithinLegalRetention())->toBeTrue();

    // Create expired query
    $expiredQuery = RoiQuery::create([
        'user_id'               => 'user-456',
        'vat_code'              => 'ESB87654321',
        'country_code'          => 'ES',
        'query_type'            => RoiQuery::QUERY_TYPE_API,
        'queried_at'            => now()->subDays(3000), // 8+ years ago
        'legal_retention_until' => now()->subDays(445), // Expired
    ]);

    expect($expiredQuery->isWithinLegalRetention())->toBeFalse();
});

it('can get expired legal retention queries', function () {
    // Create valid query
    RoiQuery::create([
        'user_id'               => 'user-123',
        'vat_code'              => 'ESB12345678',
        'country_code'          => 'ES',
        'query_type'            => RoiQuery::QUERY_TYPE_API,
        'queried_at'            => now(),
        'legal_retention_until' => now()->addDays(2555),
    ]);

    // Create expired query
    RoiQuery::create([
        'user_id'               => 'user-456',
        'vat_code'              => 'ESB87654321',
        'country_code'          => 'ES',
        'query_type'            => RoiQuery::QUERY_TYPE_API,
        'queried_at'            => now()->subDays(3000),
        'legal_retention_until' => now()->subDays(445),
    ]);

    $expiredQueries = RoiQuery::getExpiredLegalRetention();

    expect($expiredQueries)->toHaveCount(1);
    expect($expiredQueries->first()->user_id)->toBe('user-456');
});

it('can cleanup expired legal retention queries', function () {
    // Create expired query
    RoiQuery::create([
        'user_id'               => 'user-456',
        'vat_code'              => 'ESB87654321',
        'country_code'          => 'ES',
        'query_type'            => RoiQuery::QUERY_TYPE_API,
        'queried_at'            => now()->subDays(3000),
        'legal_retention_until' => now()->subDays(445),
    ]);

    $deletedCount = RoiQuery::cleanupExpiredLegalRetention();

    expect($deletedCount)->toBe(1);
    expect(RoiQuery::count())->toBe(0);
});

it('can get query statistics for a user', function () {
    // Create various queries for user-123
    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
        'queried_at'   => now()->subDays(5),
    ]);

    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB87654321',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_CACHE,
        'queried_at'   => now()->subDays(3),
    ]);

    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'FRB12345678',
        'country_code' => 'FR',
        'query_type'   => RoiQuery::QUERY_TYPE_CACHE,
        'queried_at'   => now()->subDays(1),
    ]);

    // Create query for different user
    RoiQuery::create([
        'user_id'      => 'user-456',
        'vat_code'     => 'ESB99999999',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
        'queried_at'   => now()->subDays(2),
    ]);

    $stats = RoiQuery::getQueryStatistics('user-123');

    expect($stats['total'])->toBe(3);
    expect($stats['api_queries'])->toBe(1);
    expect($stats['cache_queries'])->toBe(2);
    expect($stats['cache_hit_ratio'])->toBe(66.67);
});

it('can get query statistics with date range', function () {
    $startDate = now()->subDays(5);
    $endDate   = now();

    // Create query within range
    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
        'queried_at'   => now()->subDays(3),
    ]);

    // Create query outside range
    RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB87654321',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_CACHE,
        'queried_at'   => now()->subDays(10),
    ]);

    $stats = RoiQuery::getQueryStatistics('user-123', $startDate, $endDate);

    expect($stats['total'])->toBe(1);
    expect($stats['api_queries'])->toBe(1);
    expect($stats['cache_queries'])->toBe(0);
    expect($stats['cache_hit_ratio'])->toBe(0.0);
});

it('can get legal retention days from config', function () {
    config(['larabill.roi_verification.legal_retention_days' => 2555]); // 7 years

    expect(RoiQuery::getLegalRetentionDays())->toBe(2555);
});

it('automatically sets legal retention period on creation', function () {
    config(['larabill.roi_verification.legal_retention_days' => 2555]);

    $query = RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
        'queried_at'   => now(),
    ]);

    expect($query->legal_retention_until)->toBeGreaterThan(now()->addDays(2550));
    expect($query->legal_retention_until)->toBeLessThan(now()->addDays(2560));
});

it('automatically sets queried_at on creation', function () {
    $beforeCreation = now();

    $query = RoiQuery::create([
        'user_id'      => 'user-123',
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
    ]);

    $afterCreation = now();

    expect($query->queried_at->timestamp)->toBeGreaterThanOrEqualTo($beforeCreation->timestamp);
    expect($query->queried_at->timestamp)->toBeLessThanOrEqualTo($afterCreation->timestamp);
});
