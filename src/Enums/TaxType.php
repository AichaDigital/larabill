<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Tax Type Enum
 *
 * Represents different types of taxes supported by the system.
 * This enum is agnostic to regional implementations.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum TaxType: int
{
    case VAT       = 0;  // Value Added Tax (EU/UK)
    case SALES_TAX = 1;  // Sales Tax (US/Canada)
    case GST       = 2;  // Goods and Services Tax (Australia/NZ/India)
    case OTHER     = 3;  // Custom/Other tax types

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
     * @return array<int>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
