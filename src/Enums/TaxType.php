<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Tax Type Enum
 *
 * Represents different types of taxes supported by the system.
 * This enum is agnostic to regional implementations.
 */
enum TaxType: string
{
    case VAT       = 'vat';           // Value Added Tax (EU/UK)
    case SALES_TAX = 'sales_tax'; // Sales Tax (US/Canada)
    case GST       = 'gst';           // Goods and Services Tax (Australia/NZ/India)
    case OTHER     = 'other';       // Custom/Other tax types

    /**
     * Get human-readable label for the tax type.
     */
    public function label(): string
    {
        return match ($this) {
            self::VAT       => 'Value Added Tax',
            self::SALES_TAX => 'Sales Tax',
            self::GST       => 'Goods and Services Tax',
            self::OTHER     => 'Other',
        };
    }

    /**
     * Get all available values as array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
