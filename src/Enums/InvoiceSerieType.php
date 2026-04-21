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
 * @method static array<int, string> toArray() Get all cases as [value => label] array
 */
enum InvoiceSerieType: int
{
    case PROFORMA      = 0;  // No fiscal value, not communicated to tax authority
    case INVOICE       = 1;  // Standard/complete fiscal invoice
    case SIMPLIFIED    = 2;  // Simplified invoice (ticket) - limited data required
    case RECTIFICATIVE = 3;  // Rectificative/credit note invoice

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::PROFORMA      => 'proforma',
            self::INVOICE       => 'invoice',
            self::SIMPLIFIED    => 'simplified',
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
            self::PROFORMA      => 'PRO',
            self::INVOICE       => 'FAC',
            self::SIMPLIFIED    => 'TIK',
            self::RECTIFICATIVE => 'RECT',
        };
    }

    /**
     * Check if this is a fiscal invoice (has legal value)
     */
    public function isFiscal(): bool
    {
        return in_array($this, [self::INVOICE, self::SIMPLIFIED, self::RECTIFICATIVE], true);
    }

    /**
     * Check if this type requires full customer data
     * Simplified invoices have relaxed requirements
     */
    public function requiresFullCustomerData(): bool
    {
        return match ($this) {
            self::PROFORMA, self::SIMPLIFIED   => false,
            self::INVOICE, self::RECTIFICATIVE => true,
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
            self::PROFORMA->value      => self::PROFORMA->label(),
            self::INVOICE->value       => self::INVOICE->label(),
            self::SIMPLIFIED->value    => self::SIMPLIFIED->label(),
            self::RECTIFICATIVE->value => self::RECTIFICATIVE->label(),
        ];
    }

    /**
     * Get all fiscal types (excluding proforma)
     *
     * @return array<self>
     */
    public static function fiscalTypes(): array
    {
        return [self::INVOICE, self::SIMPLIFIED, self::RECTIFICATIVE];
    }
}
