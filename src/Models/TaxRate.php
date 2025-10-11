<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
use Illuminate\Database\Eloquent\{Builder, Factories\HasFactory, Model};

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
 * @property array<string, mixed>|null $special_conditions
 */
class TaxRate extends Model
{
    /** @phpstan-ignore-next-line */
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
     * Uses Base100 cast from lara100 package for tax rate percentages
     * Automatically handles conversion between decimals and base-100 integers
     * Example: 21.50% ↔ 2150 (stored as integer, accessed as decimal)
     */
    public function casts(): array
    {
        return [
            'is_active'          => 'boolean',
            'rate'               => Base100::class, // 21.50% ↔ 2150
            'special_conditions' => 'array',
        ];
    }

    /**
     * Get Spanish tax rates.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TaxRate>
     */
    public static function getSpanishRates(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('country_code', 'ES')
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get EU tax rates.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TaxRate>
     */
    public static function getEURates(): \Illuminate\Database\Eloquent\Collection
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
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
