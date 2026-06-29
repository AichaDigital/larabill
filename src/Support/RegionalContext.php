<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Support;

use Carbon\Carbon;

/**
 * RegionalContext Helper
 *
 * Provides helper methods to query regional and compliance settings
 * based on config/larabill.php configuration.
 *
 * v0.3.3: Added for global tax system support and fiscal compliance
 */
class RegionalContext
{
    /**
     * Check if fiscal zone is CEE (European Economic Community)
     */
    public static function isCEE(): bool
    {
        return config('larabill.region.fiscal_zone') === 'eu';
    }

    /**
     * Check if fiscal zone is USA
     */
    public static function isUSA(): bool
    {
        return config('larabill.region.country') === 'US';
    }

    /**
     * Check if fiscal zone is Australia
     */
    public static function isAustralia(): bool
    {
        return config('larabill.region.country') === 'AU';
    }

    /**
     * Check if fiscal zone is Canada
     */
    public static function isCanada(): bool
    {
        return config('larabill.region.country') === 'CA';
    }

    /**
     * Get tax system type (vat, sales_tax, gst, hst)
     */
    public static function getTaxSystemType(): string
    {
        return config('larabill.region.tax_system', 'vat');
    }

    /**
     * Get country code (ISO 3166-1 alpha-2)
     */
    public static function getCountryCode(): string
    {
        return config('larabill.region.country', 'ES');
    }

    /**
     * Get region code (state/province)
     */
    public static function getRegionCode(): ?string
    {
        return config('larabill.region.region');
    }

    /**
     * Get fiscal zone (eu, us, au, ca, other)
     */
    public static function getFiscalZone(): string
    {
        return config('larabill.region.fiscal_zone', 'other');
    }

    /**
     * Check if correlative numbering is required (fiscal compliance)
     */
    public static function requiresCorrelativeNumbering(): bool
    {
        return config('larabill.compliance.requires_correlative_numbering', true);
    }

    /**
     * Check if service dates are required
     */
    public static function requiresServiceDates(): bool
    {
        return config('larabill.compliance.requires_service_dates', false);
    }

    /**
     * Check if tax verification (VAT/Tax ID) is required
     */
    public static function requiresTaxVerification(): bool
    {
        return config('larabill.compliance.requires_tax_verification', false);
    }

    /**
     * Check if fiscal QR code is required (e.g., Spain TBAI)
     */
    public static function requiresFiscalQR(): bool
    {
        return config('larabill.compliance.requires_fiscal_qr', false);
    }

    /**
     * Get fiscal year start month (1-12)
     */
    public static function getFiscalYearStartMonth(): int
    {
        return config('larabill.fiscal_year.start_month', 1);
    }

    /**
     * Get fiscal year start day (1-31)
     */
    public static function getFiscalYearStartDay(): int
    {
        return config('larabill.fiscal_year.start_day', 1);
    }

    /**
     * Build a start-of-day Carbon for the given Y/M/D, asserting validity.
     */
    private static function startOfDayFor(int $year, int $month, int $day): Carbon
    {
        $date = Carbon::create($year, $month, $day);

        if (! $date instanceof Carbon) {
            throw new \InvalidArgumentException("Invalid date components: {$year}-{$month}-{$day}.");
        }

        return $date->startOfDay();
    }

    /**
     * Check if given date is within fiscal year
     */
    public static function isWithinFiscalYear(Carbon $date, int $fiscalYear): bool
    {
        $startMonth = self::getFiscalYearStartMonth();
        $startDay   = self::getFiscalYearStartDay();

        $fiscalStart = self::startOfDayFor($fiscalYear, $startMonth, $startDay);
        $fiscalEnd   = $fiscalStart->copy()->addYear()->subDay()->endOfDay();

        return $date->between($fiscalStart, $fiscalEnd);
    }

    /**
     * Get fiscal year for a given date
     */
    public static function getFiscalYear(Carbon $date): int
    {
        $startMonth = self::getFiscalYearStartMonth();
        $startDay   = self::getFiscalYearStartDay();

        // If fiscal year starts on Jan 1, it's simple
        if ($startMonth === 1 && $startDay === 1) {
            return $date->year;
        }

        // Otherwise, check if date is before or after fiscal year start
        $fiscalStart = self::startOfDayFor($date->year, $startMonth, $startDay);

        return $date->greaterThanOrEqualTo($fiscalStart) ? $date->year : $date->year - 1;
    }

    /**
     * Get fiscal year start date for a given fiscal year
     */
    public static function getFiscalYearStart(int $fiscalYear): Carbon
    {
        return self::startOfDayFor(
            $fiscalYear,
            self::getFiscalYearStartMonth(),
            self::getFiscalYearStartDay()
        );
    }

    /**
     * Get fiscal year end date for a given fiscal year
     */
    public static function getFiscalYearEnd(int $fiscalYear): Carbon
    {
        return self::getFiscalYearStart($fiscalYear)
            ->copy()
            ->addYear()
            ->subDay()
            ->endOfDay();
    }
}
