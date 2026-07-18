<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Template Invoice Type Enum
 *
 * Represents the type of invoice for template/settings purposes.
 * Different from InvoiceSerieType which is fiscal quality.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
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
     * The string the template registry persists in `invoice_templates.type`.
     *
     * Single source of the registry vocabulary (ADR-011, AID-502): every
     * lookup against InvoiceTemplate rows goes through this key — never
     * through the fiscal serie's label ('invoice', 'simplified', ...), whose
     * mismatch is why the registry never resolved for fiscal invoices.
     */
    public function registryKey(): string
    {
        return match ($this) {
            self::FISCAL         => 'fiscal',
            self::PROFORMA       => 'proforma',
            self::REVERSE_CHARGE => 'reverse-charge',
            self::EXEMPT         => 'exempt',
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
