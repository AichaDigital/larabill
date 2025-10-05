<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * TaxRate Model
 *
 * Represents tax rates for different countries and regions.
 * Tax rates are stored as base-100 integers (e.g., 21.50% => 2150).
 *
 * @property string $country_code
 * @property string $country_name
 * @property string $tax_name
 * @property string $tax_type
 * @property int $rate Base-100 integer (e.g., 2150 => 21.50%)
 * @property bool $is_active
 * @property string|null $applies_to
 * @property array|null $special_conditions
 */
class TaxRate extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'country_code',
        'country_name',
        'tax_name',
        'tax_type',
        'rate',
        'is_active',
        'applies_to',
        'special_conditions',
    ];

    /**
     * Casts for attributes.
     *
     * Uses integers in base 100 for tax rates (percentages)
     * Example: 21.50% is stored as 2150, 12.34% as 1234
     */
    public function casts(): array
    {
        return [
            'is_active'          => 'boolean',
            'rate'               => 'integer', // Base 100: 21.50% = 2150
            'special_conditions' => 'array',
        ];
    }

    /**
     * Convert percentage to base 100 integer.
     */
    public static function percentageToBase100(float $percentage): int
    {
        return (int) ($percentage * 100);
    }

    /**
     * Convert base 100 integer to percentage.
     */
    public static function base100ToPercentage(int $base100): float
    {
        return $base100 / 100.0;
    }

    /**
     * Get rate as percentage.
     */
    public function getRateAsPercentage(): float
    {
        return static::base100ToPercentage($this->getRawOriginal('rate'));
    }

    /**
     * Set rate from percentage.
     */
    public function setRateFromPercentage(float $percentage): self
    {
        $this->update(['rate' => static::percentageToBase100($percentage)]);

        return $this;
    }

    /**
     * Accessor for rate to return as formatted string.
     */
    public function getRateAttribute($value): string
    {
        if ($value === null) {
            return '0.0000';
        }

        // If value is already a decimal (like 0.21), format it directly
        if ($value < 1) {
            return number_format((float) $value, 4, '.', '');
        }

        // Convert from base-100 integer to decimal format
        $decimal = ((int) $value) / 10000.0;

        return number_format($decimal, 4, '.', '');
    }

    /**
     * Get Spanish tax rates.
     */
    public static function getSpanishRates()
    {
        return static::where('country_code', 'ES')
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get EU tax rates.
     */
    public static function getEURates()
    {
        $euCountries = [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU',
            'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
        ];

        return static::whereIn('country_code', $euCountries)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Scope to get only active tax rates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
