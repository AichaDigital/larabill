<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

use Carbon\Carbon;

enum BillingFrequency: string
{
    case MONTHLY   = 'M';
    case QUARTERLY = 'Q';
    case YEARLY    = 'Y';
    case LIFETIME  = 'L';

    /**
     * Get human-readable label for the frequency
     */
    public function label(): string
    {
        return match ($this) {
            self::MONTHLY   => 'Monthly',
            self::QUARTERLY => 'Quarterly',
            self::YEARLY    => 'Yearly',
            self::LIFETIME  => 'Lifetime',
        };
    }

    /**
     * Get number of months for this frequency
     * Returns null for LIFETIME as it's not recurring
     */
    public function months(): ?int
    {
        return match ($this) {
            self::MONTHLY   => 1,
            self::QUARTERLY => 3,
            self::YEARLY    => 12,
            self::LIFETIME  => null,
        };
    }

    /**
     * Add interval to date respecting the frequency
     * Uses addMonths/addYears for precise calendar calculations
     *
     * @param  Carbon  $date  The starting date
     * @param  int  $interval  Number of periods to add (default: 1)
     */
    public function addToDate(Carbon $date, int $interval = 1): Carbon
    {
        return match ($this) {
            self::MONTHLY   => $date->copy()->addMonths($interval),
            self::QUARTERLY => $date->copy()->addMonths($interval * 3),
            self::YEARLY    => $date->copy()->addYears($interval),
            self::LIFETIME  => $date->copy(), // No change for lifetime
        };
    }

    /**
     * Check if this frequency is recurring
     */
    public function isRecurring(): bool
    {
        return $this !== self::LIFETIME;
    }

    /**
     * Get all recurring frequencies
     *
     * @return array<self>
     */
    public static function recurring(): array
    {
        return [
            self::MONTHLY,
            self::QUARTERLY,
            self::YEARLY,
        ];
    }

    /**
     * Get frequency from months count
     */
    public static function fromMonths(int $months): ?self
    {
        return match ($months) {
            1       => self::MONTHLY,
            3       => self::QUARTERLY,
            12      => self::YEARLY,
            default => null,
        };
    }
}
