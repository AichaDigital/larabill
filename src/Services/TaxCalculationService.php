<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\CountryVatRate;

/**
 * Tax Calculation Service
 *
 * Handles tax calculations for different regions and transaction types.
 */
class TaxCalculationService
{
    private ?DestinationVatService $destinationVatService = null;

    private ?RoiVerificationService $roiVerificationService = null;

    /**
     * Constructor.
     */
    public function __construct(?DestinationVatService $destinationVatService = null, ?RoiVerificationService $roiVerificationService = null)
    {
        $this->destinationVatService  = $destinationVatService;
        $this->roiVerificationService = $roiVerificationService;
    }

    /**
     * Calculate tax for a transaction.
     *
     * @param  float  $amount  The amount to calculate tax for
     * @param  string  $sellerCountry  Seller's country code
     * @param  string  $buyerCountry  Buyer's country code
     * @param  bool  $isB2B  Whether this is a B2B transaction
     * @param  array<string, mixed>  $options  Additional options (vat_verification, company_id, etc.)
     * @return array<string, mixed> Tax calculation result
     */
    public function calculateTax(float $amount, string $sellerCountry, string $buyerCountry, bool $isB2B, array $options = []): array
    {
        // Determine calculation method based on countries
        if ($sellerCountry === 'ES' && $this->isSpecialSpanishTerritory($buyerCountry)) {
            return $this->calculateSpecialSpanishTax($amount, $sellerCountry, $buyerCountry, $isB2B, $options);
        }

        if ($this->isEUCountry($sellerCountry) && $this->isEUCountry($buyerCountry)) {
            return $this->calculateEUTax($amount, $sellerCountry, $buyerCountry, $isB2B, $options);
        }

        if ($this->isWorldwideTransaction($sellerCountry, $buyerCountry)) {
            return $this->calculateWorldwideTax($amount, $sellerCountry, $buyerCountry, $isB2B, $options);
        }

        // Default Spanish domestic transaction
        return $this->calculateSpanishTax($amount, $sellerCountry, $buyerCountry, $isB2B, $options);
    }

