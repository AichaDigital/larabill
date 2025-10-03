<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\VatVerificationService;
use AichaDigital\Larabill\Services\VatApiIntegrationService;
use AichaDigital\Larabill\Models\VatVerification;

it('can verify a valid Spanish VAT number', function () {
    $apiIntegration = new VatApiIntegrationService();
    $service = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('vat_number');
    expect($result)->toHaveKey('country_code');
    expect($result)->toHaveKey('company_name');
    expect($result)->toHaveKey('api_source');
    expect($result['is_valid'])->toBeTrue();
    expect($result['vat_number'])->toBe('ESB12345678');
    expect($result['country_code'])->toBe('ES');
});

it('can verify an invalid VAT number', function () {
    $apiIntegration = new VatApiIntegrationService();
    $service = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('INVALID', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result['is_valid'])->toBeFalse();
    expect($result['vat_number'])->toBe('INVALID');
    expect($result['country_code'])->toBe('ES');
});

it('caches verification results', function () {
    $apiIntegration = new VatApiIntegrationService();
    $service = new VatVerificationService($apiIntegration);

    // First call should create cache
    $result1 = $service->verifyVatNumber('ESB12345678', 'ES');
    expect($result1)->not->toHaveKey('cached');

    // Second call should use cache
    $result2 = $service->verifyVatNumber('ESB12345678', 'ES');
    expect($result2)->toHaveKey('cached');
    expect($result2['cached'])->toBeTrue();
    expect($result2['is_valid'])->toBe($result1['is_valid']);
});

it('saves verification to database', function () {
    $apiIntegration = new VatApiIntegrationService();
    $service = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    // Check if verification was saved to database
    $verification = VatVerification::findByVatNumberAndCountry('ESB12345678', 'ES');
    expect($verification)->not->toBeNull();
    expect($verification->is_valid)->toBeTrue();
    expect($verification->vat_number)->toBe('ESB12345678');
    expect($verification->country_code)->toBe('ES');
    expect($verification->company_name)->toBe('AichaDigital S.L.');
});

it('uses preferred API from configuration', function () {
    // Set preferred API to apilayer
    config(['larabill.vat_apis.preferred_api' => 'apilayer']);
    
    $apiIntegration = new VatApiIntegrationService();
    $service = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('DE123456789', 'DE');

    expect($result['api_source'])->toBe('apilayer');
    expect($result['is_valid'])->toBeTrue();
    expect($result['company_name'])->toBe('German Company GmbH');
});
