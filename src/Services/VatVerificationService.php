<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\VatVerification;

/**
 * VAT Verification Service
 *
 * Handles VAT number verification using external APIs.
 */
class VatVerificationService
{
    private VatApiIntegrationService $apiIntegration;

    public function __construct(VatApiIntegrationService $apiIntegration)
    {
        $this->apiIntegration = $apiIntegration;
    }

    /**
     * Verify a VAT number against external APIs.
     */
    public function verifyVatNumber(string $vatNumber, string $countryCode): array
    {
        // Check if we already have a cached verification
        $cachedVerification = VatVerification::findByVatNumberAndCountry($vatNumber, $countryCode);
        
        if ($cachedVerification) {
            return [
                'is_valid' => $cachedVerification->is_valid,
                'vat_number' => $cachedVerification->vat_number,
                'country_code' => $cachedVerification->country_code,
                'company_name' => $cachedVerification->company_name,
                'company_address' => $cachedVerification->company_address,
                'api_source' => $cachedVerification->api_source,
                'cached' => true,
            ];
        }

        // Use preferred API or fallback
        $preferredApi = config('larabill.vat_apis.preferred_api', 'abstractapi');
        
        if ($preferredApi === 'abstractapi') {
            $result = $this->apiIntegration->verifyWithAbstractApi($vatNumber, $countryCode);
        } else {
            $result = $this->apiIntegration->verifyWithApiLayer($vatNumber, $countryCode);
        }

        // Cache the result
        $this->cacheVerificationResult($result);

        return $result;
    }

    /**
     * Cache verification result in database.
     */
    private function cacheVerificationResult(array $result): void
    {
        VatVerification::updateOrCreate(
            [
                'vat_number' => $result['vat_number'],
                'country_code' => $result['country_code'],
            ],
            [
                'is_valid' => $result['is_valid'],
                'company_name' => $result['company_name'],
                'company_address' => $result['company_address'],
                'api_source' => $result['api_source'],
                'response_data' => $result['response_data'] ?? null,
            ]
        );
    }
}
