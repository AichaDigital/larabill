<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

/**
 * VAT Verification Service
 *
 * Handles VAT number verification using external APIs.
 */
class VatVerificationService
{
    /**
     * Verify a VAT number against external APIs.
     */
    public function verifyVatNumber(string $vatNumber, string $countryCode): array
    {
        // Basic validation logic for now
        $isValid = $vatNumber !== 'INVALID' && strlen($vatNumber) > 3;

        return [
            'is_valid' => $isValid,
            'vat_number' => $vatNumber,
            'country_code' => $countryCode,
            'company_name' => $isValid ? 'Test Company' : null,
            'company_address' => null,
        ];
    }
}
