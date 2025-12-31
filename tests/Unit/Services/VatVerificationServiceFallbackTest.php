<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\VatVerification;
use AichaDigital\Larabill\Services\VatApiIntegrationService;
use AichaDigital\Larabill\Services\VatVerificationService;

it('uses primary API when it works correctly', function () {
    // Mock the API integration service
    $apiIntegration = Mockery::mock(VatApiIntegrationService::class);

    // Configure primary API to return valid response
    $apiIntegration->shouldReceive('verifyWithAbstractApi')
        ->with('ESB12345678', 'ES')
        ->once()
        ->andReturn([
            'is_valid'        => true,
            'vat_code'        => 'ESB12345678',
            'country_code'    => 'ES',
            'company_name'    => 'Test Company S.L.',
            'company_address' => 'Test Address 123',
            'api_source'      => 'abstractapi',
            'response_data'   => ['valid' => true],
        ]);

    // Fallback API should not be called
    $apiIntegration->shouldNotReceive('verifyWithApiLayer');

    $service = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result['is_valid'])->toBeTrue();
    expect($result['vat_code'])->toBe('ESB12345678');
    expect($result['country_code'])->toBe('ES');
    expect($result['api_source'])->toBe('abstractapi');
    expect($result)->not->toHaveKey('fallback_used');
});

it('falls back to secondary API when primary fails', function () {
    // Mock the API integration service
    $apiIntegration = Mockery::mock(VatApiIntegrationService::class);

    // Configure primary API to throw exception
    $apiIntegration->shouldReceive('verifyWithAbstractApi')
        ->with('ESB12345678', 'ES')
        ->once()
        ->andThrow(new Exception('Primary API failed'));

    // Configure fallback API to return valid response
    $apiIntegration->shouldReceive('verifyWithApiLayer')
        ->with('ESB12345678', 'ES')
        ->once()
        ->andReturn([
            'is_valid'        => true,
            'vat_code'        => 'ESB12345678',
            'country_code'    => 'ES',
            'company_name'    => 'Test Company S.L.',
            'company_address' => 'Test Address 123',
            'api_source'      => 'apilayer',
            'response_data'   => ['valid' => true],
        ]);

    $service = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result['is_valid'])->toBeTrue();
    expect($result['api_source'])->toBe('apilayer');
    expect($result)->toHaveKey('fallback_used');
    expect($result['fallback_used'])->toBeTrue();
    expect($result['primary_api_failed'])->toBe('abstractapi');
});

it('uses mock response when both APIs fail', function () {
    // Mock the API integration service
    $apiIntegration = Mockery::mock(VatApiIntegrationService::class);

    // Configure both APIs to throw exceptions
    $apiIntegration->shouldReceive('verifyWithAbstractApi')
        ->with('ESB12345678', 'ES')
        ->once()
        ->andThrow(new Exception('Primary API failed'));

    $apiIntegration->shouldReceive('verifyWithApiLayer')
        ->with('ESB12345678', 'ES')
        ->once()
        ->andThrow(new Exception('Fallback API failed'));

    $service = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    expect($result['is_valid'])->toBeTrue(); // Default mock response
    expect($result['api_source'])->toBe('abstractapi');
    expect($result)->toHaveKey('mock_fallback');
    expect($result['mock_fallback'])->toBeTrue();
    expect($result)->toHaveKey('all_apis_failed');
    expect($result['all_apis_failed'])->toBeTrue();
});

it('accepts mock responses as valid when no API keys configured', function () {
    // Set environment to use mock responses (no real API keys)
    config(['larabill.vat_apis.abstractapi.key' => 'your_abstractapi_key_here']);
    config(['larabill.vat_apis.apilayer.key' => 'your_apilayer_key_here']);

    // Mock the API integration service
    $apiIntegration = Mockery::mock(VatApiIntegrationService::class);

    // Configure primary API to return mock response
    $apiIntegration->shouldReceive('verifyWithAbstractApi')
        ->with('ESB12345678', 'ES')
        ->once()
        ->andReturn([
            'is_valid'        => true,
            'vat_code'        => 'ESB12345678',
            'country_code'    => 'ES',
            'company_name'    => 'Test Company S.L.',
            'company_address' => 'Test Address 123',
            'api_source'      => 'abstractapi',
            'response_data'   => ['valid' => true],
        ]);

    // Fallback API should not be called since mock is accepted as valid
    $apiIntegration->shouldNotReceive('verifyWithApiLayer');

    $service = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('ESB12345678', 'ES');

    // Should use primary API mock response without fallback
    expect($result['api_source'])->toBe('abstractapi');
    expect($result)->not->toHaveKey('fallback_used');
    expect($result['is_valid'])->toBeTrue();
});

it('caches fallback results correctly', function () {
    // Mock the API integration service
    $apiIntegration = Mockery::mock(VatApiIntegrationService::class);

    // Configure primary API to fail
    $apiIntegration->shouldReceive('verifyWithAbstractApi')
        ->with('ESB12345678', 'ES')
        ->once()
        ->andThrow(new Exception('Primary API failed'));

    // Configure fallback API to succeed
    $apiIntegration->shouldReceive('verifyWithApiLayer')
        ->with('ESB12345678', 'ES')
        ->once()
        ->andReturn([
            'is_valid'        => true,
            'vat_code'        => 'ESB12345678',
            'country_code'    => 'ES',
            'company_name'    => 'Test Company S.L.',
            'company_address' => 'Test Address 123',
            'api_source'      => 'apilayer',
            'response_data'   => ['valid' => true],
        ]);

    $service = new VatVerificationService($apiIntegration);

    // First call should use fallback
    $result1 = $service->verifyVatNumber('ESB12345678', 'ES');
    expect($result1['fallback_used'])->toBeTrue();

    // Second call should use cache
    $result2 = $service->verifyVatNumber('ESB12345678', 'ES');
    expect($result2)->toHaveKey('cached');
    expect($result2['cached'])->toBeTrue();
    expect($result2['api_source'])->toBe('apilayer');

    // Verify it was saved to database
    $verification = VatVerification::findByVatCodeAndCountry('ESB12345678', 'ES');
    expect($verification)->not->toBeNull();
    expect($verification->api_source)->toBe('apilayer');
});

it('handles different primary API configurations', function () {
    // Test with API Layer as primary
    config(['larabill.vat_apis.preferred_api' => 'apilayer']);

    $apiIntegration = Mockery::mock(VatApiIntegrationService::class);

    // Configure API Layer (primary) to fail
    $apiIntegration->shouldReceive('verifyWithApiLayer')
        ->with('DE123456789', 'DE')
        ->once()
        ->andThrow(new Exception('API Layer failed'));

    // Configure AbstractAPI (fallback) to succeed
    $apiIntegration->shouldReceive('verifyWithAbstractApi')
        ->with('DE123456789', 'DE')
        ->once()
        ->andReturn([
            'is_valid'        => true,
            'vat_code'        => 'DE123456789',
            'country_code'    => 'DE',
            'company_name'    => 'German Company GmbH',
            'company_address' => 'German Address 123',
            'api_source'      => 'abstractapi',
            'response_data'   => ['valid' => true],
        ]);

    $service = new VatVerificationService($apiIntegration);

    $result = $service->verifyVatNumber('DE123456789', 'DE');

    expect($result['is_valid'])->toBeTrue();
    expect($result['api_source'])->toBe('abstractapi');
    expect($result)->toHaveKey('fallback_used');
    expect($result['fallback_used'])->toBeTrue();
    expect($result['primary_api_failed'])->toBe('apilayer');
});
