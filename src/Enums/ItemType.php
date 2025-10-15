<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * ItemType Enum
 *
 * Represents the type of invoice item: good (physical product) or service.
 * Critical for EU tax rules where services have different treatment.
 *
 * @method string label() Get human-readable label
 * @method bool requiresServiceDates() Check if service dates are required
 * @method static array<int, string> toArray() Get all cases as [value => label] array
 */
enum ItemType: int
{
    case GOOD    = 0;
    case SERVICE = 1;

    /**
     * Get human-readable label (basic method, NOT Filament contract)
     */
    public function label(): string
    {
        return match ($this) {
            self::GOOD    => __('larabill::enums.item_type.good'),
            self::SERVICE => __('larabill::enums.item_type.service'),
        };
    }

    /**
     * Check if this item type requires service dates
     */
    public function requiresServiceDates(): bool
    {
        return $this === self::SERVICE;
    }

    /**
     * Get all cases as array [value => label]
     *
     * @return array<int, string>
     */
    public static function toArray(): array
    {
        return [
            self::GOOD->value    => self::GOOD->label(),
            self::SERVICE->value => self::SERVICE->label(),
        ];
    }
}
