<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Setting Scope Enum
 *
 * Represents the scope at which a setting applies.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum SettingScope: int
{
    case GLOBAL_SCOPE = 0;  // Applies to all invoices
    case CLIENT       = 1;  // Applies to specific client
    case INDIVIDUAL   = 2;  // Applies to individual invoice

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::GLOBAL_SCOPE => 'Global',
            self::CLIENT       => 'Client',
            self::INDIVIDUAL   => 'Individual',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::GLOBAL_SCOPE => 'Setting applies to all invoices.',
            self::CLIENT       => 'Setting applies to a specific client.',
            self::INDIVIDUAL   => 'Setting applies to individual invoices.',
        };
    }
}
