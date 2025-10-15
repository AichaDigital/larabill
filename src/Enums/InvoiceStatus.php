<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * InvoiceStatus Enum
 *
 * Represents the current status of an invoice in its lifecycle.
 *
 * @method string label() Get human-readable label
 * @method bool canBeEdited() Check if invoice can be edited in this status
 * @method static array<int, string> toArray() Get all cases as [value => label] array
 */
enum InvoiceStatus: int
{
    case DRAFT     = 0;
    case SENT      = 1;
    case PAID      = 2;
    case OVERDUE   = 3;
    case CANCELLED = 4;

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT     => __('larabill::enums.status.draft'),
            self::SENT      => __('larabill::enums.status.sent'),
            self::PAID      => __('larabill::enums.status.paid'),
            self::OVERDUE   => __('larabill::enums.status.overdue'),
            self::CANCELLED => __('larabill::enums.status.cancelled'),
        };
    }

    /**
     * Check if invoice can be edited in this status
     * Only drafts can be edited
     */
    public function canBeEdited(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Get all cases as array [value => label]
     *
     * @return array<int, string>
     */
    public static function toArray(): array
    {
        return [
            self::DRAFT->value     => self::DRAFT->label(),
            self::SENT->value      => self::SENT->label(),
            self::PAID->value      => self::PAID->label(),
            self::OVERDUE->value   => self::OVERDUE->label(),
            self::CANCELLED->value => self::CANCELLED->label(),
        ];
    }
}
