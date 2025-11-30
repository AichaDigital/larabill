<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Commission Level Enum
 *
 * Represents the level/scope at which a commission is applied.
 * Priority: product > product_group > global
 */
enum CommissionLevel: int
{
    case GLOBAL        = 0;  // Applies to all sales
    case PRODUCT_GROUP = 1;  // Applies to a product group
    case PRODUCT       = 2;  // Applies to a specific product

    /**
     * Get human-readable label for the commission level.
     */
    public function label(): string
    {
        return match ($this) {
            self::GLOBAL        => 'Global',
            self::PRODUCT_GROUP => 'Product Group',
            self::PRODUCT       => 'Product',
        };
    }

    /**
     * Get description for the commission level.
     */
    public function description(): string
    {
        return match ($this) {
            self::GLOBAL        => 'Commission applies to all sales.',
            self::PRODUCT_GROUP => 'Commission applies to a specific product group.',
            self::PRODUCT       => 'Commission applies to a specific product.',
        };
    }

    /**
     * Get the priority of this level (higher = more specific).
     */
    public function priority(): int
    {
        return match ($this) {
            self::GLOBAL        => 0,
            self::PRODUCT_GROUP => 1,
            self::PRODUCT       => 2,
        };
    }
}
