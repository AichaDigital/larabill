<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * InvoiceSerieType Enum
 *
 * Represents the legal type of invoice series.
 * This is the FISCAL QUALITY of the invoice, not the display format.
 *
 * @method string label() Get human-readable label
 * @method bool requiresCorrelation() Check if requires correlative numbering
 * @method string defaultPrefix() Get default prefix for this type
 * @method static array toArray() Get all cases as [value => label] array
 */
enum InvoiceSerieType: int
{
    case PROFORMA = 0;
    case INVOICE = 1;
    case RECTIFICATIVE = 2;

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::PROFORMA => 'proforma',
            self::INVOICE => 'invoice',
            self::RECTIFICATIVE => 'rectificative',
        };
    }

    /**
     * Check if this type requires correlative numbering (EU fiscal requirement)
     * Proformas do NOT require legal correlation
     */
    public function requiresCorrelation(): bool
    {
        return $this !== self::PROFORMA;
    }

    /**
     * Get default prefix for this invoice type
     * User can customize, this is just the default
     */
    public function defaultPrefix(): string
    {
        return match ($this) {
            self::PROFORMA => 'PRO',
            self::INVOICE => 'FAC',
            self::RECTIFICATIVE => 'RECT',
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
            self::PROFORMA->value => self::PROFORMA->label(),
            self::INVOICE->value => self::INVOICE->label(),
            self::RECTIFICATIVE->value => self::RECTIFICATIVE->label(),
        ];
    }
}

