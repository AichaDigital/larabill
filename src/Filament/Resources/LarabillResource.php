<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Filament\Resources;

use Filament\Resources\Resource;

/**
 * Base Resource for Larabill Filament Resources
 *
 * This abstract class provides common functionality for all Larabill resources.
 * It centralizes navigation configuration and formatting helpers.
 *
 * @version 1.0
 *
 * @note In v2.0, this namespace will be extracted to `aichadigital/larabill-filament` plugin.
 */
abstract class LarabillResource extends Resource
{
    /**
     * Get navigation group from config
     */
    public static function getNavigationGroup(): ?string
    {
        return config('larabill.filament.navigation.group', 'Billing');
    }

    /**
     * Get navigation sort order from config
     */
    public static function getNavigationSort(): ?int
    {
        return config('larabill.filament.navigation.sort', 10);
    }

    /**
     * Format base100 integer to money string
     *
     * @param  int  $amount  Amount in base100 format (e.g., 1234 = €12.34)
     * @param  string  $currency  Currency code (default: EUR)
     * @return string Formatted money string (e.g., "12,34 EUR")
     */
    protected static function formatMoney(int $amount, string $currency = 'EUR'): string
    {
        return number_format($amount / 100, 2, ',', '.').' '.$currency;
    }

    /**
     * Format base100 percentage to string
     *
     * @param  int  $rate  Rate in base100 format (e.g., 2100 = 21%)
     * @return string Formatted percentage (e.g., "21%")
     */
    protected static function formatPercentage(int $rate): string
    {
        return number_format($rate / 100, 2, ',', '.').'%';
    }

    /**
     * Format base100 quantity to decimal
     *
     * @param  int  $quantity  Quantity in base100 format (e.g., 250 = 2.5)
     * @return string Formatted quantity (e.g., "2,5")
     */
    protected static function formatQuantity(int $quantity): string
    {
        return number_format($quantity / 100, 2, ',', '.');
    }

    /**
     * Check if Filament integration is enabled
     */
    public static function isFilamentEnabled(): bool
    {
        return (bool) config('larabill.filament.enabled', true);
    }

    /**
     * Check if specific resource is enabled in config
     *
     * @param  string  $resourceKey  Key from config (invoice, customer, article)
     */
    protected static function isResourceEnabled(string $resourceKey): bool
    {
        return (bool) config("larabill.filament.resources.{$resourceKey}.enabled", true);
    }
}
