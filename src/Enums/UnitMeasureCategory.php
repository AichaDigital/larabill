<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * UnitMeasureCategory Enum
 *
 * Represents the category of unit measurement.
 * Used to group unit measures logically (weight, volume, etc.)
 *
 * Usage example:
 * ```php
 * // Get options for select inputs
 * $options = UnitMeasureCategory::toArray();
 * ```
 */
enum UnitMeasureCategory: int
{
    case COUNT  = 0;    // Unit counting
    case WEIGHT = 1;   // Mass/Weight
    case VOLUME = 2;   // Volume
    case LENGTH = 3;   // Length/Distance
    case AREA   = 4;     // Area
    case TIME   = 5;     // Time duration

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::COUNT  => __('larabill::enums.unit_category.count'),
            self::WEIGHT => __('larabill::enums.unit_category.weight'),
            self::VOLUME => __('larabill::enums.unit_category.volume'),
            self::LENGTH => __('larabill::enums.unit_category.length'),
            self::AREA   => __('larabill::enums.unit_category.area'),
            self::TIME   => __('larabill::enums.unit_category.time'),
        };
    }

    /**
     * Get all cases as array [value => label]
     *
     * @return array<int, string>
     */
    public static function toArray(): array
    {
        return [
            self::COUNT->value  => self::COUNT->label(),
            self::WEIGHT->value => self::WEIGHT->label(),
            self::VOLUME->value => self::VOLUME->label(),
            self::LENGTH->value => self::LENGTH->label(),
            self::AREA->value   => self::AREA->label(),
            self::TIME->value   => self::TIME->label(),
        ];
    }
}
