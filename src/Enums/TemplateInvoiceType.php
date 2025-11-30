<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Template Invoice Type Enum
 *
 * Represents the type of invoice for template/settings purposes.
 * Different from InvoiceSerieType which is fiscal quality.
 */
enum TemplateInvoiceType: int
{
    case FISCAL         = 0;  // Standard fiscal invoice
    case PROFORMA       = 1;  // Proforma invoice
    case REVERSE_CHARGE = 2;  // Reverse charge (intra-EU B2B)
    case EXEMPT         = 3;  // Tax exempt invoice

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::FISCAL         => 'Fiscal',
            self::PROFORMA       => 'Proforma',
            self::REVERSE_CHARGE => 'Reverse Charge',
            self::EXEMPT         => 'Exempt',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::FISCAL         => 'Standard fiscal invoice with taxes.',
            self::PROFORMA       => 'Proforma invoice without fiscal value.',
            self::REVERSE_CHARGE => 'Invoice with reverse charge mechanism (EU B2B).',
            self::EXEMPT         => 'Invoice exempt from taxes.',
        };
    }
}
