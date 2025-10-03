<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\VatApiIntegrationService;

it('can verify VAT with AbstractAPI mock response', function () {
    $service = new VatApiIntegrationService;

    $result = $service->verifyWithAbstractApi('ESB12345678', 'ES');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('vat_number');
    expect($result)->toHaveKey('country_code');
    expect($result)->toHaveKey('company_name');
    expect($result)->toHaveKey('api_source');
    expect($result['api_source'])->toBe('abstractapi');
    expect($result['vat_number'])->toBe('ESB12345678');
    expect($result['country_code'])->toBe('ES');
});

it('can verify VAT with API Layer mock response', function () {
    $service = new VatApiIntegrationService;

    $result = $service->verifyWithApiLayer('DE123456789', 'DE');

    expect($result)->toBeArray();
    expect($result)->toHaveKey('is_valid');
    expect($result)->toHaveKey('vat_number');
    expect($result)->toHaveKey('country_code');
    expect($result)->toHaveKey('company_name');
    expect($result)->toHaveKey('api_source');
    expect($result['api_source'])->toBe('apilayer');
    expect($result['vat_number'])->toBe('DE123456789');
    expect($result['country_code'])->toBe('DE');
});

it('returns valid response for known VAT numbers', function () {
    $service = new VatApiIntegrationService;

    $result = $service->verifyWithAbstractApi('ESB12345678', 'ES');

    expect($result['is_valid'])->toBeTrue();
    expect($result['company_name'])->toBe('AichaDigital S.L.');
    expect($result['company_address'])->toBe('Calle Test 123, 41001 Sevilla, España');
});

it('returns invalid response for invalid VAT numbers', function () {
    $service = new VatApiIntegrationService;

    $result = $service->verifyWithAbstractApi('INVALID', 'ES');

    expect($result['is_valid'])->toBeFalse();
    expect($result['company_name'])->toBeNull();
    expect($result['company_address'])->toBeNull();
});

it('includes response data in result', function () {
    $service = new VatApiIntegrationService;

    $result = $service->verifyWithAbstractApi('ESB12345678', 'ES');

    expect($result)->toHaveKey('response_data');
    expect($result['response_data'])->toBeArray();
    expect($result['response_data']['valid'])->toBeTrue();
    expect($result['response_data']['vat_number'])->toBe('ESB12345678');
});

it('handles different country VAT numbers', function () {
    $service = new VatApiIntegrationService;

    $testCases = [
        ['DE123456789', 'DE', 'German Company GmbH'],
        ['FR12345678901', 'FR', 'Société Française S.A.S.'],
        ['GB123456789', 'GB', 'British Company Ltd.'],
    ];

    foreach ($testCases as [$vatNumber, $countryCode, $expectedCompany]) {
        $result = $service->verifyWithAbstractApi($vatNumber, $countryCode);

        expect($result['is_valid'])->toBeTrue();
        expect($result['vat_number'])->toBe($vatNumber);
        expect($result['country_code'])->toBe($countryCode);
        expect($result['company_name'])->toBe($expectedCompany);
    }
});

it('uses mock responses when API keys are not configured', function () {
    // Set invalid API keys
    config(['larabill.vat_apis.abstractapi.key' => 'your_abstractapi_key_here']);
    config(['larabill.vat_apis.apilayer.key' => 'your_apilayer_key_here']);

    $service = new VatApiIntegrationService;

    $result = $service->verifyWithAbstractApi('ESB12345678', 'ES');

    expect($result['is_valid'])->toBeTrue();
    expect($result['company_name'])->toBe('AichaDigital S.L.');
    expect($result['api_source'])->toBe('abstractapi');
});

it('falls back to default mock when VAT number not in stubs', function () {
    $service = new VatApiIntegrationService;

    $result = $service->verifyWithAbstractApi('UNKNOWN123', 'ES');

    expect($result['is_valid'])->toBeTrue(); // Default is valid for non-INVALID
    expect($result['company_name'])->toBe('Test Company S.L.');
    expect($result['company_address'])->toBe('Test Address 123');
});

it('returns invalid for INVALID VAT number in default mock', function () {
    $service = new VatApiIntegrationService;

    $result = $service->verifyWithAbstractApi('INVALID', 'ES');

    expect($result['is_valid'])->toBeFalse();
    expect($result['company_name'])->toBeNull();
    expect($result['company_address'])->toBeNull();
});
