<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Enums;

use Carbon\Carbon;

/**
 * Billing Frequency Enum
 *
 * Represents all possible billing frequencies for articles and services.
 * Integer values are ordered by period length for logical sorting.
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
enum BillingFrequency: int
{
    case ONE_TIME   = 0;  // Pago único (no recurrente)
    case WEEKLY     = 1;  // Semanal
    case BIWEEKLY   = 2;  // Quincenal
    case MONTHLY    = 3;  // Mensual
    case BIMONTHLY  = 4;  // Bimensual (cada 2 meses)
    case QUARTERLY  = 5;  // Trimestral
    case SEMIANNUAL = 6;  // Semestral
    case YEARLY     = 7;  // Anual
    case BIENNIAL   = 8;  // Bienal (cada 2 años)
    case TRIENNIAL  = 9;  // Trienal (cada 3 años)

    /**
     * Get human-readable label for the frequency
     */
    public function label(): string
    {
        return match ($this) {
            self::ONE_TIME   => 'One-time',
            self::WEEKLY     => 'Weekly',
            self::BIWEEKLY   => 'Biweekly',
            self::MONTHLY    => 'Monthly',
            self::BIMONTHLY  => 'Bimonthly',
            self::QUARTERLY  => 'Quarterly',
            self::SEMIANNUAL => 'Semiannual',
            self::YEARLY     => 'Yearly',
            self::BIENNIAL   => 'Biennial',
            self::TRIENNIAL  => 'Triennial',
        };
    }

    /**
     * Get number of months for this frequency
     * Returns null for ONE_TIME (not recurring) and sub-monthly frequencies
     */
    public function months(): ?int
    {
        return match ($this) {
            self::ONE_TIME   => null,
            self::WEEKLY     => null,  // Use days() instead
            self::BIWEEKLY   => null,  // Use days() instead
            self::MONTHLY    => 1,
            self::BIMONTHLY  => 2,
            self::QUARTERLY  => 3,
            self::SEMIANNUAL => 6,
            self::YEARLY     => 12,
            self::BIENNIAL   => 24,
            self::TRIENNIAL  => 36,
        };
    }

    /**
     * Get number of days for sub-monthly frequencies
     * Returns null for monthly and longer frequencies
     */
    public function days(): ?int
    {
        return match ($this) {
            self::WEEKLY   => 7,
            self::BIWEEKLY => 14,
            default        => null,
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
            self::ONE_TIME   => $date->copy(), // No change for one-time
            self::WEEKLY     => $date->copy()->addWeeks($interval),
            self::BIWEEKLY   => $date->copy()->addWeeks($interval * 2),
            self::MONTHLY    => $date->copy()->addMonths($interval),
            self::BIMONTHLY  => $date->copy()->addMonths($interval * 2),
            self::QUARTERLY  => $date->copy()->addMonths($interval * 3),
            self::SEMIANNUAL => $date->copy()->addMonths($interval * 6),
            self::YEARLY     => $date->copy()->addYears($interval),
            self::BIENNIAL   => $date->copy()->addYears($interval * 2),
            self::TRIENNIAL  => $date->copy()->addYears($interval * 3),
        };
    }

    /**
     * Check if this frequency is recurring
     */
    public function isRecurring(): bool
    {
        return $this !== self::ONE_TIME;
    }

    /**
     * Get all recurring frequencies
     *
     * @return array<self>
     */
    public static function recurring(): array
    {
        return array_filter(
            self::cases(),
            fn (self $frequency) => $frequency->isRecurring()
        );
    }

    /**
     * Get only month-based recurring frequencies (excludes weekly/biweekly)
     *
     * @return array<self>
     */
    public static function monthlyOrLonger(): array
    {
        return [
            self::MONTHLY,
            self::BIMONTHLY,
            self::QUARTERLY,
            self::SEMIANNUAL,
            self::YEARLY,
            self::BIENNIAL,
            self::TRIENNIAL,
        ];
    }

    /**
     * Subtract interval from date respecting the frequency
     * Uses subMonths/subYears for precise calendar calculations
     *
     * @param  Carbon  $date  The starting date
     * @param  int  $interval  Number of periods to subtract (default: 1)
     */
    public function subtractFromDate(Carbon $date, int $interval = 1): Carbon
    {
        return match ($this) {
            self::ONE_TIME   => $date->copy(), // No change for one-time
            self::WEEKLY     => $date->copy()->subWeeks($interval),
            self::BIWEEKLY   => $date->copy()->subWeeks($interval * 2),
            self::MONTHLY    => $date->copy()->subMonthsNoOverflow($interval),
            self::BIMONTHLY  => $date->copy()->subMonthsNoOverflow($interval * 2),
            self::QUARTERLY  => $date->copy()->subMonthsNoOverflow($interval * 3),
            self::SEMIANNUAL => $date->copy()->subMonthsNoOverflow($interval * 6),
            self::YEARLY     => $date->copy()->subYearsNoOverflow($interval),
            self::BIENNIAL   => $date->copy()->subYearsNoOverflow($interval * 2),
            self::TRIENNIAL  => $date->copy()->subYearsNoOverflow($interval * 3),
        };
    }

    /**
     * Get frequency from months count
     */
    public static function fromMonths(int $months): ?self
    {
        return match ($months) {
            1       => self::MONTHLY,
            2       => self::BIMONTHLY,
            3       => self::QUARTERLY,
            6       => self::SEMIANNUAL,
            12      => self::YEARLY,
            24      => self::BIENNIAL,
            36      => self::TRIENNIAL,
            default => null,
        };
    }

    /**
     * Get frequency from days count (for sub-monthly frequencies)
     */
    public static function fromDays(int $days): ?self
    {
        return match ($days) {
            7       => self::WEEKLY,
            14      => self::BIWEEKLY,
            default => null,
        };
    }

    /**
     * Get approximate total days for this frequency
     * Useful for comparisons and sorting
     */
    public function approximateDays(): ?int
    {
        return match ($this) {
            self::ONE_TIME   => null,
            self::WEEKLY     => 7,
            self::BIWEEKLY   => 14,
            self::MONTHLY    => 30,
            self::BIMONTHLY  => 60,
            self::QUARTERLY  => 90,
            self::SEMIANNUAL => 180,
            self::YEARLY     => 365,
            self::BIENNIAL   => 730,
            self::TRIENNIAL  => 1095,
        };
    }
}
