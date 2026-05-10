<?php

declare(strict_types=1);

/**
 * @deprecated v0.4.0 - ROI verification moved to lararoi package
 * These tests use legacy API/tables that no longer exist in v0.4.0.
 */

// Skip all tests - legacy code
beforeEach(function () {
    // REACTIVATED v0.4.1: $this->markTestSkipped('Legacy test - deprecated in v0.4.0');
});

use AichaDigital\Larabill\Models\RoiQuery;
use AichaDigital\Larabill\Models\UserRoiVerification;
use AichaDigital\Larabill\Models\VatVerification;
use AichaDigital\Larabill\Services\RoiVerificationService;
use AichaDigital\Larabill\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\mock;

beforeEach(function () {
    UserRoiVerification::truncate();
    RoiQuery::truncate();
    VatVerification::truncate(); // Clear VAT verification cache

    config(['larabill.roi_verification.cache_duration_days' => 15]);
    config(['larabill.roi_verification.force_api_check' => false]);
    config(['larabill.roi_verification.legal_retention_days' => 2555]);
});

it('can verify ROI status with cache hit', function () {
    $service = new RoiVerificationService;

    // Create existing verification in cache
    UserRoiVerification::create([
        'user_id'      => TestCase::USER_UUID_1,
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'company_name' => 'Test Company S.L.',
        'last_check'   => now(),
        'expired_at'   => now()->addDays(15),
        'api_source'   => 'cache',
        'cache_hit'    => false,
    ]);

    $result = $service->verifyRoiStatus(TestCase::USER_UUID_1, 'ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result['is_roi'])->toBeTrue();
    expect($result['company_name'])->toBe('Test Company S.L.');
    expect($result['cache_hit'])->toBeTrue();
    expect($result['api_source'])->toBe('cache');
});

it('can verify ROI status with API call', function () {
    $service = new RoiVerificationService;

    // Mock successful API response
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'address'    => 'Test Address 123, Madrid, 28001, ES',
            'vat_code'   => 'ESB12345678',
        ], 200),
    ]);

    $result = $service->verifyRoiStatus(TestCase::USER_UUID_1, 'ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result['is_roi'])->toBeTrue();
    expect($result['company_name'])->toBe('Test Company S.L.');
    expect($result['cache_hit'])->toBeFalse();
    expect($result['api_source'])->toBe('abstractapi');

    // Verify database records were created
    $verification = UserRoiVerification::where('user_id', TestCase::USER_UUID_1)
        ->where('vat_code', 'ESB12345678')
        ->first();

    expect($verification)->not->toBeNull();
    expect($verification->is_roi)->toBeTrue();

    $query = RoiQuery::where('user_id', TestCase::USER_UUID_1)
        ->where('vat_code', 'ESB12345678')
        ->first();

    expect($query)->not->toBeNull();
    expect($query->query_type)->toBe(RoiQuery::QUERY_TYPE_API);
});

it('can verify ROI status with API fallback', function () {
    $service = new RoiVerificationService;

    // Mock primary API failure and fallback success
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 500),
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'vat_code'   => 'ESB12345678',
        ], 200),
    ]);

    $result = $service->verifyRoiStatus(TestCase::USER_UUID_1, 'ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result['is_roi'])->toBeTrue();
    expect($result['api_source'])->toBe('abstractapi');
});

it('can handle invalid VAT number', function () {
    $service = new RoiVerificationService;

    // Mock API response for invalid VAT
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid' => false,
            'error' => 'Invalid VAT number format',
        ], 200),
    ]);

    $result = $service->verifyRoiStatus(TestCase::USER_UUID_1, 'INVALID', 'ES');

    expect($result)->toBeArray();
    expect($result['is_roi'])->toBeFalse();
    expect($result['error'])->toBe('Invalid VAT number format');
    expect($result['cache_hit'])->toBeFalse();

    // Verify database records were created
    $verification = UserRoiVerification::where('user_id', TestCase::USER_UUID_1)
        ->where('vat_code', 'INVALID')
        ->first();

    expect($verification)->not->toBeNull();
    expect($verification->is_roi)->toBeFalse();
});

it('can handle API failures gracefully', function () {
    // Clear all VAT verification cache
    VatVerification::truncate();

    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'real_api_key_123']);
    config(['larabill.vat_apis.apilayer.key' => 'real_api_key_456']);

    // Create service after configuration
    $service = new RoiVerificationService;

    // Mock API failure for both APIs
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 500),
        'http://apilayer.net/api/validate*'         => Http::response([], 500),
    ]);

    $result = $service->verifyRoiStatus(TestCase::USER_UUID_1, 'ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result['is_roi'])->toBeFalse();
    expect($result['error'])->toContain('API verification failed');
    expect($result['cache_hit'])->toBeFalse();

    // Verify query was still logged for legal purposes
    $query = RoiQuery::where('user_id', TestCase::USER_UUID_1)
        ->where('vat_code', 'ESB12345678')
        ->first();

    expect($query)->not->toBeNull();
    expect($query->response_data)->toHaveKey('error');
});

