<?php

declare(strict_types=1);

use AichaDigital\Larabill\Models\VatVerification;
use AichaDigital\Larabill\Services\VatVerificationService;
use Illuminate\Support\Facades\Http;

describe('VatVerification Integration Tests', function () {
    beforeEach(function () {
        VatVerification::truncate();

        // Configure API keys for testing
        config(['larabill.vat_apis.abstractapi.key' => 'test_abstractapi_key']);
        config(['larabill.vat_apis.apilayer.key' => 'test_apilayer_key']);
        config(['larabill.vat_apis.abstractapi.url' => 'https://vat.abstractapi.com/v1/validate/']);
        config(['larabill.vat_apis.apilayer.url' => 'http://apilayer.net/api/validate']);
        config(['larabill.vat_apis.preferred_api' => 'abstractapi']);
    });

    describe('API Failover Process', function () {
        it('uses primary API when it succeeds', function () {
            $service = new VatVerificationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([
                    'valid'      => true,
                    'vat_code'   => 'ESB12345678',
                    'country'    => [
                        'code' => 'ES',
                        'name' => 'Spain',
                    ],
                    'company' => [
                        'name'    => 'Test Company S.L.',
                        'address' => 'Calle Test 123, 41001 Sevilla, España',
                    ],
                    'format_valid' => true,
                    'query'        => 'ESB12345678',
                ], 200),
            ]);

            $result = $service->verifyVatNumber('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeTrue();
            expect($result['api_source'])->toBe('abstractapi');
            expect($result['cached'])->toBeFalse();
            expect($result)->not->toHaveKey('fallback_used');
            expect($result)->not->toHaveKey('primary_api_failed');

            // Verify only AbstractAPI was called
            Http::assertSentCount(1);
            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'abstractapi.com');
            });
        });

        it('falls back to APILayer when AbstractAPI fails', function () {
            $service = new VatVerificationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 500),
                'http://apilayer.net/api/validate*'         => Http::response([
                    'valid'           => true,
                    'vat_code'        => 'ESB12345678',
                    'country_code'    => 'ES',
                    'company_name'    => 'Test Company S.L.',
                    'company_address' => 'Calle Test 123, 41001 Sevilla, España',
                ], 200),
            ]);

            $result = $service->verifyVatNumber('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeTrue();
            expect($result['api_source'])->toBe('apilayer');
            expect($result['fallback_used'])->toBeTrue();
            expect($result['primary_api_failed'])->toBe('abstractapi');
            expect($result['cached'])->toBeFalse();

            // Verify both APIs were called
            Http::assertSentCount(2);
            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'abstractapi.com');
            });
            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'apilayer.net');
            });
        });

        it('falls back to APILayer when AbstractAPI returns invalid response', function () {
            $service = new VatVerificationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([
                    'error' => 'Invalid API key',
                ], 401),
                'http://apilayer.net/api/validate*' => Http::response([
                    'valid'           => true,
                    'vat_code'        => 'ESB12345678',
                    'country_code'    => 'ES',
                    'company_name'    => 'Test Company S.L.',
                    'company_address' => 'Calle Test 123, 41001 Sevilla, España',
                ], 200),
            ]);

            $result = $service->verifyVatNumber('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeTrue();
            expect($result['api_source'])->toBe('apilayer');
            expect($result['fallback_used'])->toBeTrue();
            expect($result['primary_api_failed'])->toBe('abstractapi');

            // Verify both APIs were called
            Http::assertSentCount(2);
        });

        it('uses mock response when both APIs fail', function () {
            // Configure API keys to force HTTP calls
            config(['larabill.vat_apis.abstractapi.key' => 'test_key']);
            config(['larabill.vat_apis.apilayer.key' => 'test_key']);

            $service = new VatVerificationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 500),
                'http://apilayer.net/api/validate*'         => Http::response([], 503),
            ]);

            $result = $service->verifyVatNumber('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeFalse(); // Both APIs failed
            expect($result['vat_code'])->toBe('ESB12345678');
            expect($result['country_code'])->toBe('ES');
            expect($result['company_name'])->toBeNull();
            expect($result['api_source'])->toBe('apilayer'); // Fallback API was used
            expect($result['all_apis_failed'])->toBeTrue();
            expect($result['fallback_used'])->toBeTrue();
            expect($result['primary_api_failed'])->toBe('abstractapi');

            // Verify both APIs were called
            Http::assertSentCount(2);
        });

        it('caches successful results from primary API', function () {
            $service = new VatVerificationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([
                    'valid'      => true,
                    'vat_code'   => 'ESB12345678',
                    'country'    => [
                        'code' => 'ES',
                        'name' => 'Spain',
                    ],
                    'company' => [
                        'name'    => 'Test Company S.L.',
                        'address' => 'Calle Test 123, 41001 Sevilla, España',
                    ],
                    'format_valid' => true,
                    'query'        => 'ESB12345678',
                ], 200),
            ]);

            // First call
            $result1 = $service->verifyVatNumber('ESB12345678', 'ES');
            expect($result1['cached'])->toBeFalse();
            expect($result1['api_source'])->toBe('abstractapi');

            // Second call should use cache
            $result2 = $service->verifyVatNumber('ESB12345678', 'ES');
            expect($result2['cached'])->toBeTrue();
            expect($result2['api_source'])->toBe('abstractapi');
            expect($result2['is_valid'])->toBeTrue();

            // Verify API was only called once
            Http::assertSentCount(1);
        });

        it('caches successful results from fallback API', function () {
            $service = new VatVerificationService;

            Http::fake([
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([], 500),
                'http://apilayer.net/api/validate*'         => Http::response([
                    'valid'           => true,
                    'vat_code'        => 'ESB12345678',
                    'country_code'    => 'ES',
                    'company_name'    => 'Test Company S.L.',
                    'company_address' => 'Calle Test 123, 41001 Sevilla, España',
                ], 200),
            ]);

            // First call
            $result1 = $service->verifyVatNumber('ESB12345678', 'ES');
            expect($result1['cached'])->toBeFalse();
            expect($result1['api_source'])->toBe('apilayer');
            expect($result1['fallback_used'])->toBeTrue();

            // Second call should use cache
            $result2 = $service->verifyVatNumber('ESB12345678', 'ES');
            expect($result2['cached'])->toBeTrue();
            expect($result2['api_source'])->toBe('apilayer');
            expect($result2['is_valid'])->toBeTrue();

            // Verify both APIs were called only once
            Http::assertSentCount(2);
        });
    });

    describe('Different API Preferences', function () {
        it('uses APILayer as primary when configured', function () {
            config(['larabill.vat_apis.preferred_api' => 'apilayer']);
            $service = new VatVerificationService;

            Http::fake([
                'http://apilayer.net/api/validate*' => Http::response([
                    'valid'           => true,
                    'vat_code'        => 'ESB12345678',
                    'country_code'    => 'ES',
                    'company_name'    => 'Test Company S.L.',
                    'company_address' => 'Calle Test 123, 41001 Sevilla, España',
                ], 200),
            ]);

            $result = $service->verifyVatNumber('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeTrue();
            expect($result['api_source'])->toBe('apilayer');
            expect($result)->not->toHaveKey('fallback_used');

            // Verify only APILayer was called
            Http::assertSentCount(1);
            Http::assertSent(function ($request) {
                return str_contains($request->url(), 'apilayer.net');
            });
        });

        it('falls back to AbstractAPI when APILayer is primary and fails', function () {
            config(['larabill.vat_apis.preferred_api' => 'apilayer']);
            $service = new VatVerificationService;

            Http::fake([
                'http://apilayer.net/api/validate*'         => Http::response([], 500),
                'https://vat.abstractapi.com/v1/validate/*' => Http::response([
                    'valid'      => true,
                    'vat_code'   => 'ESB12345678',
                    'country'    => [
                        'code' => 'ES',
                        'name' => 'Spain',
                    ],
                    'company' => [
                        'name'    => 'Test Company S.L.',
                        'address' => 'Calle Test 123, 41001 Sevilla, España',
                    ],
                    'format_valid' => true,
                    'query'        => 'ESB12345678',
                ], 200),
            ]);

            $result = $service->verifyVatNumber('ESB12345678', 'ES');

            expect($result)->toBeArray();
            expect($result['is_valid'])->toBeTrue();
            expect($result['api_source'])->toBe('abstractapi');
            expect($result['fallback_used'])->toBeTrue();
            expect($result['primary_api_failed'])->toBe('apilayer');

            // Verify both APIs were called
            Http::assertSentCount(2);
        });
    });
});
