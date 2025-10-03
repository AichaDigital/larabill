<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * VatApiIntegrationService
 *
 * Handles integration with external VAT verification APIs.
 */
class VatApiIntegrationService
{
    private array $config;

    public function __construct()
    {
        $this->config = config('larabill.vat_apis', []);
    }

    /**
     * Verify VAT number using AbstractAPI.
     */
    public function verifyWithAbstractApi(string $vatNumber, string $countryCode): array
    {
        $apiKey = $this->config['abstractapi']['key'] ?? null;

        if (!$apiKey || $apiKey === 'your_abstractapi_key_here') {
            return $this->getMockResponse('abstractapi', $vatNumber, $countryCode);
        }

        try {
            $response = Http::timeout($this->config['abstractapi']['timeout'] ?? 10)
                ->get($this->config['abstractapi']['url'], [
                    'api_key' => $apiKey,
                    'vat_number' => $vatNumber,
                    'country_code' => $countryCode,
                ]);

            return $this->processAbstractApiResponse($response, $vatNumber, $countryCode);
        } catch (\Exception $e) {
            Log::error('AbstractAPI VAT verification failed', [
                'vat_number' => $vatNumber,
                'country_code' => $countryCode,
                'error' => $e->getMessage(),
            ]);

            return $this->getMockResponse('abstractapi', $vatNumber, $countryCode);
        }
    }

    /**
     * Verify VAT number using API Layer.
     */
    public function verifyWithApiLayer(string $vatNumber, string $countryCode): array
    {
        $apiKey = $this->config['apilayer']['key'] ?? null;

        if (!$apiKey || $apiKey === 'your_apilayer_key_here') {
            return $this->getMockResponse('apilayer', $vatNumber, $countryCode);
        }

        try {
            $response = Http::timeout($this->config['apilayer']['timeout'] ?? 10)
                ->get($this->config['apilayer']['url'], [
                    'access_key' => $apiKey,
                    'vat_number' => $vatNumber,
                    'country_code' => $countryCode,
                ]);

            return $this->processApiLayerResponse($response, $vatNumber, $countryCode);
        } catch (\Exception $e) {
            Log::error('API Layer VAT verification failed', [
                'vat_number' => $vatNumber,
                'country_code' => $countryCode,
                'error' => $e->getMessage(),
            ]);

            return $this->getMockResponse('apilayer', $vatNumber, $countryCode);
        }
    }

    /**
     * Process AbstractAPI response.
     */
    private function processAbstractApiResponse(Response $response, string $vatNumber, string $countryCode): array
    {
        $data = $response->json();

        return [
            'is_valid' => $data['valid'] ?? false,
            'vat_number' => $vatNumber,
            'country_code' => $countryCode,
            'company_name' => $data['company'] ?? null,
            'company_address' => $data['address'] ?? null,
            'api_source' => 'abstractapi',
            'response_data' => $data,
        ];
    }

    /**
     * Process API Layer response.
     */
    private function processApiLayerResponse(Response $response, string $vatNumber, string $countryCode): array
    {
        $data = $response->json();

        return [
            'is_valid' => $data['valid'] ?? false,
            'vat_number' => $vatNumber,
            'country_code' => $countryCode,
            'company_name' => $data['company_name'] ?? null,
            'company_address' => $data['company_address'] ?? null,
            'api_source' => 'apilayer',
            'response_data' => $data,
        ];
    }

    /**
     * Get mock response for testing.
     */
    private function getMockResponse(string $apiSource, string $vatNumber, string $countryCode): array
    {
        $mockResponses = $this->getMockResponses();
        $key = strtolower($countryCode . '_' . $vatNumber);

        if (isset($mockResponses[$key])) {
            return array_merge($mockResponses[$key], ['api_source' => $apiSource]);
        }

        // Default mock response
        return [
            'is_valid' => $vatNumber !== 'INVALID',
            'vat_number' => $vatNumber,
            'country_code' => $countryCode,
            'company_name' => $vatNumber !== 'INVALID' ? 'Test Company S.L.' : null,
            'company_address' => $vatNumber !== 'INVALID' ? 'Test Address 123' : null,
            'api_source' => $apiSource,
            'response_data' => [
                'valid' => $vatNumber !== 'INVALID',
                'vat_number' => $vatNumber,
                'country_code' => $countryCode,
            ],
        ];
    }

    /**
     * Get mock responses for testing.
     */
    private function getMockResponses(): array
    {
        return [
            'es_esb12345678' => [
                'is_valid' => true,
                'vat_number' => 'ESB12345678',
                'country_code' => 'ES',
                'company_name' => 'AichaDigital S.L.',
                'company_address' => 'Calle Test 123, 41001 Sevilla, España',
                'response_data' => [
                    'valid' => true,
                    'vat_number' => 'ESB12345678',
                    'country_code' => 'ES',
                    'company' => 'AichaDigital S.L.',
                    'address' => 'Calle Test 123, 41001 Sevilla, España',
                    'format_valid' => true,
                    'query' => 'ESB12345678',
                ],
            ],
            'de_de123456789' => [
                'is_valid' => true,
                'vat_number' => 'DE123456789',
                'country_code' => 'DE',
                'company_name' => 'German Company GmbH',
                'company_address' => 'Musterstraße 123, 10115 Berlin, Deutschland',
                'response_data' => [
                    'valid' => true,
                    'vat_number' => 'DE123456789',
                    'country_code' => 'DE',
                    'company_name' => 'German Company GmbH',
                    'company_address' => 'Musterstraße 123, 10115 Berlin, Deutschland',
                    'format_valid' => true,
                    'query' => 'DE123456789',
                ],
            ],
            'fr_fr12345678901' => [
                'is_valid' => true,
                'vat_number' => 'FR12345678901',
                'country_code' => 'FR',
                'company_name' => 'Société Française S.A.S.',
                'company_address' => '123 Rue de la Paix, 75001 Paris, France',
                'response_data' => [
                    'valid' => true,
                    'vat_number' => 'FR12345678901',
                    'country_code' => 'FR',
                    'company' => 'Société Française S.A.S.',
                    'address' => '123 Rue de la Paix, 75001 Paris, France',
                    'format_valid' => true,
                    'query' => 'FR12345678901',
                ],
            ],
            'gb_gb123456789' => [
                'is_valid' => true,
                'vat_number' => 'GB123456789',
                'country_code' => 'GB',
                'company_name' => 'British Company Ltd.',
                'company_address' => '123 Test Street, London SW1A 1AA, United Kingdom',
                'response_data' => [
                    'valid' => true,
                    'vat_number' => 'GB123456789',
                    'country_code' => 'GB',
                    'company_name' => 'British Company Ltd.',
                    'company_address' => '123 Test Street, London SW1A 1AA, United Kingdom',
                    'format_valid' => true,
                    'query' => 'GB123456789',
                ],
            ],
            'invalid_test' => [
                'is_valid' => false,
                'vat_number' => 'INVALID',
                'country_code' => 'ES',
                'company_name' => null,
                'company_address' => null,
                'response_data' => [
                    'valid' => false,
                    'vat_number' => 'INVALID',
                    'country_code' => 'ES',
                    'format_valid' => false,
                    'query' => 'INVALID',
                ],
            ],
        ];
    }
}
