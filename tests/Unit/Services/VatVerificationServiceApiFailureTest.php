<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\VatApiIntegrationService;
use AichaDigital\Larabill\Services\VatVerificationService;
use Illuminate\Support\Facades\Http;

it('returns error when all apis fail', function () {
    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'test_key']);
    config(['larabill.vat_apis.apilayer.key' => 'test_key']);

    // Mock HTTP to return 500 errors for both APIs
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 500),
        'http://apilayer.net/api/validate*'         => Http::response([], 500),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('vat_code');
    expect($result)->toHaveKey('country_code');
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('all_apis_failed');
    expect($result)->toHaveKey('error');
    expect($result)->toHaveKey('cached');

    expect($result['is_valid'])->toBeFalse();
    expect($result['all_apis_failed'])->toBeTrue();
    expect($result['vat_code'])->toBe('ESB12345678');
    expect($result['country_code'])->toBe('ES');
    expect($result['error'])->toContain('HTTP error or exception');
    expect($result['cached'])->toBeFalse();
});

it('returns error when primary api fails but fallback succeeds', function () {
    // Configure API keys to force HTTP calls
    config(['larabill.vat_apis.abstractapi.key' => 'test_key']);
    config(['larabill.vat_apis.apilayer.key' => 'test_key']);

    // Mock HTTP to return 500 for primary API but success for fallback
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 500),
        'http://apilayer.net/api/validate*'         => Http::response([
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
    expect($result)->toHaveKey('all_apis_failed');
    expect($result)->toHaveKey('cached');

    expect($result['is_valid'])->toBeTrue();
    expect($result['all_apis_failed'])->toBeFalse();
    expect($result['cached'])->toBeFalse();
});