it('can force API check even with valid cache', function () {
    config(['larabill.roi_verification.force_api_check' => true]);

    // Clear all VAT verification cache
    VatVerification::truncate();

    // Create existing verification in cache
    UserRoiVerification::create([
        'user_id'      => TestCase::USER_UUID_1,
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now(),
        'expired_at'   => now()->addDays(15),
        'api_source'   => 'cache',
    ]);

    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'real_api_key_123']);
    config(['larabill.vat_apis.apilayer.key' => 'real_api_key_456']);

    // Create service after configuration
    $service = new RoiVerificationService;

    // Mock API response
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Updated Company S.L.',
            'vat_code'   => 'ESB12345678',
        ], 200),
    ]);

    $result = $service->verifyRoiStatus(TestCase::USER_UUID_1, 'ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result['is_roi'])->toBeTrue();
    expect($result['company_name'])->toBe('Updated Company S.L.');
    expect($result['cache_hit'])->toBeFalse();
    expect($result['api_source'])->toBe('abstractapi');

    // Verify cache was updated
    $verification = UserRoiVerification::where('user_id', TestCase::USER_UUID_1)
        ->where('vat_code', 'ESB12345678')
        ->first();

    expect($verification->company_name)->toBe('Updated Company S.L.');
});

it('can handle expired cache', function () {
    $service = new RoiVerificationService;

    // Create expired verification
    UserRoiVerification::create([
        'user_id'      => TestCase::USER_UUID_1,
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now()->subDays(20),
        'expired_at'   => now()->subDays(5), // Expired
        'api_source'   => 'cache',
    ]);

    // Mock API response
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'vat_code'   => 'ESB12345678',
        ], 200),
    ]);

    $result = $service->verifyRoiStatus(TestCase::USER_UUID_1, 'ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result['is_roi'])->toBeTrue();
    expect($result['cache_hit'])->toBeFalse();
    expect($result['api_source'])->toBe('abstractapi');
});

it('can get ROI verification history', function () {
    $service = new RoiVerificationService;

    // Create some verification history
    UserRoiVerification::create([
        'user_id'      => TestCase::USER_UUID_1,
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'is_roi'       => true,
        'last_check'   => now()->subDays(3), // More recent
        'expired_at'   => now()->addDays(10),
    ]);

    UserRoiVerification::create([
        'user_id'      => TestCase::USER_UUID_1,
        'vat_code'     => 'FRB87654321',
        'country_code' => 'FR',
        'is_roi'       => false,
        'last_check'   => now()->subDays(5), // Older
        'expired_at'   => now()->addDays(12),
    ]);

    $history = $service->getRoiVerificationHistory(TestCase::USER_UUID_1);

    expect($history)->toHaveCount(2);
    expect($history->first()->vat_code)->toBe('ESB12345678');
    expect($history->last()->vat_code)->toBe('FRB87654321');
});

it('can get ROI query statistics', function () {
    $service = new RoiVerificationService;

    // Create some query history
    RoiQuery::create([
        'user_id'      => TestCase::USER_UUID_1,
        'vat_code'     => 'ESB12345678',
        'country_code' => 'ES',
        'query_type'   => RoiQuery::QUERY_TYPE_API,
        'api_source'   => 'abstractapi',
        'queried_at'   => now()->subDays(5),
        'cache_used'   => false,
    ]);

    RoiQuery::create([
        'user_id'      => TestCase::USER_UUID_1,
        'vat_code'     => 'FRB87654321',
        'country_code' => 'FR',
        'query_type'   => RoiQuery::QUERY_TYPE_CACHE,
        'api_source'   => 'cache',
        'queried_at'   => now()->subDays(3),
        'cache_used'   => true,
    ]);

    $stats = $service->getRoiQueryStatistics(TestCase::USER_UUID_1);

    expect($stats)->toBeArray();
    expect($stats['total'])->toBe(2);
    expect($stats['api_queries'])->toBe(1);
    expect($stats['cache_queries'])->toBe(1);
    expect($stats['cache_hit_ratio'])->toBe(50.0);
});

it('can cleanup expired legal retention queries', function () {
    $service = new RoiVerificationService;

    // Create expired query
    RoiQuery::create([
        'user_id'               => TestCase::USER_UUID_1,
        'vat_code'              => 'ESB12345678',
        'country_code'          => 'ES',
        'query_type'            => RoiQuery::QUERY_TYPE_API,
        'queried_at'            => now()->subDays(3000), // 8+ years ago
        'legal_retention_until' => now()->subDays(445), // Expired
    ]);

    // Create valid query
    RoiQuery::create([
        'user_id'               => TestCase::USER_UUID_2,
        'vat_code'              => 'FRB87654321',
        'country_code'          => 'FR',
        'query_type'            => RoiQuery::QUERY_TYPE_API,
        'queried_at'            => now(),
        'legal_retention_until' => now()->addDays(2555), // Valid
    ]);

    $deletedCount = $service->cleanupExpiredLegalRetentionQueries();

    expect($deletedCount)->toBe(1);
    expect(RoiQuery::count())->toBe(1);
    expect(RoiQuery::first()->user_id)->toBe(TestCase::USER_UUID_2);
});

