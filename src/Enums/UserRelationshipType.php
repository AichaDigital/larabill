<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

use Filament\Support\Contracts\{HasColor, HasIcon, HasLabel};

/**
 * UserRelationshipType Enum
 *
 * Represents the type of relationship between a User and the Company (issuer).
 *
 * - DIRECT: Client of the Company (software issuer)
 * - DELEGATED: Client of a User (delegated billing)
 *
 * @see ADR-003 for architectural decisions
 */
enum UserRelationshipType: int implements HasColor, HasIcon, HasLabel
{
    case DIRECT    = 0;  // Client of the Company (parent_user_id = null)
    case DELEGATED = 1;  // Client of a User (parent_user_id = User.id)

    /**
     * Get human-readable label for the relationship type.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::DIRECT    => __('larabill::enums.user_relationship.direct'),
            self::DELEGATED => __('larabill::enums.user_relationship.delegated'),
        };
    }

    /**
     * Get Filament color for the relationship type.
     */
    public function getColor(): string
    {
        return match ($this) {
            self::DIRECT    => 'success',
            self::DELEGATED => 'info',
        };
    }

    /**
     * Get Filament icon for the relationship type.
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::DIRECT    => 'heroicon-o-user',
            self::DELEGATED => 'heroicon-o-user-group',
        };
    }

    /**
     * Get description for the relationship type.
     */
    public function description(): string
    {
        return match ($this) {
            self::DIRECT    => 'Direct client of the company (receives invoices from issuer)',
            self::DELEGATED => 'Delegated client (receives invoices on behalf of parent user)',
        };
    }

    /**
     * Check if the user is a direct client.
     */
    public function isDirect(): bool
    {
        return $this === self::DIRECT;
    }

    /**
     * Check if the user is a delegated client.
     */
    public function isDelegated(): bool
    {
        return $this === self::DELEGATED;
    }

    /**
     * Get all cases as array [value => label].
     *
     * @return array<int, string>
     */
    public static function toArray(): array
    {
        return [
            self::DIRECT->value    => self::DIRECT->getLabel(),
            self::DELEGATED->value => self::DELEGATED->getLabel(),
        ];
    }
}
