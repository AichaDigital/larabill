<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use Illuminate\Support\Facades\Log;

/**
 * Company Configuration Service
 *
 * Handles the fiscal configuration for the company using the software.
 * This service works with a singleton CompanyFiscalConfig model.
 */
class CompanyConfigService
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
     * Get current company configuration.
     */
    public function getCurrentConfig(): CompanyFiscalConfig
    {
        $cacheKey = 'company_config_current';

        return $this->cacheService->remember($cacheKey, function () {
            return CompanyFiscalConfig::getOrCreateForCompany('default-company', 2024);
        });
    }

    /**
     * Update company configuration.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateConfig(array $data): CompanyFiscalConfig
    {
        $config = $this->getCurrentConfig();

        // Validate configuration
        $errors = $this->validateConfigData($data);
        if (! empty($errors)) {
            throw new \InvalidArgumentException('Invalid company configuration: '.implode(', ', $errors));
        }

        $config->update($data);

        // Clear cache
        $this->clearConfigCache();

        Log::info('Company configuration updated', [
            'updated_fields' => array_keys($data),
        ]);

        return $config;
    }

    /**
     * Enable OSS registration.
     */
    public function enableOSS(): CompanyFiscalConfig
    {
        $config = $this->getCurrentConfig();
        $config->enableOSS();
        $this->clearConfigCache();

        Log::info('OSS registration enabled');

        return $config;
    }

    /**
     * Disable OSS registration.
     */
    public function disableOSS(): CompanyFiscalConfig
    {
        $config = $this->getCurrentConfig();
        $config->disableOSS();
        $this->clearConfigCache();

        Log::info('OSS registration disabled');

        return $config;
    }

    /**
     * Enable ROI status.
     */
    public function enableROI(): CompanyFiscalConfig
    {
        $config = $this->getCurrentConfig();
        $config->enableROI();
        $this->clearConfigCache();

        Log::info('ROI status enabled');

        return $config;
    }

    /**
     * Disable ROI status.
     */
    public function disableROI(): CompanyFiscalConfig
    {
        $config = $this->getCurrentConfig();
        $config->disableROI();
        $this->clearConfigCache();

        Log::info('ROI status disabled');

        return $config;
    }

    /**
     * Update EU sales threshold for specific company.
     */
    public function updateEuSalesThreshold(string $companyId, int $fiscalYear, float|string $threshold): CompanyFiscalConfig
    {
        return $this->updateCompanyConfig($companyId, $fiscalYear, ['eu_sales_threshold' => (float) $threshold]);
    }

    /**
     * Update EU sales threshold for current company (for testing).
     */
    public function updateThreshold(float|string $threshold): CompanyFiscalConfig
    {
        $companyId  = 'default-company';
        $fiscalYear = 2024;

        // Get or create the configuration first
        $config = $this->getOrCreateCompanyConfig($companyId, $fiscalYear);
        $config->update(['eu_sales_threshold' => CompanyFiscalConfig::amountToBase100((float) $threshold)]);

        // Clear cache after update
        $this->clearConfigCache();

        return $config->fresh();
    }

    /**
     * Update EU sales amount for specific company.
     */
    public function updateEuSalesAmount(string $companyId, int $fiscalYear, float|string $amount): CompanyFiscalConfig
    {
        $config = $this->getCompanyConfig($companyId, $fiscalYear);
        if (! $config) {
            throw new \Exception("Company configuration not found for {$companyId} in fiscal year {$fiscalYear}");
        }

        $currentAmount = $config->current_eu_sales_amount;
        $amountInBase100 = CompanyFiscalConfig::amountToBase100((float) $amount);
        $newAmount = $currentAmount + $amountInBase100;

        return $this->updateCompanyConfig($companyId, $fiscalYear, ['current_eu_sales_amount' => $newAmount]);
    }

    /**
     * Update EU sales amount for current company (for testing).
     */
    public function updateAmount(float|string $amount): CompanyFiscalConfig
    {
        $companyId  = 'default-company';
        $fiscalYear = 2024;

        // Get or create the configuration first
        $config = $this->getOrCreateCompanyConfig($companyId, $fiscalYear);
        $config->update(['current_eu_sales_amount' => CompanyFiscalConfig::amountToBase100((float) $amount)]);

        // Check threshold after updating amount
        $config->fresh()->checkThreshold();

        return $config->fresh();
    }

    /**
     * Reset EU sales for new fiscal year.
     */
    public function resetEuSalesForNewYear(int $newYear): CompanyFiscalConfig
    {
        $config = $this->getCurrentConfig();
        $config->resetForNewYear($newYear);
        $this->clearConfigCache();

        Log::info('EU sales reset for new fiscal year', ['new_year' => $newYear]);

        return $config;
    }

    /**
     * Mark notification as sent.
     */
    public function markNotificationSent(): CompanyFiscalConfig
    {
        $config = $this->getCurrentConfig();
        $config->markNotificationSent();
        $this->clearConfigCache();

        return $config;
    }

    /**
     * Check if company needs notification.
     */
    public function needsNotification(): bool
    {
        $config = $this->getCurrentConfig();

        return (bool) $config->shouldSendNotification();
    }

    /**
     * Get companies that need notification (always returns current company if needed).
     *
     * @return array<int, CompanyFiscalConfig>
     */
    public function getCompaniesNeedingNotification(): array
    {
        $config = $this->getCurrentConfig();

        if ($config->shouldSendNotification()) {
            return [$config];
        }

        return [];
    }

    /**
     * Check if destination VAT should be applied.
     */
    public function shouldApplyDestinationVat(): bool
    {
        $config = $this->getCurrentConfig();

        return $config->shouldApplyDestinationVat();
    }

    /**
     * Get threshold percentage.
     */
    public function getThresholdPercentage(): float
    {
        $config = $this->getCurrentConfig();

        return $config->getThresholdPercentage();
    }

    /**
     * Get remaining amount until threshold.
     */
    public function getRemainingThresholdAmount(): float
    {
        $config = $this->getCurrentConfig();

        return $config->getRemainingThresholdAmount();
    }

    /**
     * Get company configuration statistics.
     *
     * @return array<string, mixed>
     */
    public function getCompanyStatistics(): array
    {
        $config = $this->getCurrentConfig();

        return [
            'is_oss'                       => $config->is_oss,
            'is_roi'                       => $config->is_roi,
            'eu_sales_threshold'           => $config->eu_sales_threshold,
            'current_eu_sales_amount'      => $config->current_eu_sales_amount,
            'threshold_percentage'         => $config->getThresholdPercentage(),
            'threshold_exceeded'           => $config->threshold_exceeded,
            'notification_sent'            => $config->notification_sent,
            'needs_notification'           => $config->shouldSendNotification(),
            'should_apply_destination_vat' => $config->shouldApplyDestinationVat(),
            'fiscal_year'                  => $config->fiscal_year,
            'currency'                     => $config->currency,
        ];
    }

    /**
     * Validate configuration data.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function validateConfigData(array $data): array
    {
        $errors = [];

        // Validate numeric fields
        $numericFields = ['eu_sales_threshold', 'current_eu_sales_amount'];
        foreach ($numericFields as $field) {
            if (isset($data[$field]) && ! is_numeric($data[$field])) {
                $errors[] = "Field '{$field}' must be numeric";
            }
        }

        // Validate boolean fields
        $booleanFields = ['is_oss', 'is_roi', 'auto_apply_destination', 'notification_sent', 'threshold_exceeded'];
        foreach ($booleanFields as $field) {
            if (isset($data[$field]) && ! is_bool($data[$field])) {
                $errors[] = "Field '{$field}' must be boolean";
            }
        }

        // Validate currency
        if (isset($data['currency']) && ! preg_match('/^[A-Z]{3}$/', $data['currency'])) {
            $errors[] = "Field 'currency' must be a valid 3-letter currency code";
        }

        // Validate fiscal year start
        if (isset($data['fiscal_year_start']) && ! preg_match('/^\d{2}-\d{2}$/', $data['fiscal_year_start'])) {
            $errors[] = "Field 'fiscal_year_start' must be in MM-DD format";
        }

        return $errors;
    }

    /**
     * Get company configuration.
     */
    public function getCompanyConfig(string $companyId, ?int $fiscalYear = null): ?CompanyFiscalConfig
    {
        $fiscalYear = $fiscalYear ?? date('Y');

        return CompanyFiscalConfig::where('company_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->first();
    }

    /**
     * Create a new company configuration.
     *
     * @param  array<string, mixed>  $data
     */
    public function createCompanyConfig(string $companyId, int $fiscalYear, array $data = []): CompanyFiscalConfig
    {
        // Merge with default configuration
        $defaultConfig = $this->getDefaultConfig();
        $configData    = array_merge($defaultConfig, $data, [
            'company_id'  => $companyId,
            'fiscal_year' => $fiscalYear,
        ]);

        // Validate configuration
        $errors = $this->validateConfigData($configData);
        if (! empty($errors)) {
            throw new \InvalidArgumentException('Invalid company configuration: '.implode(', ', $errors));
        }

        // Ensure monetary values are properly handled with factor 100
        if (isset($configData['eu_sales_threshold'])) {
            $configData['eu_sales_threshold'] = (float) $configData['eu_sales_threshold'];
        }
        if (isset($configData['current_eu_sales_amount'])) {
            $configData['current_eu_sales_amount'] = (float) $configData['current_eu_sales_amount'];
        }

        $config = CompanyFiscalConfig::create($configData);

        Log::info('Company configuration created', [
            'company_id'  => $companyId,
            'fiscal_year' => $fiscalYear,
            'config_id'   => $config->id,
        ]);

        return $config;
    }

    /**
     * Get default configuration.
     *
     * @return array<string, mixed>
     */
    public function getDefaultConfig(): array
    {
        return [
            'is_oss'                  => false,
            'is_roi'                  => false,
            'apply_destination_iva'   => false,
            'eu_sales_threshold'      => 10000,
            'current_eu_sales_amount' => 0,
            'threshold_exceeded'      => false,
            'auto_apply_destination'  => true,
            'notification_sent'       => false,
            'fiscal_year_start'       => '01-01',
            'currency'                => 'EUR',
        ];
    }

    /**
     * Update company configuration by company ID and fiscal year.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCompanyConfig(string $companyId, int $fiscalYear, array $data): CompanyFiscalConfig
    {
        $config = CompanyFiscalConfig::where('company_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->firstOrFail();

        $config->update($data);

        Log::info('Company configuration updated', [
            'company_id'     => $companyId,
            'fiscal_year'    => $fiscalYear,
            'updated_fields' => array_keys($data),
        ]);

        return $config;
    }

    /**
     * Delete company configuration.
     */
    public function deleteCompanyConfig(string $companyId, int $fiscalYear): bool
    {
        $deleted = CompanyFiscalConfig::where('company_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->delete();

        Log::info('Company configuration deleted', [
            'company_id'  => $companyId,
            'fiscal_year' => $fiscalYear,
            'deleted'     => $deleted > 0,
        ]);

        return $deleted > 0;
    }

    /**
     * Check if company configuration exists.
     */
    public function hasCompanyConfig(string $companyId, int $fiscalYear): bool
    {
        return CompanyFiscalConfig::where('company_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->exists();
    }

    /**
     * Get or create company configuration.
     *
     * @param  array<string, mixed>  $data
     */
    public function getOrCreateCompanyConfig(string $companyId, int $fiscalYear, array $data = []): CompanyFiscalConfig
    {
        return CompanyFiscalConfig::firstOrCreate(
            [
                'company_id'  => $companyId,
                'fiscal_year' => $fiscalYear,
            ],
            array_merge($this->getDefaultConfig(), $data)
        );
    }

    /**
     * Get all company configurations.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompanyFiscalConfig>
     */
    public function getAllCompanyConfigs(): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyFiscalConfig::all();
    }

    /**
     * Get company configurations by fiscal year.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompanyFiscalConfig>
     */
    public function getCompanyConfigsByFiscalYear(int $fiscalYear): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyFiscalConfig::where('fiscal_year', $fiscalYear)->get();
    }

    /**
     * Get company configurations by destination VAT status.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompanyFiscalConfig>
     */
    public function getCompanyConfigsByDestinationVatStatus(bool $applyDestinationVat): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyFiscalConfig::where('apply_destination_iva', $applyDestinationVat)->get();
    }

    /**
     * Get company configurations by threshold status.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompanyFiscalConfig>
     */
    public function getCompanyConfigsByThresholdStatus(bool $thresholdExceeded): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyFiscalConfig::where('threshold_exceeded', $thresholdExceeded)->get();
    }

    /**
     * Get company configurations needing notification.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompanyFiscalConfig>
     */
    public function getCompanyConfigsNeedingNotification(): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyFiscalConfig::where('threshold_exceeded', true)
            ->where('notification_sent', false)
            ->get();
    }

    /**
     * Enable destination VAT for a specific company.
     */
    public function enableDestinationVat(string $companyId, int $fiscalYear): CompanyFiscalConfig
    {
        return $this->updateCompanyConfig($companyId, $fiscalYear, ['apply_destination_iva' => true]);
    }

    /**
     * Disable destination VAT for a specific company.
     */
    public function disableDestinationVat(string $companyId, int $fiscalYear): CompanyFiscalConfig
    {
        return $this->updateCompanyConfig($companyId, $fiscalYear, ['apply_destination_iva' => false]);
    }

    /**
     * Reset EU sales for a specific company.
     */
    public function resetEuSales(string $companyId, int $fiscalYear): CompanyFiscalConfig
    {
        return $this->updateCompanyConfig($companyId, $fiscalYear, [
            'current_eu_sales_amount' => 0.0,
            'threshold_exceeded'      => false,
            'notification_sent'       => false,
        ]);
    }

    /**
     * Merge configuration data with defaults.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mergeWithDefaults(array $data): array
    {
        $merged = array_merge($this->getDefaultConfig(), $data);

        // Convert monetary values to integers (factor 100)
        if (isset($merged['eu_sales_threshold'])) {
            $merged['eu_sales_threshold'] = (int) $merged['eu_sales_threshold'];
        }
        if (isset($merged['current_eu_sales_amount'])) {
            $merged['current_eu_sales_amount'] = (int) $merged['current_eu_sales_amount'];
        }

        return $merged;
    }

    /**
     * Get configuration by mapping.
     */
    public function getCompanyConfigByMapping(string $companyId, int $fiscalYear): ?CompanyFiscalConfig
    {
        return CompanyFiscalConfig::where('company_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->first();
    }

    /**
     * Get company configuration statistics.
     *
     * @return array<string, mixed>
     */
    public function getCompanyConfigStatistics(int $fiscalYear): array
    {
        $configs = CompanyFiscalConfig::where('fiscal_year', $fiscalYear)->get();

        $totalSales = $configs->sum('current_eu_sales_amount');

        // Calculate average threshold percentage as average of individual percentages
        $thresholdPercentages = $configs->map(function ($config) {
            return $config->eu_sales_threshold > 0 ?
                ($config->current_eu_sales_amount / $config->eu_sales_threshold) * 100 : 0;
        });
        $averageThresholdPercentage = $thresholdPercentages->avg();

        return [
            'total_companies'                 => $configs->count(),
            'companies_using_destination_vat' => $configs->where('apply_destination_iva', true)->count(),
            'companies_exceeding_threshold'   => $configs->where('threshold_exceeded', true)->count(),
            'companies_needing_notification'  => $configs->where('threshold_exceeded', true)
                ->where('notification_sent', false)->count(),
            'total_eu_sales'               => $totalSales,
            'average_threshold_usage'      => $configs->avg('current_eu_sales_amount') / 10000.0 * 100,
            'average_threshold_percentage' => $averageThresholdPercentage,
        ];
    }

    /**
     * Get service configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return [
            'model'                  => CompanyFiscalConfig::class,
            'default_threshold'      => 10000,
            'currency'               => 'EUR',
            'fiscal_year_start'      => '01-01',
            'cache_enabled'          => true,
            'auto_apply_destination' => true,
        ];
    }

    /**
     * Bulk update company configurations.
     *
     * @param  array<string, mixed>  $companyUpdates
     */
    public function bulkUpdateCompanyConfigs(array $companyUpdates, int $fiscalYear): int
    {
        $successCount = 0;

        foreach ($companyUpdates as $companyId => $data) {
            try {
                $this->updateCompanyConfig($companyId, $fiscalYear, $data);
                $successCount++;
            } catch (\Exception $e) {
                // Log error but continue with other updates
                Log::error('Bulk update failed for company', [
                    'company_id' => $companyId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $successCount;
    }

    /**
     * Get company configurations by fiscal year range.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompanyFiscalConfig>
     */
    public function getCompanyConfigsByFiscalYearRange(string $companyId, int $startYear, int $endYear): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyFiscalConfig::where('company_id', $companyId)
            ->whereBetween('fiscal_year', [$startYear, $endYear])
            ->orderBy('fiscal_year')
            ->get();
    }

    /**
     * Update service configuration.
     *
     * @param  array<string, mixed>  $newConfig
     */
    public function updateConfiguration(array $newConfig): void
    {
        // Update Laravel config values
        foreach ($newConfig as $key => $value) {
            config(["larabill.destination_vat.{$key}" => $value]);
        }

        Log::info('Service configuration updated', ['updated_keys' => array_keys($newConfig)]);
    }

    /**
     * Validate company configuration data.
     *
     * @param  array<string, mixed>  $data
     */
    public function validateCompanyConfigData(array $data): bool
    {
        $errors = $this->validateConfigData($data);

        return empty($errors);
    }

    /**
     * Get default company configuration.
     *
     * @return array<string, mixed>
     */
    public function getDefaultCompanyConfig(): array
    {
        return $this->getDefaultConfig();
    }

    /**
     * Create company configuration with mapping.
     *
     * @param  array<string, mixed>  $data
     */
    public function createCompanyConfigWithMapping(string $companyId, int $fiscalYear, array $data = []): CompanyFiscalConfig
    {
        return $this->createCompanyConfig($companyId, $fiscalYear, $data);
    }

    /**
     * Clear configuration cache.
     */
    private function clearConfigCache(): void
    {
        $this->cacheService->forget('company_config_current');
    }

    /**
     * Handle service errors gracefully.
     *
     * @return array<string, mixed>
     */
    public function handleError(\Exception $e): array
    {
        Log::error('CompanyConfigService error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return [
            'error'   => true,
            'message' => $e->getMessage(),
            'config'  => $this->getDefaultConfig(),
        ];
    }
}
