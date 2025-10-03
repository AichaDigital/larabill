<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

/**
 * Tax Calculation Service
 *
 * Handles tax calculations for different regions and transaction types.
 */
class TaxCalculationService
{
    /**
     * Calculate tax for a transaction.
     *
     * @param  float  $amount  The amount to calculate tax for
     * @param  string  $sellerCountry  Seller's country code
     * @param  string  $buyerCountry  Buyer's country code
     * @param  bool  $isB2B  Whether this is a B2B transaction
     * @return array Tax calculation result
     */
    public function calculateTax(float $amount, string $sellerCountry, string $buyerCountry, bool $isB2B): array
    {
        // Basic tax calculation logic
        $taxRate = $this->getTaxRate($sellerCountry, $buyerCountry, $isB2B);
        $taxAmount = $amount * ($taxRate / 100);

        return [
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'amount' => $amount,
            'total' => $amount + $taxAmount,
        ];
    }

    /**
     * Get tax rate for a transaction.
     */
    private function getTaxRate(string $sellerCountry, string $buyerCountry, bool $isB2B): float
    {
        // Spanish domestic transaction
        if ($sellerCountry === 'ES' && $buyerCountry === 'ES') {
            return 21.0; // Standard Spanish VAT
        }

        // EU B2B transaction (reverse charge)
        if ($isB2B && $sellerCountry === 'ES' && $buyerCountry === 'DE') {
            return 0.0; // Reverse charge
        }

        // Default case
        return 21.0;
    }
}
