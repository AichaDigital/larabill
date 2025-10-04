<?php

declare(strict_types=1);

use AichaDigital\Larabill\Services\VatApiIntegrationService;
use Illuminate\Support\Facades\Http;

describe('VatApiIntegrationService', function () {
    beforeEach(function () {
        // Configure API keys for testing
        config(['larabill.vat_apis.abstractapi.key' => 'test_abstractapi_key']);
        config(['larabill.vat_apis.apilayer.key' => 'test_apilayer_key']);
        config(['larabill.vat_apis.abstractapi.url' => 'https://vat.abstractapi.com/v1/validate/']);
        config(['larabill.vat_apis.apilayer.url' => 'http://apilayer.net/api/validate']);
    });

    describe('AbstractAPI Integration', function () {
        it('can verify valid VAT number with AbstractAPI', function () {
            $service = new VatApiIntegrationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([
                    'valid' => true,
                    'vat_number' => 'ESB12345678',
                    'country' => [
                        'code' => 'ES',
                        'name' => 'Spain',
                    ],
                    'company' => [
                        'name' => 'Test Company S.L.',
                        'address' => 'Calle Test 123, 41001 Sevilla, España',
                    ],
                    'format_valid' => true,
                    'query' => 'ESB12345678',
                ], 200),
            ]);

            $result = $service->verifyWithAbstractApi('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeTrue();
            expect($result['vat_number'])->toBe('ESB12345678');
            expect($result['country_code'])->toBe('ES');
            expect($result['company_name'])->toBe('Test Company S.L.');
            expect($result['company_address'])->toBe('Calle Test 123, 41001 Sevilla, España');
            expect($result['api_source'])->toBe('abstractapi');
            expect($result['all_apis_failed'])->toBeFalse();

            Http::assertSent(function ($request) {
                return str_starts_with($request->url(), 'https://vat.abstractapi.com/v1/validate/') &&
                       $request['api_key'] === 'test_abstractapi_key' &&
                       $request['vat_number'] === 'ESB12345678' &&
                       $request['country_code'] === 'ES';
            });
        });

        it('can handle invalid VAT number with AbstractAPI', function () {
            $service = new VatApiIntegrationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([
                    'valid' => false,
                    'vat_number' => 'INVALID123',
                    'country' => [
                        'code' => 'ES',
                        'name' => 'Spain',
                    ],
                    'company' => null,
                    'format_valid' => false,
                    'query' => 'INVALID123',
                ], 200),
            ]);

            $result = $service->verifyWithAbstractApi('INVALID123', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeFalse();
            expect($result['vat_number'])->toBe('ESINVALID123');
            expect($result['country_code'])->toBe('ES');
            expect($result['company_name'])->toBeNull();
            expect($result['company_address'])->toBeNull();
            expect($result['api_source'])->toBe('abstractapi');
            expect($result['all_apis_failed'])->toBeFalse();
        });

        it('can handle AbstractAPI HTTP errors', function () {
            $service = new VatApiIntegrationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 500),
            ]);

            $result = $service->verifyWithAbstractApi('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeFalse();
            expect($result['error'])->toContain('HTTP error');
            expect($result['api_source'])->toBe('abstractapi');
            expect($result['all_apis_failed'])->toBeTrue();
        });

        it('can handle AbstractAPI network timeouts', function () {
            $service = new VatApiIntegrationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 408),
            ]);

            $result = $service->verifyWithAbstractApi('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeFalse();
            expect($result['error'])->toContain('HTTP error');
            expect($result['api_source'])->toBe('abstractapi');
            expect($result['all_apis_failed'])->toBeTrue();
        });
    });

    describe('APILayer Integration', function () {
        it('can verify valid VAT number with APILayer', function () {
            $service = new VatApiIntegrationService;

            Http::fake([
                'http://apilayer.net/api/validate*' => Http::response([
                    'valid' => true,
                    'vat_number' => 'FRB87654321',
                    'country_code' => 'FR',
                    'company_name' => 'French Company SARL',
                    'company_address' => '123 Rue de la Paix, 75001 Paris, France',
                ], 200),
            ]);

            $result = $service->verifyWithApiLayer('FRB87654321', 'FR');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeTrue();
            expect($result['vat_number'])->toBe('FRB87654321');
            expect($result['country_code'])->toBe('FR');
            expect($result['company_name'])->toBe('French Company SARL');
            expect($result['company_address'])->toBe('123 Rue de la Paix, 75001 Paris, France');
            expect($result['api_source'])->toBe('apilayer');
            expect($result['all_apis_failed'])->toBeFalse();

            Http::assertSent(function ($request) {
                return str_starts_with($request->url(), 'http://apilayer.net/api/validate') &&
                       $request['access_key'] === 'test_apilayer_key' &&
                       $request['vat_number'] === 'FRB87654321' &&
                       $request['country_code'] === 'FR';
            });
        });

        it('can handle invalid VAT number with APILayer', function () {
            $service = new VatApiIntegrationService;

            Http::fake([
                'http://apilayer.net/api/validate*' => Http::response([
                    'valid' => false,
                    'vat_number' => 'INVALID456',
                    'country_code' => 'FR',
                    'company_name' => null,
                    'company_address' => null,
                ], 200),
            ]);

            $result = $service->verifyWithApiLayer('INVALID456', 'FR');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeFalse();
            expect($result['vat_number'])->toBe('FRINVALID456');
            expect($result['country_code'])->toBe('FR');
            expect($result['company_name'])->toBeNull();
            expect($result['company_address'])->toBeNull();
            expect($result['api_source'])->toBe('apilayer');
            expect($result['all_apis_failed'])->toBeFalse();
        });

        it('can handle APILayer HTTP errors', function () {
            $service = new VatApiIntegrationService;

            Http::fake([
                'http://apilayer.net/api/validate*' => Http::response([], 503),
            ]);

            $result = $service->verifyWithApiLayer('FRB87654321', 'FR');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeFalse();
            expect($result['error'])->toContain('HTTP error');
            expect($result['api_source'])->toBe('apilayer');
            expect($result['all_apis_failed'])->toBeTrue();
        });

        it('can handle APILayer network timeouts', function () {
            $service = new VatApiIntegrationService;

            Http::fake([
                'http://apilayer.net/api/validate*' => Http::response([], 408),
            ]);

            $result = $service->verifyWithApiLayer('FRB87654321', 'FR');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeFalse();
            expect($result['error'])->toContain('HTTP error');
            expect($result['api_source'])->toBe('apilayer');
            expect($result['all_apis_failed'])->toBeTrue();
        });
    });

    describe('Mock Responses', function () {
        it('returns mock response when no API key configured for AbstractAPI', function () {
            config(['larabill.vat_apis.abstractapi.key' => null]);

            $service = new VatApiIntegrationService;
            $result = $service->verifyWithAbstractApi('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeTrue();
            expect($result['vat_number'])->toBe('ESB12345678');
            expect($result['country_code'])->toBe('ES');
            expect($result['company_name'])->toBe('Test Company S.L.');
            expect($result['api_source'])->toBe('abstractapi');
            expect($result['all_apis_failed'])->toBeFalse();
        });

        it('returns mock response when no API key configured for APILayer', function () {
            config(['larabill.vat_apis.apilayer.key' => null]);

            $service = new VatApiIntegrationService;
            $result = $service->verifyWithApiLayer('FRB87654321', 'FR');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeTrue();
            expect($result['vat_number'])->toBe('FRB87654321');
            expect($result['country_code'])->toBe('FR');
            expect($result['company_name'])->toBe('Updated Company S.L.');
            expect($result['api_source'])->toBe('apilayer');
            expect($result['all_apis_failed'])->toBeFalse();
        });
    });
});