    /**
     * Calculate Spanish domestic tax (IVA).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function calculateSpanishTax(float $amount, string $sellerCountry, string $buyerCountry, bool $isB2B, array $options = []): array
    {
        $taxRate   = $this->getSpanishTaxRate($options['category'] ?? 'standard');
        $taxAmount = $amount * ($taxRate / 100);

        return [
            'tax_rate'           => $taxRate,
            'tax_amount'         => $taxAmount,
            'amount'             => $amount,
            'total'              => $amount + $taxAmount,
            'tax_type'           => 'iva',
            'tax_name'           => 'IVA',
            'special_conditions' => [],
            'invoice_notes'      => [],
        ];
    }

    /**
     * Calculate special Spanish tax (Canarias IGIC, Ceuta/Melilla IPSI).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function calculateSpecialSpanishTax(float $amount, string $sellerCountry, string $buyerCountry, bool $isB2B, array $options = []): array
    {
        if ($buyerCountry === 'IC') { // Canarias
            return $this->calculateCanariasTax($amount, $options);
        }

        if (in_array($buyerCountry, ['CE', 'ML'])) { // Ceuta/Melilla
            return $this->calculateCeutaMelillaTax($amount, $options);
        }

        // Fallback to standard Spanish tax
        return $this->calculateSpanishTax($amount, $sellerCountry, $buyerCountry, $isB2B, $options);
    }

    /**
     * Calculate Canarias IGIC tax.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function calculateCanariasTax(float $amount, array $options = []): array
    {
        $taxRate   = $this->getCanariasTaxRate($options['category'] ?? 'standard');
        $taxAmount = $amount * ($taxRate / 100);

        return [
            'tax_rate'           => $taxRate,
            'tax_amount'         => $taxAmount,
            'amount'             => $amount,
            'total'              => $amount + $taxAmount,
            'tax_type'           => 'igic',
            'tax_name'           => 'IGIC',
            'special_conditions' => ['canarias'],
            'invoice_notes'      => ['Exento de IVA español. Aplicable IGIC canario.'],
        ];
    }

    /**
     * Calculate Ceuta/Melilla IPSI tax.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function calculateCeutaMelillaTax(float $amount, array $options = []): array
    {
        $taxRate   = 0.0; // IPSI is always 0%
        $taxAmount = 0.0;

        return [
            'tax_rate'           => $taxRate,
            'tax_amount'         => $taxAmount,
            'amount'             => $amount,
            'total'              => $amount + $taxAmount,
            'tax_type'           => 'ipsi',
            'tax_name'           => 'IPSI',
            'special_conditions' => ['ceuta_melilla'],
            'invoice_notes'      => ['Exento de IVA español. Territorio especial de Ceuta/Melilla.'],
        ];
    }

    /**
     * Calculate EU tax (with reverse charge and destination VAT).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function calculateEUTax(float $amount, string $sellerCountry, string $buyerCountry, bool $isB2B, array $options = []): array
    {
        // Check if destination VAT should be applied
        if ($this->shouldApplyDestinationVat($options)) {
            return $this->calculateDestinationVat($amount, $buyerCountry, $options);
        }

        // B2B reverse charge
        if ($isB2B && $this->isValidRoi($options)) {
            return $this->calculateReverseCharge($amount, $sellerCountry, $buyerCountry, $options);
        }

        // B2C or invalid ROI - apply seller's country tax
        return $this->calculateSellerCountryTax($amount, $sellerCountry, $options);
    }

    /**
     * Calculate worldwide tax (outside EU).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function calculateWorldwideTax(float $amount, string $sellerCountry, string $buyerCountry, bool $isB2B, array $options = []): array
    {
        // USA Sales Tax
        if ($buyerCountry === 'US') {
            return $this->calculateUSATax($amount, $options);
        }

        // Most countries outside EU have no VAT
        return [
            'tax_rate'           => 0.0,
            'tax_amount'         => 0.0,
            'amount'             => $amount,
            'total'              => $amount,
            'tax_type'           => 'none',
            'tax_name'           => 'Sin impuestos',
            'special_conditions' => ['worldwide'],
            'invoice_notes'      => ['Transacción internacional. Sin IVA aplicable.'],
        ];
    }

    /**
     * Calculate USA Sales Tax.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function calculateUSATax(float $amount, array $options = []): array
    {
        $state     = $options['state'] ?? 'CA'; // Default to California
        $taxRate   = $this->getUSATaxRate($state);
        $taxAmount = $amount * ($taxRate / 100);

        return [
            'tax_rate'           => $taxRate,
            'tax_amount'         => $taxAmount,
            'amount'             => $amount,
            'total'              => $amount + $taxAmount,
            'tax_type'           => 'sales_tax',
            'tax_name'           => 'Sales Tax',
            'special_conditions' => ['usa', $state],
            'invoice_notes'      => ["Sales Tax aplicable en {$state}."],
        ];
    }

    /**
     * Calculate destination VAT.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function calculateDestinationVat(float $amount, string $buyerCountry, array $options = []): array
    {
        if (! $this->destinationVatService) {
            throw new \RuntimeException('DestinationVatService not available');
        }

        $category = $options['category'] ?? null;
        $result   = $this->destinationVatService->calculateDestinationVat($amount, $buyerCountry, $category);

        return [
            'tax_rate'           => $result['vat_rate'],
            'tax_amount'         => $result['vat_amount'],
            'amount'             => $result['amount'],
            'total'              => $result['total'],
            'tax_type'           => 'destination_vat',
            'tax_name'           => 'IVA de Destino',
            'special_conditions' => ['destination_vat', $buyerCountry],
            'invoice_notes'      => ["IVA de destino aplicado para {$buyerCountry}."],
        ];
    }

    /**
     * Calculate reverse charge.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function calculateReverseCharge(float $amount, string $sellerCountry, string $buyerCountry, array $options = []): array
    {
        return [
            'tax_rate'           => 0.0,
            'tax_amount'         => 0.0,
            'amount'             => $amount,
            'total'              => $amount,
            'tax_type'           => 'reverse_charge',
            'tax_name'           => 'Reverse Charge',
            'special_conditions' => ['reverse_charge', 'eu_b2b'],
            'invoice_notes'      => [
                'Operación sujeta al régimen de inversión del sujeto pasivo.',
                'El destinatario debe repercutir el IVA correspondiente.',
            ],
        ];
    }

    /**
     * Calculate seller country tax.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function calculateSellerCountryTax(float $amount, string $sellerCountry, array $options = []): array
    {
        $taxRate   = $this->getCountryTaxRate($sellerCountry);
        $taxAmount = $amount * ($taxRate / 100);

        return [
            'tax_rate'           => $taxRate,
            'tax_amount'         => $taxAmount,
            'amount'             => $amount,
            'total'              => $amount + $taxAmount,
            'tax_type'           => 'standard',
            'tax_name'           => 'IVA',
            'special_conditions' => ['eu_b2c'],
            'invoice_notes'      => ["IVA del país vendedor ({$sellerCountry}) aplicado."],
        ];
    }

    /**
     * Get Spanish tax rate by category.
     */
    private function getSpanishTaxRate(string $category): float
    {
        return match ($category) {
            'reduced'       => 10.0,
            'super_reduced' => 4.0,
            'exempt'        => 0.0,
            default         => 21.0,
        };
    }

