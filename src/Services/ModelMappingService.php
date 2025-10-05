<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

/**
 * ModelMappingService
 *
 * Handles dynamic model mapping and field mapping for agnostic package usage.
 */
class ModelMappingService
{
    /**
     * Get the configured model class for a given model type.
     */
    public static function getModelClass(string $modelType): string
    {
        $defaultModels = [
            'user'             => \AichaDigital\Larabill\Tests\Models\User::class,
            'user_tax_info'    => \AichaDigital\Larabill\Models\UserTaxInfo::class,
            'invoice'          => \AichaDigital\Larabill\Models\Invoice::class,
            'invoice_item'     => \AichaDigital\Larabill\Models\InvoiceItem::class,
            'tax_rate'         => \AichaDigital\Larabill\Models\TaxRate::class,
            'vat_verification' => \AichaDigital\Larabill\Models\VatVerification::class,
        ];

        $configuredModel = config("larabill.models.{$modelType}");

        if ($configuredModel && class_exists($configuredModel)) {
            return $configuredModel;
        }

        return $defaultModels[$modelType] ?? throw new \InvalidArgumentException("Unknown model type: {$modelType}");
    }

    /**
     * Get field mapping for a given model type.
     */
    public static function getFieldMapping(string $modelType): array
    {
        return config("larabill.field_mappings.{$modelType}", []);
    }

    /**
     * Map field names using the configured mapping.
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
        if (! is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)) {
            return false;
        }

        return true;
    }
}
