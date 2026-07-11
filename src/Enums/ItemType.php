<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * ItemType Enum
 *
 * Represents the type of invoice item: good (physical product) or service.
 * Critical for EU tax rules where services have different treatment.
 *
 * Usage example:
 * ```php
 * // Get options for select inputs
 * $options = ItemType::toArray();
 * ```
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum ItemType: int
{
    case GOOD    = 0;
    case SERVICE = 1;

    /**
     * Get a human-readable label.
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
