<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

enum CancellationType: int
{
    case IMMEDIATE     = 0;
    case END_OF_PERIOD = 1;
    case NOTICE_PERIOD = 2;

    /**
     * Get human-readable label for the cancellation type
     */
    public function label(): string
    {
        return match ($this) {
            self::IMMEDIATE     => 'Immediate cancellation',
            self::END_OF_PERIOD => 'End of billing period',
            self::NOTICE_PERIOD => 'Notice period with refund',
        };
    }

    /**
     * Get description for the cancellation type
     */
    public function description(): string
    {
        return match ($this) {
            self::IMMEDIATE     => 'Service is cancelled immediately without refund for unused time.',
            self::END_OF_PERIOD => 'Service remains active until the end of current billing period.',
            self::NOTICE_PERIOD => 'Service is cancelled after notice period with refund for unused time.',
        };
    }

    /**
     * Check if this cancellation type requires calculating refund
     */
    public function requiresRefund(): bool
    {
        return $this === self::NOTICE_PERIOD;
    }

    /**
     * Check if service should be deprovisioned immediately
     */
    public function shouldDeprovisionImmediately(): bool
    {
        return $this === self::IMMEDIATE;
    }

    /**
     * Check if service should continue until end of period
     */
    public function continuesUntilEndOfPeriod(): bool
    {
        return $this === self::END_OF_PERIOD;
    }

    /**
     * Get icon representation for UI purposes
     */
    public function icon(): string
    {
        return match ($this) {
            self::IMMEDIATE     => 'x-circle',
            self::END_OF_PERIOD => 'calendar-x',
            self::NOTICE_PERIOD => 'alert-circle',
        };
    }

    /**
     * Get color representation for UI purposes
     */
    public function color(): string
    {
        return match ($this) {
            self::IMMEDIATE     => 'red',
            self::END_OF_PERIOD => 'orange',
            self::NOTICE_PERIOD => 'yellow',
        };
    }
}
