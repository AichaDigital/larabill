<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\{VatApiIntegrationService, VatVerificationService};
use Illuminate\Support\Facades\Http;

it('handles rate limiting from AbstractAPI', function () {
    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'test_key']);
    config(['larabill.vat_apis.apilayer.key' => 'test_key']);

    // Mock HTTP to return rate limit error for primary API
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'error'   => 'Rate limit exceeded',
            'message' => 'You have exceeded your rate limit',
        ], 429),
        'http://apilayer.net/api/validate*' => Http::response([
            'valid'           => true,
            'vat_code'        => 'ESB12345678',
            'company_name'    => 'Test Company S.L.',
            'company_address' => 'Test Address',
        ], 200),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('fallback_used');
    expect($result)->toHaveKey('rate_limit_hit');

    expect($result['is_valid'])->toBeTrue();
    expect($result['fallback_used'])->toBeTrue();
    expect($result['rate_limit_hit'])->toBeTrue();
    expect($result['api_source'])->toBe('apilayer');
});

it('handles rate limiting from APILayer', function () {
    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'test_key']);
    config(['larabill.vat_apis.apilayer.key' => 'test_key']);

    // Mock HTTP to return rate limit error for both APIs
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'error' => 'Rate limit exceeded',
        ], 429),
        'http://apilayer.net/api/validate*' => Http::response([
            'error' => 'Rate limit exceeded',
        ], 429),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('rate_limit_hit');
    expect($result)->toHaveKey('all_apis_failed');
    expect($result)->toHaveKey('fallback_used');

    expect($result['rate_limit_hit'])->toBeTrue();
    expect($result['all_apis_failed'])->toBeFalse(); // Fallback succeeded
    expect($result['fallback_used'])->toBeTrue();
    expect($result['is_valid'])->toBeFalse(); // Fallback API also rate limited
});

it('handles rate limiting with cache fallback', function () {
    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'test_key']);
    config(['larabill.vat_apis.apilayer.key' => 'test_key']);

    // Create a cached verification first with rate limiting info
    $cachedVerification = \AichaDigital\Larabill\Models\VatVerification::create([
        'vat_code'        => 'ESB12345678',
        'country_code'    => 'ES',
        'is_valid'        => true,
        'company_name'    => 'Cached Company S.L.',
        'company_address' => 'Cached Address',
        'api_source'      => 'abstractapi',
        'response_data'   => ['valid' => true, 'rate_limit_hit' => true],
        'checked_at'      => now(),
        'expires_at'      => now()->addDays(30),
    ]);

    // Mock HTTP to return rate limit error for both APIs
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'error' => 'Rate limit exceeded',
        ], 429),
        'http://apilayer.net/api/validate*' => Http::response([
            'error' => 'Rate limit exceeded',
        ], 429),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('cached');
    expect($result)->toHaveKey('rate_limit_hit');

    expect($result['is_valid'])->toBeTrue();
    expect($result['cached'])->toBeTrue();
    expect($result['rate_limit_hit'])->toBeTrue();
    expect($result['company_name'])->toBe('Cached Company S.L.');
});

it('handles rate limiting with expired cache', function () {
    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'test_key']);
    config(['larabill.vat_apis.apilayer.key' => 'test_key']);

    // Create an expired cached verification
    $expiredVerification = \AichaDigital\Larabill\Models\VatVerification::create([
        'vat_code'        => 'ESB12345678',
        'country_code'    => 'ES',
        'is_valid'        => true,
        'company_name'    => 'Expired Company S.L.',
        'company_address' => 'Expired Address',
        'api_source'      => 'abstractapi',
        'response_data'   => ['valid' => true],
        'checked_at'      => now()->subDays(35),
        'expires_at'      => now()->subDays(5), // Expired
    ]);

    // Mock HTTP to return rate limit error for both APIs
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'error' => 'Rate limit exceeded',
        ], 429),
        'http://apilayer.net/api/validate*' => Http::response([
            'error' => 'Rate limit exceeded',
        ], 429),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('rate_limit_hit');
    expect($result)->toHaveKey('all_apis_failed');
    expect($result)->toHaveKey('fallback_used');

    expect($result['rate_limit_hit'])->toBeTrue();
    expect($result['all_apis_failed'])->toBeFalse(); // Fallback succeeded
    expect($result['fallback_used'])->toBeTrue();
    expect($result['is_valid'])->toBeFalse(); // Fallback API also rate limited
});

it('handles rate limiting with mock fallback', function () {
    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'test_key']);
    config(['larabill.vat_apis.apilayer.key' => 'test_key']);

    // Mock HTTP to return rate limit error for both APIs
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'error' => 'Rate limit exceeded',
        ], 429),
        'http://apilayer.net/api/validate*' => Http::response([
            'error' => 'Rate limit exceeded',
        ], 429),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('rate_limit_hit');
    expect($result)->toHaveKey('all_apis_failed');
    expect($result)->toHaveKey('fallback_used');

    expect($result['rate_limit_hit'])->toBeTrue();
    expect($result['all_apis_failed'])->toBeFalse(); // Fallback succeeded
    expect($result['fallback_used'])->toBeTrue();
    expect($result['is_valid'])->toBeFalse(); // Fallback API also rate limited
});
