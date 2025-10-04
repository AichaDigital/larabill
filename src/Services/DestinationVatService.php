<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\CountryVatRate;
use AichaDigital\Larabill\Models\EuSalesThreshold;
use AichaDigital\Larabill\Models\VatCategory;
use Illuminate\Support\Facades\Log;

/**
 * Destination VAT Service
 *
 * Handles destination VAT application and threshold management
 * for intra-EU transactions.
 */
class DestinationVatService
{
    private CacheService $cacheService;

    /**
     * Constructor.
     */
    public function __construct(?CacheService $cacheService = null)
    {
        $this->cacheService = $cacheService ?? app(CacheService::class);
    }

    /**
     * Check if destination VAT should be applied for a company.
     */
    public function shouldApplyDestinationVat(string $companyId, ?int $fiscalYear = null): bool
    {
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $fiscalYear);

        return $config->shouldApplyDestinationVat();
    }

    /**
     * Get destination VAT rate for a country and category.
     */
    public function getDestinationVatRate(string $countryCode, ?string $category = null): float
    {
        $cacheKey = "vat_rate:{$countryCode}".($category ? ":{$category}" : '');

        return $this->cacheService->remember($cacheKey, function () use ($countryCode, $category) {
            if ($category) {
                // Try to get rate from VAT category
                $vatCategory = VatCategory::findByNameAndCountry($category, $countryCode);
                if ($vatCategory) {
                    return (float) $vatCategory->vat_rate;
                }
            }

            // Fall back to country VAT rate
            $countryVatRate = CountryVatRate::findByCountry($countryCode);
            if ($countryVatRate) {
                if ($category) {
                    return (float) $countryVatRate->getRateForCategory($category);
                }

                return (float) $countryVatRate->standard_rate;
            }

            // Final fallback to default rate
            return (float) CountryVatRate::getDefaultRateForCountry($countryCode);
        });
    }

    /**
     * Calculate destination VAT for a transaction.
     */
    public function calculateDestinationVat(float $amount, string $countryCode, ?string $category = null): array
    {
        $vatRate = $this->getDestinationVatRate($countryCode, $category);
        $vatAmount = $amount * ($vatRate / 100);

        return [
            'amount' => $amount,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total' => $amount + $vatAmount,
            'country_code' => $countryCode,
            'category' => $category,
        ];
    }

    /**
     * Update EU sales amount for a company.
     */
    public function updateEuSales(string $companyId, string $countryCode, float $amount, ?int $fiscalYear = null): void
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        // Update company fiscal config
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $fiscalYear);
        $config->updateEuSales($amount);

        // Update EU sales threshold
        $threshold = EuSalesThreshold::getOrCreateForCompany($companyId, $fiscalYear);
        $threshold->addSalesForCountry($countryCode, $amount);

        // Check if threshold exceeded and send notification
        if ($threshold->checkThreshold() && ! $threshold->notification_sent) {
            $this->sendThresholdExceededNotification($config, $threshold);
        }

        // Clear cache for this company
        $this->clearCompanyCache($companyId);
    }

    /**
     * Check threshold for a company.
     */
    public function checkThreshold(string $companyId, ?int $fiscalYear = null): bool
    {
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $fiscalYear);

        return $config->checkThreshold();
    }

    /**
     * Get threshold statistics for a company.
     */
    public function getThresholdStatistics(string $companyId, ?int $fiscalYear = null): array
    {
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $fiscalYear);
        $threshold = EuSalesThreshold::getOrCreateForCompany($companyId, $fiscalYear);

        return [
            'company_id' => $companyId,
            'fiscal_year' => $fiscalYear ?: now()->year,
            'current_amount' => $config->current_eu_sales_amount,
            'threshold_amount' => $config->eu_sales_threshold,
            'threshold_percentage' => $config->getThresholdPercentage(),
            'remaining_amount' => $config->getRemainingThresholdAmount(),
            'exceeded' => $config->checkThreshold(),
            'exceeded_at' => $config->threshold_exceeded_at,
            'applies_destination_vat' => $config->shouldApplyDestinationVat(),
            'breakdown_by_country' => $threshold->breakdown_by_country ?? [],
            'top_countries' => $threshold->getTopCountriesBySales(5),
        ];
    }

    /**
     * Enable destination VAT for a company.
     */
    public function enableDestinationVat(string $companyId, ?int $fiscalYear = null): CompanyFiscalConfig
    {
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $fiscalYear);
        $config->enableDestinationVat();

        $this->clearCompanyCache($companyId);

        Log::info('Destination VAT enabled for company', [
            'company_id' => $companyId,
            'fiscal_year' => $fiscalYear ?: now()->year,
        ]);

        return $config;
    }

    /**
     * Disable destination VAT for a company.
     */
    public function disableDestinationVat(string $companyId, ?int $fiscalYear = null): CompanyFiscalConfig
    {
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $fiscalYear);
        $config->disableDestinationVat();

        $this->clearCompanyCache($companyId);

        Log::info('Destination VAT disabled for company', [
            'company_id' => $companyId,
            'fiscal_year' => $fiscalYear ?: now()->year,
        ]);

        return $config;
    }

    /**
     * Reset EU sales for new fiscal year.
     */
    public function resetEuSalesForNewYear(string $companyId, int $newFiscalYear): void
    {
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $newFiscalYear);
        $config->resetEuSales();

        $threshold = EuSalesThreshold::getOrCreateForCompany($companyId, $newFiscalYear);
        $threshold->resetForNewYear($newFiscalYear);

        $this->clearCompanyCache($companyId);

        Log::info('EU sales reset for new fiscal year', [
            'company_id' => $companyId,
            'new_fiscal_year' => $newFiscalYear,
        ]);
    }

    /**
     * Clear cache for a company.
     */
    private function clearCompanyCache(string $companyId): void
    {
        $this->cacheService->clearByTag('company');
    }

    /**
     * Get VAT categories for a country.
     */
    public function getVatCategories(string $countryCode): \Illuminate\Database\Eloquent\Collection
    {
        $cacheKey = "vat_categories:{$countryCode}";

        return $this->cacheService->remember($cacheKey, function () use ($countryCode) {
            return VatCategory::getByCountry($countryCode);
        });
    }

    /**
     * Get all EU countries with VAT rates.
     */
    public function getEuCountriesWithVatRates(): \Illuminate\Database\Eloquent\Collection
    {
        $cacheKey = 'eu_countries_vat_rates';

        return $this->cacheService->remember($cacheKey, function () {
            return CountryVatRate::getEuCountries();
        });
    }

    /**
     * Import VAT rates from external source.
     */
    public function importVatRates(array $data, string $dataSource = 'external'): int
    {
        $imported = CountryVatRate::importFromDataSource($data, $dataSource);

        // Clear VAT rates cache
        $this->cacheService->clearByTag('vat');

        Log::info('VAT rates imported', [
            'count' => $imported,
            'data_source' => $dataSource,
        ]);

        return $imported;
    }

    /**
     * Get destination VAT statistics.
     */
    public function getDestinationVatStatistics(?int $fiscalYear = null): array
    {
        $cacheKey = 'destination_vat_statistics';

        return $this->cacheService->remember($cacheKey, function () use ($fiscalYear) {
            $query = CompanyFiscalConfig::query();
            if ($fiscalYear) {
                $query->where('fiscal_year', $fiscalYear);
            }

            $totalCompanies = $query->count();
            $companiesExceeding = (clone $query)->whereColumn('current_eu_sales_amount', '>=', 'eu_sales_threshold')->count();
            $companiesUsingDestination = (clone $query)->where('apply_destination_iva', true)->count();
            $totalEuSales = (clone $query)->sum('current_eu_sales_amount');

            // Calculate average threshold percentage
            $configs = (clone $query)->get();
            $totalPercentage = 0;
            $count = 0;
            foreach ($configs as $config) {
                if ($config->eu_sales_threshold > 0) {
                    $percentage = ($config->current_eu_sales_amount / $config->eu_sales_threshold) * 100;
                    $totalPercentage += $percentage;
                    $count++;
                }
            }
            $averageThresholdPercentage = $count > 0 ? $totalPercentage / $count : 0;

            return [
                'total_companies' => $totalCompanies,
                'companies_exceeding_threshold' => $companiesExceeding,
                'companies_using_destination_vat' => $companiesUsingDestination,
                'total_eu_sales' => (float) $totalEuSales,
                'average_threshold_percentage' => (float) $averageThresholdPercentage,
                'vat_rate_statistics' => CountryVatRate::getVatRateStatistics(),
            ];
        });
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
     * Get fiscal year start date.
     */
    public function getFiscalYearStartDate(int $fiscalYear): \DateTime
    {
        $fiscalYearStart = config('larabill.destination_vat.fiscal_year_start', '01-01');

        return \DateTime::createFromFormat('Y-m-d', $fiscalYear.'-'.$fiscalYearStart);
    }

    /**
     * Get fiscal year end date.
     */
    public function getFiscalYearEndDate(int $fiscalYear): \DateTime
    {
        $startDate = $this->getFiscalYearStartDate($fiscalYear);
        $endDate = clone $startDate;
        $endDate->add(new \DateInterval('P1Y'))->sub(new \DateInterval('P1D'));

        return $endDate;
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
     * Get VAT rate for a specific category in a country.
     */
    public function getVatRateForCategory(string $countryCode, string $categoryName): float
    {
        $category = VatCategory::where('country_code', $countryCode)
            ->where('name', $categoryName)
            ->where('is_active', true)
            ->first();

        if (! $category) {
            throw new \InvalidArgumentException("VAT category not found: {$categoryName} for country {$countryCode}");
        }

        return (float) $category->vat_rate;
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
     * Update EU sales amount for a company.
     */
    public function updateEuSalesAmount(string $companyId, int $fiscalYear, string $countryCode, float $amount): CompanyFiscalConfig
    {
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $fiscalYear);

        $config->current_eu_sales_amount = (float) $config->current_eu_sales_amount + $amount;

        if ($config->current_eu_sales_amount >= $config->eu_sales_threshold) {
            $config->apply_destination_iva = true;
            $config->threshold_exceeded_at = now();
        }

        $config->save();

        return $config;
    }

    /**
     * Get EU sales threshold status for a company.
     */
    public function getEuSalesThresholdStatus(string $companyId, int $fiscalYear): array
    {
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $fiscalYear);
        $threshold = (float) $config->eu_sales_threshold;
        $current = (float) $config->current_eu_sales_amount;

        return [
            'threshold' => $threshold,
            'current_amount' => $current,
            'exceeded' => $current >= $threshold,
            'percentage' => ($current / $threshold) * 100,
            'remaining' => max(0, $threshold - $current),
        ];
    }

    /**
     * Get EU sales breakdown by country for a company.
     */
    public function getEuSalesBreakdownByCountry(string $companyId, int $fiscalYear): array
    {
        $threshold = EuSalesThreshold::findByCompanyAndYear($companyId, $fiscalYear);

        if (! $threshold) {
            return ['total' => 0.0, 'countries' => []];
        }

        $breakdown = $threshold->breakdown_by_country ?? [];
        $total = (float) array_sum($breakdown);

        // Convert all country values to float
        $floatBreakdown = [];
        foreach ($breakdown as $country => $amount) {
            $floatBreakdown[$country] = (float) $amount;
        }

        return [
            'total' => $total,
            'countries' => $floatBreakdown,
        ];
    }

    /**
     * Get companies exceeding threshold for a fiscal year.
     */
    public function getCompaniesExceedingThreshold(int $fiscalYear)
    {
        return CompanyFiscalConfig::where('fiscal_year', $fiscalYear)
            ->whereColumn('current_eu_sales_amount', '>=', 'eu_sales_threshold')
            ->get();
    }

    /**
     * Get companies needing notification for threshold exceeded.
     */
    public function getCompaniesNeedingNotification(int $fiscalYear)
    {
        return CompanyFiscalConfig::where('fiscal_year', $fiscalYear)
            ->where(function ($query) {
                $query->where('apply_destination_iva', true)
                    ->orWhere('threshold_exceeded', true);
            })
            ->where('notification_sent', false)
            ->get();
    }

    /**
     * Send threshold exceeded notification (public method for testing).
     */
    public function sendThresholdExceededNotification(CompanyFiscalConfig $config): bool
    {
        // This would typically send an email notification
        // For now, we'll just mark it as sent
        $config->notification_sent = true;
        $config->save();

        return true;
    }

    /**
     * Reset EU sales for new fiscal year.
     */
    public function resetEuSalesForNewFiscalYear(string $companyId, int $newFiscalYear): bool
    {
        $config = CompanyFiscalConfig::getOrCreateForCompany($companyId, $newFiscalYear);

        $config->current_eu_sales_amount = 0.0;
        $config->apply_destination_iva = false;
        $config->threshold_exceeded = false;
        $config->threshold_exceeded_at = null;
        $config->notification_sent = false;

        $config->save();

        // Also reset EuSalesThreshold
        $threshold = EuSalesThreshold::findByCompanyAndYear($companyId, $newFiscalYear);
        if (! $threshold) {
            EuSalesThreshold::create([
                'company_id' => $companyId,
                'fiscal_year' => $newFiscalYear,
                'total_amount' => 0.0,
                'threshold_exceeded' => false,
                'notification_sent' => false,
                'breakdown_by_country' => [],
            ]);
        } else {
            $threshold->total_amount = 0.0;
            $threshold->threshold_exceeded = false;
            $threshold->notification_sent = false;
            $threshold->breakdown_by_country = [];
            $threshold->save();
        }

        return true;
    }

    /**
     * Check if date is within fiscal year.
     */
    public function isWithinFiscalYear(int $fiscalYear, \DateTime $date): bool
    {
        $currentYear = (int) $date->format('Y');

        return $currentYear === $fiscalYear;
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
     */
    public function getAvailableDestinationCountries(): array
    {
        return CountryVatRate::where('is_active', true)
            ->pluck('country_code')
            ->toArray();
    }

    /**
     * Get VAT rate comparison between countries.
     */
    public function getVatRateComparison(array $countryCodes): array
    {
        $comparison = [];
        $rates = [];

        foreach ($countryCodes as $countryCode) {
            $countryVatRate = CountryVatRate::findByCountryCode($countryCode);
            if ($countryVatRate) {
                $rate = (float) $countryVatRate->standard_rate;
                $comparison[$countryCode] = $rate;
                $rates[] = $rate;
            }
        }

        if (! empty($rates)) {
            $comparison['average'] = array_sum($rates) / count($rates);
            $comparison['highest'] = max($rates);
            $comparison['lowest'] = min($rates);
        }

        return $comparison;
    }

    /**
     * Calculate VAT savings between countries.
     */
    public function calculateVatSavings(float $amount, string $sourceCountry, string $destinationCountry): array
    {
        $sourceRate = $this->getVatRateForCountry($sourceCountry);
        $destinationRate = $this->getVatRateForCountry($destinationCountry);

        $sourceVat = $amount * ($sourceRate / 100);
        $destinationVat = $amount * ($destinationRate / 100);

        $savings = $sourceVat - $destinationVat;
        $percentage = $sourceRate - $destinationRate;

        return [
            'amount' => $savings,
            'percentage' => $percentage,
            'source_vat' => $sourceVat,
            'destination_vat' => $destinationVat,
            'from_country' => $sourceCountry,
            'to_country' => $destinationCountry,
        ];
    }

    /**
     * Get fiscal year information.
     */
    public function getFiscalYearInfo(int $fiscalYear): array
    {
        $startDate = $this->getFiscalYearStartDate($fiscalYear);
        $endDate = $this->getFiscalYearEndDate($fiscalYear);
        $currentYear = (int) now()->format('Y');

        return [
            'year' => $fiscalYear,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'days' => $startDate->diff($endDate)->days + 1,
            'is_current' => $currentYear === $fiscalYear,
        ];
    }

    /**
     * Get service configuration.
     */
    public function getConfiguration(): array
    {
        return config('larabill.destination_vat', []);
    }

    /**
     * Update service configuration.
     */
    public function updateConfiguration(array $config): void
    {
        foreach ($config as $key => $value) {
            config(["larabill.destination_vat.{$key}" => $value]);
        }
    }
}
