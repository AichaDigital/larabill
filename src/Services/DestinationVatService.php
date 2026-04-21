<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CountryVatRate;
use AichaDigital\Larabill\Models\EuSalesThreshold;
use AichaDigital\Larabill\Models\VatCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Destination VAT Service
 *
 * Handles destination VAT application and threshold management
 * for intra-EU transactions.
 *
 * @note Refactored in ADR-001 to use CompanyFiscalConfig instead of FiscalSettings
 */
class DestinationVatService
{
    private CacheService $cacheService;

    public function __construct(?CacheService $cacheService = null)
    {
        $this->cacheService = $cacheService ?? app(CacheService::class);
    }

    /**
     * Check if destination VAT should be applied based on company config.
     */
    public function shouldApplyDestinationVat(): bool
    {
        $config = CompanyFiscalConfig::getActive();

        if (! $config) {
            return false;
        }

        return $config->is_oss;
    }

    /**
     * Get destination VAT rate for a country and category.
     */
    public function getDestinationVatRate(string $countryCode, ?string $category = null): float
    {
        $cacheKey = "vat_rate:{$countryCode}".($category ? ":{$category}" : '');

        return $this->cacheService->remember($cacheKey, function () use ($countryCode, $category) {
            if ($category) {
                $vatCategory = VatCategory::findByNameAndCountry($category, $countryCode);
                if ($vatCategory) {
                    return (float) $vatCategory->vat_rate / 100.0;
                }
            }

            $countryVatRate = CountryVatRate::findByCountry($countryCode);
            if ($countryVatRate) {
                if ($category) {
                    return (float) $countryVatRate->getRateForCategory($category);
                }

                return (float) $countryVatRate->standard_rate;
            }

            return (float) CountryVatRate::getDefaultRateForCountry($countryCode);
        });
    }

    /**
     * Calculate destination VAT for a transaction.
     *
     * @return array<string, mixed>
     */
    public function calculateDestinationVat(float $amount, string $countryCode, ?string $category = null): array
    {
        $vatRate   = $this->getDestinationVatRate($countryCode, $category);
        $vatAmount = $amount * ($vatRate / 100);

        return [
            'amount'       => $amount,
            'vat_rate'     => $vatRate,
            'vat_amount'   => $vatAmount,
            'total'        => $amount + $vatAmount,
            'country_code' => $countryCode,
            'category'     => $category,
        ];
    }

    /**
     * Update EU sales amount for tracking purposes.
     */
    public function updateEuSales(string $userId, string $countryCode, float $amount, ?int $fiscalYear = null): void
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        $threshold = EuSalesThreshold::getOrCreateForUser($userId, $fiscalYear);
        $threshold->addSalesForCountry($countryCode, $amount);

        $this->clearCompanyCache($userId);
    }

    /**
     * Get threshold statistics for a user.
     *
     * @return array<string, mixed>
     */
    public function getThresholdStatistics(string $userId, ?int $fiscalYear = null): array
    {
        $threshold = EuSalesThreshold::getOrCreateForUser($userId, $fiscalYear ?? now()->year);
        $config    = CompanyFiscalConfig::getActive();

        return [
            'user_id'                 => $userId,
            'fiscal_year'             => $fiscalYear                      ?? now()->year,
            'breakdown_by_country'    => $threshold->breakdown_by_country ?? [],
            'top_countries'           => $threshold->getTopCountriesBySalesForInstance(5),
            'is_oss'                  => $config ? $config->is_oss : false,
            'is_roi'                  => $config ? $config->is_roi : false,
        ];
    }

    /**
     * Clear cache for a company.
     */
    private function clearCompanyCache(string $userId): void
    {
        $this->cacheService->clearByTag('company');
    }

    /**
     * Get VAT categories for a country.
     *
     * @return Collection<int, VatCategory>
     */
    public function getVatCategories(string $countryCode): Collection
    {
        $cacheKey = "vat_categories:{$countryCode}";

        return $this->cacheService->remember($cacheKey, function () use ($countryCode) {
            return VatCategory::getByCountry($countryCode);
        });
    }

    /**
     * Get all EU countries with VAT rates.
     *
     * @return Collection<int, CountryVatRate>
     */
    public function getEuCountriesWithVatRates(): Collection
    {
        $cacheKey = 'eu_countries_vat_rates';

        return $this->cacheService->remember($cacheKey, function () {
            return CountryVatRate::getEuCountries();
        });
    }

    /**
     * Import VAT rates from external source.
     *
     * @param  array<int, mixed>  $data
     */
    public function importVatRates(array $data, string $dataSource = 'external'): int
    {
        $imported = CountryVatRate::importFromDataSource($data, $dataSource);

        $this->cacheService->clearByTag('vat');

        Log::info('VAT rates imported', [
            'count'       => $imported,
            'data_source' => $dataSource,
        ]);

        return $imported;
    }

    /**
     * Check if a country is in the EU.
     */
    public function isEuCountry(string $countryCode): bool
    {
        $euCountries = config('larabill.eu_countries', [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        ]);

        return in_array(strtoupper($countryCode), $euCountries);
    }

    /**
     * Get fiscal year for a date.
     */
    public function getFiscalYear(?\DateTime $date = null): int
    {
        if (! $date) {
            $date = now();
        }

        return (int) $date->format('Y');
    }

    /**
     * Get VAT rate for a specific country.
     */
    public function getVatRateForCountry(string $countryCode): float
    {
        $countryVatRate = CountryVatRate::findByCountryCode($countryCode);

        if (! $countryVatRate) {
            throw new \InvalidArgumentException("VAT rate not found for country: {$countryCode}");
        }

        return (float) $countryVatRate->standard_rate;
    }

    /**
     * Calculate VAT amount for a given amount and country.
     */
    public function calculateVatAmount(float $amount, string $countryCode, ?string $category = null): float
    {
        $vatRate = $this->getDestinationVatRate($countryCode, $category);

        return $amount * ($vatRate / 100);
    }

    /**
     * Validate destination country.
     */
    public function isValidDestinationCountry(string $countryCode): bool
    {
        if (empty($countryCode)) {
            return false;
        }

        return $this->isEuCountry($countryCode);
    }

    /**
     * Get available destination countries.
     *
     * @return array<int, string>
     */
    public function getAvailableDestinationCountries(): array
    {
        return CountryVatRate::where('is_active', true)
            ->pluck('country_code')
            ->toArray();
    }

    /**
     * Get service configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return config('larabill.destination_vat', []);
    }
}
