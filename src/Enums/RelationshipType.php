<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

/**
 * Relationship Type Enum
 *
 * Represents the type of relationship between a Customer and a User.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum RelationshipType: int
{
    case SELF         = 0;  // User mismo (persona física)
    case SELF_COMPANY = 1;  // Empresa del User (autónomo/empresa propia)
    case CLIENT       = 2;  // Cliente del User (tercero)
    case OTHER        = 3;  // Otro tipo de relación

    /**
     * Get human-readable label for the relationship type.
     */
    public function label(): string
    {
        return match ($this) {
            self::SELF         => 'Self (Individual)',
            self::SELF_COMPANY => 'Self Company',
            self::CLIENT       => 'Client',
            self::OTHER        => 'Other',
        };
    }

    /**
     * Get description for the relationship type.
     */
    public function description(): string
    {
        return match ($this) {
            self::SELF         => 'The user themselves as a natural person.',
            self::SELF_COMPANY => 'The user\'s own company or business.',
            self::CLIENT       => 'A client of the user.',
            self::OTHER        => 'Other type of relationship.',
        };
    }

    /**
     * Check if this is a self-type relationship.
     */
    public function isSelf(): bool
    {
        return in_array($this, [self::SELF, self::SELF_COMPANY], true);
    }

    /**
     * Get all self-type relationships.
     *
     * @return array<self>
     */
    public static function selfTypes(): array
    {
        return [self::SELF, self::SELF_COMPANY];
    }
}
