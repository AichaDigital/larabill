<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * ItemType Enum
 *
 * Represents the type of invoice item: good (physical product) or service.
 * Critical for EU tax rules where services have different treatment.
 *
 * Usage in Filament (agnostic - no Filament dependency):
 * ```php
 * Forms\Components\Select::make('type')
 *     ->options(ItemType::toArray())
 *     ->label(__('larabill::fields.item_type'))
 * ```
 */
enum ItemType: int
{
    case GOOD    = 0;
    case SERVICE = 1;

    /**
     * Get a human-readable label (basic method, NOT Filament contract)
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