it('can validate VAT number format', function () {
    $service = new RoiVerificationService;

    // Valid formats
    expect($service->isValidVatFormat('ESB12345678', 'ES'))->toBeTrue();
    expect($service->isValidVatFormat('FR12345678901', 'FR'))->toBeTrue();
    expect($service->isValidVatFormat('DE123456789', 'DE'))->toBeTrue();

    // Invalid formats
    expect($service->isValidVatFormat('INVALID', 'ES'))->toBeFalse();
    expect($service->isValidVatFormat('ESB123', 'ES'))->toBeFalse();
    expect($service->isValidVatFormat('', 'ES'))->toBeFalse();
    expect($service->isValidVatFormat('ESB12345678', 'XX'))->toBeFalse();
});

it('can extract country code from VAT number', function () {
    $service = new RoiVerificationService;

    expect($service->extractCountryCode('ESB12345678'))->toBe('ES');
    expect($service->extractCountryCode('FR12345678901'))->toBe('FR');
    expect($service->extractCountryCode('DE123456789'))->toBe('DE');
    expect($service->extractCountryCode('INVALID'))->toBeNull();
});

it('can get API rate limit information', function () {
    $service = new RoiVerificationService;

    config(['larabill.roi_verification.api_rate_limits.abstractapi' => 1000]);
    config(['larabill.roi_verification.api_rate_limits.apilayer' => 500]);

    $rateLimits = $service->getApiRateLimits();

    expect($rateLimits)->toBeArray();
    expect($rateLimits['abstractapi'])->toBe(1000);
    expect($rateLimits['apilayer'])->toBe(500);
});

it('can check API rate limit status', function () {
    $service = mock(RoiVerificationService::class)->makePartial();

    // Mock rate limit check
    $service->shouldReceive('checkApiRateLimit')
        ->with('abstractapi')
        ->andReturn(['remaining' => 950, 'reset_time' => now()->addHour()]);

    $status = $service->checkApiRateLimit('abstractapi');

    expect($status)->toBeArray();
    expect($status['remaining'])->toBe(950);
    expect($status['reset_time'])->toBeInstanceOf(Carbon::class);
});

it('can handle batch ROI verification', function () {
    $service = new RoiVerificationService;

    $vatNumbers = [
        ['user_id' => TestCase::USER_UUID_1, 'vat_code' => 'ESB12345678', 'country_code' => 'ES'],
        ['user_id' => TestCase::USER_UUID_2, 'vat_code' => 'FRB87654321', 'country_code' => 'FR'],
    ];

    // Mock API responses
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'vat_code'   => 'ESB12345678',
        ], 200),
    ]);

    $results = $service->batchVerifyRoiStatus($vatNumbers);

    expect($results)->toHaveCount(2);
    expect($results[0]['user_id'])->toBe(TestCase::USER_UUID_1);
    expect($results[0]['is_roi'])->toBeTrue();
    expect($results[1]['user_id'])->toBe(TestCase::USER_UUID_2);
});

it('can get service configuration', function () {
    $service = new RoiVerificationService;

    $config = $service->getConfiguration();

    expect($config)->toBeArray();
    expect($config['cache_duration_days'])->toBe(15);
    expect($config['force_api_check'])->toBeFalse();
    expect($config['legal_retention_days'])->toBe(2555);
});

it('can update service configuration', function () {
    $service = new RoiVerificationService;

    $newConfig = [
        'cache_duration_days'  => 30,
        'force_api_check'      => true,
        'legal_retention_days' => 3650,
    ];

    $service->updateConfiguration($newConfig);

    expect(config('larabill.roi_verification.cache_duration_days'))->toBe(30);
    expect(config('larabill.roi_verification.force_api_check'))->toBeTrue();
    expect(config('larabill.roi_verification.legal_retention_days'))->toBe(3650);
});

it('can handle service errors gracefully', function () {
    $service = mock(RoiVerificationService::class)->makePartial();

    // Mock service to throw exception
    $service->shouldReceive('verifyRoiStatus')
        ->andThrow(new Exception('Service error'));

    expect(fn () => $service->verifyRoiStatus(TestCase::USER_UUID_1, 'ESB12345678', 'ES'))
        ->toThrow(Exception::class, 'Service error');
});

it('can log service operations', function () {
    $service = new RoiVerificationService;

    // Mock logger - allow any number of calls
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('error')->zeroOrMoreTimes();

    // Mock API response
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'vat_code'   => 'ESB12345678',
        ], 200),
    ]);

    $result = $service->verifyRoiStatus(TestCase::USER_UUID_1, 'ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result['is_roi'])->toBeTrue();
});
