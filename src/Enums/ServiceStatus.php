<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

enum ServiceStatus: int
{
    case ACTIVE    = 0;
    case PENDING   = 1;
    case SUSPENDED = 2;
    case CANCELLED = 3;
    case EXPIRED   = 4;

    /**
     * Get human-readable label for the status
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE    => 'Active',
            self::PENDING   => 'Pending',
            self::SUSPENDED => 'Suspended',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED   => 'Expired',
        };
    }

    /**
     * Check if service can be billed in this status
     */
    public function canBill(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if service is in active state (can be used)
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if service is in a final state (cannot be reactivated)
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::CANCELLED, self::EXPIRED], true);
    }

    /**
     * Get color representation for UI purposes
     * Returns Tailwind color classes
     */
    public function color(): string
    {
        return match ($this) {
            self::ACTIVE    => 'green',
            self::PENDING   => 'yellow',
            self::SUSPENDED => 'orange',
            self::CANCELLED => 'red',
            self::EXPIRED   => 'gray',
        };
    }

    /**
     * Get icon representation for UI purposes
     */
    public function icon(): string
    {
        return match ($this) {
            self::ACTIVE    => 'check-circle',
            self::PENDING   => 'clock',
            self::SUSPENDED => 'pause-circle',
            self::CANCELLED => 'x-circle',
            self::EXPIRED   => 'archive',
        };
    }

    /**
     * Get all statuses that can be billed
     *
     * @return array<self>
     */
    public static function billable(): array
    {
        return [
            self::ACTIVE,
        ];
    }

    /**
     * Get all statuses that are considered final
     *
     * @return array<self>
     */
    public static function final(): array
    {
        return [
            self::CANCELLED,
            self::EXPIRED,
        ];
    }
}
