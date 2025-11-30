<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Commission Type Enum
 *
 * Represents how the commission amount is calculated.
 */
enum CommissionType: int
{
    case PERCENTAGE = 0;  // Commission as a percentage
    case FIXED      = 1;  // Fixed amount commission

    /**
     * Get human-readable label for the commission type.
     */
    public function label(): string
    {
        return match ($this) {
            self::PERCENTAGE => 'Percentage',
            self::FIXED      => 'Fixed Amount',
        };
    }

    /**
     * Get description for the commission type.
     */
    public function description(): string
    {
        return match ($this) {
            self::PERCENTAGE => 'Commission calculated as a percentage of the amount.',
            self::FIXED      => 'Commission is a fixed monetary amount.',
        };
    }
}
