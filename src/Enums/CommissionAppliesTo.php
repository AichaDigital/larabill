<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Commission Applies To Enum
 *
 * Represents the base amount on which the commission is calculated.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum CommissionAppliesTo: int
{
    case TAXABLE_AMOUNT = 0;  // Calculate on base amount before tax
    case TOTAL_AMOUNT   = 1;  // Calculate on total amount including tax

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::TAXABLE_AMOUNT => 'Taxable Amount',
            self::TOTAL_AMOUNT   => 'Total Amount',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::TAXABLE_AMOUNT => 'Commission calculated on the base amount before taxes.',
            self::TOTAL_AMOUNT   => 'Commission calculated on the total amount including taxes.',
        };
    }
}