    /**
     * Get Canarias tax rate by category.
     */
    private function getCanariasTaxRate(string $category): float
    {
        return match ($category) {
            'reduced'       => 3.0,
            'super_reduced' => 0.0,
            'exempt'        => 0.0,
            default         => 7.0,
        };
    }

    /**
     * Get USA tax rate by state.
     */
    private function getUSATaxRate(string $state): float
    {
        $rates = [
            'CA' => 7.25, 'NY' => 8.0, 'TX' => 6.25, 'FL' => 6.0,
            'IL' => 6.25, 'PA' => 6.0, 'OH' => 5.75, 'GA' => 4.0,
            'NC' => 4.75, 'MI' => 6.0,
        ];

        return $rates[$state] ?? 7.0; // Default rate
    }

    /**
     * Get country tax rate.
     */
    private function getCountryTaxRate(string $countryCode): float
    {
        $countryVatRate = CountryVatRate::findByCountry($countryCode);

        if ($countryVatRate) {
            return (float) $countryVatRate->standard_rate;
        }

        // Fallback rates for common countries
        $fallbackRates = [
            'DE' => 19.0, 'FR' => 20.0, 'IT' => 22.0, 'PT' => 23.0,
            'NL' => 21.0, 'BE' => 21.0, 'AT' => 20.0, 'IE' => 23.0,
        ];

        return $fallbackRates[$countryCode] ?? 21.0;
    }

    /**
     * Check if destination VAT should be applied.
     *
     * @param  array<string, mixed>  $options
     */
    private function shouldApplyDestinationVat(array $options): bool
    {
        if (! $this->destinationVatService || ! isset($options['company_id'])) {
            return false;
        }

        return $this->destinationVatService->shouldApplyDestinationVat($options['company_id']);
    }

    /**
     * Check if ROI is valid.
     *
     * @param  array<string, mixed>  $options
     */
    private function isValidRoi(array $options): bool
    {
        if (! $this->roiVerificationService || ! isset($options['vat_verification'])) {
            return false;
        }

        $vatVerification = $options['vat_verification'];
        if (! is_array($vatVerification) || ! isset($vatVerification['vat_code'])) {
            return false;
        }

        $result = $this->roiVerificationService->verifyRoiStatus(
            $options['user_id'] ?? 'unknown',
            $vatVerification['vat_code'],
            $vatVerification['country_code'] ?? 'ES'
        );

        return $result['is_roi'] ?? false;
    }

    /**
     * Check if country is a special Spanish territory.
     */
    private function isSpecialSpanishTerritory(string $countryCode): bool
    {
        return in_array($countryCode, ['IC', 'CE', 'ML']);
    }

    /**
     * Check if country is in EU.
     */
    private function isEUCountry(string $countryCode): bool
    {
        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ];

        return in_array($countryCode, $euCountries);
    }

    /**
     * Check if transaction is worldwide (outside EU).
     */
    private function isWorldwideTransaction(string $sellerCountry, string $buyerCountry): bool
    {
        return ! $this->isEUCountry($sellerCountry) || ! $this->isEUCountry($buyerCountry);
    }
}
