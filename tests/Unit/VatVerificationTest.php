<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\VatVerification;
use AichaDigital\Larabill\Services\{VatApiIntegrationService, VatVerificationService};

it('can verify a valid Spanish VAT number', function () {
    // Mock successful API response
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'address'    => 'Test Address 123, Madrid, 28001, ES',
            'vat_code' => 'ESB12345678',
        ], 200),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('vat_code');
    expect($result)->toHaveKey('country_code');
    expect($result)->toHaveKey('company_name');
    expect($result)->toHaveKey('api_source');
    expect($result['is_valid'])->toBeTrue();
    expect($result['vat_code'])->toBe('ESB12345678');
    expect($result['country_code'])->toBe('ES');
});

it('can verify an invalid VAT number', function () {
    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('INVALID', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result['is_valid'])->toBeFalse();
    expect($result['vat_code'])->toBe('INVALID');
    expect($result['country_code'])->toBe('ES');
});

it('caches verification results', function () {
    // Mock successful API response
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'address'    => 'Test Address 123, Madrid, 28001, ES',
            'vat_code' => 'ESB12345678',
        ], 200),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    // First call should create cache
    $result1 = $service->verifyVatNumber('ESB12345678', 'ES');
    expect($result1)->toHaveKey('cached');
    expect($result1['cached'])->toBeFalse();

    // Second call should use cache
    $result2 = $service->verifyVatNumber('ESB12345678', 'ES');
    expect($result2)->toHaveKey('cached');
    expect($result2['cached'])->toBeTrue();
    expect($result2['is_valid'])->toBe($result1['is_valid']);
});

it('saves verification to database', function () {
    // Mock successful API response
    Http::fake([
        'https://vat.abstractapi.com/v1/validate/*' => Http::response([
            'valid'      => true,
            'company'    => 'Test Company S.L.',
            'address'    => 'Test Address 123, Madrid, 28001, ES',
            'vat_code' => 'ESB12345678',
        ], 200),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    // Check if verification was saved to database
    $verification = VatVerification::findByVatNumberAndCountry('ESB12345678', 'ES');
    expect($verification)->not->toBeNull();
    expect($verification->is_valid)->toBeTrue();
    expect($verification->vat_code)->toBe('ESB12345678');
    expect($verification->country_code)->toBe('ES');
    expect($verification->company_name)->toBe('Test Company S.L.');
});

it('uses preferred API from configuration', function () {
    // Set preferred API to apilayer
    config(['larabill.vat_apis.preferred_api' => 'apilayer']);

    // Mock successful API response
    Http::fake([
        'http://apilayer.net/api/validate*' => Http::response([
            'valid'           => true,
            'company_name'    => 'German Company GmbH',
            'company_address' => 'Test Address 123, Berlin, 10115, DE',
            'vat_code'      => 'DE123456789',
        ], 200),
    ]);

    $apiIntegration = new VatApiIntegrationService;
    $service        = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('DE123456789', 'DE');

    expect($result['api_source'])->toBe('apilayer');
    expect($result['is_valid'])->toBeTrue();
    expect($result['company_name'])->toBe('German Company GmbH');
});
