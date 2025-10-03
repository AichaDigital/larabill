<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TaxRate Model
 *
 * Represents tax rates for different countries and regions.
 */
class TaxRate extends Model
{
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
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'rate' => 'decimal:4',
        'special_conditions' => 'array',
    ];

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
