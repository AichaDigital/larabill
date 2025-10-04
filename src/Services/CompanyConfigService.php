<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use Illuminate\Support\Facades\Log;

/**
 * Company Configuration Service
 *
 * Handles flexible company configuration with support for
 * different company models and field mapping.
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
     * Get company model class.
     */
    public function getCompanyModel(): string
    {
        return config('larabill.models.company', \App\Models\Company::class);
    }

    /**
     * Get or create fiscal config for a company.
     */
    public function getOrCreateFiscalConfig(string $companyId, ?int $fiscalYear = null): CompanyFiscalConfig
    {
        $cacheKey = "company_fiscal_config:{$companyId}:".($fiscalYear ?: now()->year);

        return $this->cacheService->remember($cacheKey, function () use ($companyId, $fiscalYear) {
            return CompanyFiscalConfig::getOrCreateForCompany($companyId, $fiscalYear);
        });
    }

    /**
     * Update fiscal configuration for a company.
     */
    public function updateFiscalConfig(string $companyId, array $data, ?int $fiscalYear = null): CompanyFiscalConfig
    {
        $config = $this->getOrCreateFiscalConfig($companyId, $fiscalYear);
        $config->update($data);

        // Clear cache
        $this->clearCompanyCache($companyId, $fiscalYear);

        Log::info('Company fiscal config updated', [
            'company_id' => $companyId,
            'fiscal_year' => $fiscalYear ?: now()->year,
            'updated_fields' => array_keys($data),
        ]);

        return $config;
    }

    /**
     * Get company field mapping.
     */
    public function getCompanyFieldMapping(): array
    {
        return config('larabill.company_field_mapping', [
            'id' => 'id',
            'name' => 'name',
            'vat_number' => 'vat_number',
            'country' => 'country',
            'address' => 'address',
            'city' => 'city',
            'postal_code' => 'postal_code',
        ]);
    }

    /**
     * Map company fields according to configuration.
     */
    public function mapCompanyFields(array $companyData): array
    {
        $fieldMapping = $this->getCompanyFieldMapping();
        $mappedData = [];

        foreach ($fieldMapping as $configKey => $companyKey) {
            if (isset($companyData[$companyKey])) {
                $mappedData[$configKey] = $companyData[$companyKey];
            }
        }

        return $mappedData;
    }

    /**
     * Reverse map company fields.
     */
    public function reverseMapCompanyFields(array $configData): array
    {
        $fieldMapping = $this->getCompanyFieldMapping();
        $reversedMapping = array_flip($fieldMapping);
        $mappedData = [];

        foreach ($configData as $configKey => $value) {
            if (isset($reversedMapping[$configKey])) {
                $mappedData[$reversedMapping[$configKey]] = $value;
            }
        }

        return $mappedData;
    }

    /**
     * Validate company configuration.
     */
    public function validateCompanyConfig(array $data): array
    {
        $errors = [];

        // Required fields validation
        $requiredFields = ['company_id', 'fiscal_year'];
        foreach ($requiredFields as $field) {
            if (! isset($data[$field]) || empty($data[$field])) {
                $errors[] = "Field '{$field}' is required";
            }
        }

        // Numeric fields validation
        $numericFields = ['eu_sales_threshold', 'current_eu_sales_amount'];
        foreach ($numericFields as $field) {
            if (isset($data[$field]) && ! is_numeric($data[$field])) {
                $errors[] = "Field '{$field}' must be numeric";
            }
        }

        // Boolean fields validation
        $booleanFields = ['apply_destination_iva', 'auto_apply_destination', 'notification_sent'];
        foreach ($booleanFields as $field) {
            if (isset($data[$field]) && ! is_bool($data[$field])) {
                $errors[] = "Field '{$field}' must be boolean";
            }
        }

        // Currency validation
        if (isset($data['currency']) && ! preg_match('/^[A-Z]{3}$/', $data['currency'])) {
            $errors[] = "Field 'currency' must be a valid 3-letter currency code";
        }

        // Fiscal year start validation
        if (isset($data['fiscal_year_start']) && ! preg_match('/^\d{2}-\d{2}$/', $data['fiscal_year_start'])) {
            $errors[] = "Field 'fiscal_year_start' must be in MM-DD format";
        }

        return $errors;
    }

    /**
     * Get company configuration with field mapping.
     */
    public function getCompanyConfig(string $companyId, ?int $fiscalYear = null): array
    {
        $config = $this->getOrCreateFiscalConfig($companyId, $fiscalYear);
        $configData = $config->toArray();

        // Apply field mapping
        return $this->mapCompanyFields($configData);
    }

    /**
     * Set company configuration with field mapping.
     */
    public function setCompanyConfig(string $companyId, array $data, ?int $fiscalYear = null): CompanyFiscalConfig
    {
        // Validate configuration
        $errors = $this->validateCompanyConfig(array_merge($data, ['company_id' => $companyId, 'fiscal_year' => $fiscalYear ?: now()->year]));
        if (! empty($errors)) {
            throw new \InvalidArgumentException('Invalid company configuration: '.implode(', ', $errors));
        }

        // Apply reverse field mapping
        $mappedData = $this->reverseMapCompanyFields($data);

        return $this->updateFiscalConfig($companyId, $mappedData, $fiscalYear);
    }

    /**
     * Get companies with destination VAT enabled.
     */
    public function getCompaniesWithDestinationVat(?int $fiscalYear = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = CompanyFiscalConfig::applyDestinationVat();

        if ($fiscalYear) {
            $query->byFiscalYear($fiscalYear);
        }

        return $query->get();
    }

    /**
     * Get companies over threshold.
     */
    public function getCompaniesOverThreshold(?int $fiscalYear = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = CompanyFiscalConfig::thresholdExceeded();

        if ($fiscalYear) {
            $query->byFiscalYear($fiscalYear);
        }

        return $query->get();
    }

    /**
     * Get companies needing notification.
     */
    public function getCompaniesNeedingNotification(): \Illuminate\Database\Eloquent\Collection
    {
        return CompanyFiscalConfig::needsNotification()->get();
    }

    /**
     * Bulk update company configurations.
     */
    public function bulkUpdateCompanyConfigs(array $configs): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($configs as $config) {
            try {
                $this->setCompanyConfig(
                    $config['company_id'],
                    $config['data'],
                    $config['fiscal_year'] ?? null
                );
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'company_id' => $config['company_id'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Export company configurations.
     */
    public function exportCompanyConfigs(?int $fiscalYear = null): array
    {
        $query = CompanyFiscalConfig::query();

        if ($fiscalYear) {
            $query->byFiscalYear($fiscalYear);
        }

        $configs = $query->get();

        return $configs->map(function ($config) {
            return $this->mapCompanyFields($config->toArray());
        })->toArray();
    }

    /**
     * Import company configurations.
     */
    public function importCompanyConfigs(array $configs): array
    {
        $results = [
            'imported' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($configs as $configData) {
            try {
                $companyId = $configData['company_id'];
                $fiscalYear = $configData['fiscal_year'] ?? null;

                // Check if config exists
                $existingConfig = CompanyFiscalConfig::findByCompanyAndYear($companyId, $fiscalYear);

                $this->setCompanyConfig($companyId, $configData, $fiscalYear);

                if ($existingConfig) {
                    $results['updated']++;
                } else {
                    $results['imported']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'config' => $configData,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Get company configuration statistics.
     */
    public function getCompanyConfigStatistics(?int $fiscalYear = null): array
    {
        $cacheKey = 'company_config_statistics:'.($fiscalYear ?: 'all');

        return $this->cacheService->remember($cacheKey, function () use ($fiscalYear) {
            $query = CompanyFiscalConfig::query();

            if ($fiscalYear) {
                $query->byFiscalYear($fiscalYear);
            }

            $total = $query->count();
            $withDestinationVat = $query->clone()->applyDestinationVat()->count();
            $overThreshold = $query->clone()->thresholdExceeded()->count();
            $needingNotification = $query->clone()->needsNotification()->count();

            return [
                'total_companies' => $total,
                'with_destination_vat' => $withDestinationVat,
                'over_threshold' => $overThreshold,
                'needing_notification' => $needingNotification,
                'destination_vat_percentage' => $total > 0 ? round(($withDestinationVat / $total) * 100, 2) : 0,
                'threshold_exceeded_percentage' => $total > 0 ? round(($overThreshold / $total) * 100, 2) : 0,
            ];
        });
    }

    /**
     * Clear company cache.
     */
    private function clearCompanyCache(string $companyId, ?int $fiscalYear = null): void
    {
        $cacheKeys = [
            "company_fiscal_config:{$companyId}:".($fiscalYear ?: now()->year),
            'company_config_statistics:'.($fiscalYear ?: 'all'),
        ];

        foreach ($cacheKeys as $cacheKey) {
            $this->cacheService->forget($cacheKey);
        }

        // Also clear by tag
        $this->cacheService->clearByTag('company');
    }

    /**
     * Get default company configuration.
     */
    public function getDefaultCompanyConfig(): array
    {
        return [
            'apply_destination_iva' => false,
            'auto_apply_destination' => true,
            'eu_sales_threshold' => CompanyFiscalConfig::getDefaultThreshold(),
            'current_eu_sales_amount' => 0,
            'fiscal_year_start' => CompanyFiscalConfig::getDefaultFiscalYearStart(),
            'currency' => CompanyFiscalConfig::getDefaultCurrency(),
            'notification_sent' => false,
        ];
    }

    /**
     * Validate company model compatibility.
     */
    public function validateCompanyModelCompatibility(): array
    {
        $errors = [];
        $companyModelClass = $this->getCompanyModel();

        if (! class_exists($companyModelClass)) {
            $errors[] = "Company model class '{$companyModelClass}' does not exist";

            return $errors;
        }

        // Check if model has required methods
        $requiredMethods = ['find', 'create', 'update'];
        foreach ($requiredMethods as $method) {
            if (! method_exists($companyModelClass, $method)) {
                $errors[] = "Company model '{$companyModelClass}' does not have required method '{$method}'";
            }
        }

        return $errors;
    }
}
