<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\CompanyFiscalConfig;
use AichaDigital\Larabill\Models\Invoice;
use AichaDigital\Larabill\Models\InvoiceItem;
use AichaDigital\Larabill\Models\TaxRate;
use AichaDigital\Larabill\Models\UserTaxProfile;
use AichaDigital\Larabill\Tests\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * ModelMappingService
 *
 * Handles dynamic model mapping and field mapping for agnostic package usage.
 *
 * @internal Implementation detail — may change without a major version (AID-413).
 */
class ModelMappingService
{
    /**
     * Get the configured model class for a given model type.
     */
    public static function getModelClass(string $modelType): string
    {
        // ADR-003: Customer removed, unified into User with billable_user_id
        $defaultModels = [
            'user'                   => User::class,
            'invoice'                => Invoice::class,
            'invoice_item'           => InvoiceItem::class,
            'tax_rate'               => TaxRate::class,
            'company_fiscal_config'  => CompanyFiscalConfig::class,
            'user_tax_profile'       => UserTaxProfile::class,
        ];

        $configuredModel = config("larabill.models.{$modelType}");

        if ($configuredModel && class_exists($configuredModel)) {
            return $configuredModel;
        }

        return $defaultModels[$modelType] ?? throw new \InvalidArgumentException("Unknown model type: {$modelType}");
    }

    /**
     * Get field mapping for a given model type.
     *
     * @return array<string, string>
     */
    public static function getFieldMapping(string $modelType): array
    {
        return config("larabill.field_mappings.{$modelType}", []);
    }

    /**
     * Map field names using the configured mapping.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mapFields(array $data, string $modelType): array
    {
        $fieldMapping = self::getFieldMapping($modelType);

        if (empty($fieldMapping)) {
            return $data;
        }

        $mappedData = [];
        foreach ($data as $key => $value) {
            // Check if there's a mapping for this field
            $mappedKey = array_search($key, $fieldMapping, true);
            if ($mappedKey !== false) {
                $mappedData[$mappedKey] = $value;
            } else {
                $mappedData[$key] = $value;
            }
        }

        return $mappedData;
    }

    /**
     * Reverse map field names (from mapped to original).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function reverseMapFields(array $data, string $modelType): array
    {
        $fieldMapping = self::getFieldMapping($modelType);

        if (empty($fieldMapping)) {
            return $data;
        }

        $reverseMappedData = [];
        foreach ($data as $key => $value) {
            // Check if there's a reverse mapping for this field
            if (isset($fieldMapping[$key])) {
                $reverseMappedData[$fieldMapping[$key]] = $value;
            } else {
                $reverseMappedData[$key] = $value;
            }
        }

        return $reverseMappedData;
    }

    /**
     * Validate model mapping configuration.
     */
    public static function validateModelMapping(string $modelType, string $modelClass): bool
    {
        if (! class_exists($modelClass)) {
            return false;
        }

        // Check if the class extends Model
        if (! is_subclass_of($modelClass, Model::class)) {
            return false;
        }

        return true;
    }
}
