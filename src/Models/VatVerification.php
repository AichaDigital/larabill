<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\{Builder, Model};

/**
 * VatVerification Model
 *
 * Represents VAT number verification results from external APIs.
 *
 * @property int $id
 * @property string $vat_number
 * @property string $country_code
 * @property bool $is_valid
 * @property string|null $company_name
 * @property string|null $company_address
 * @property string|null $api_source
 * @property array<string, mixed>|null $response_data
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class VatVerification extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'vat_number',
        'country_code',
        'is_valid',
        'company_name',
        'company_address',
        'api_source',
        'response_data',
        'checked_at',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_valid'      => 'boolean',
        'response_data' => 'array',
        'checked_at'    => 'datetime',
        'expires_at'    => 'datetime',
    ];

    /**
     * Find verification by VAT number and country code.
     */
    public static function findByVatNumber(string $vatNumber, string $countryCode): ?self
    {
        return static::where('vat_number', $vatNumber)
            ->where('country_code', $countryCode)
            ->first();
    }

    /**
     * Find verification by VAT number and country code (alias).
     */
    public static function findByVatNumberAndCountry(string $vatNumber, string $countryCode): ?self
    {
        return static::findByVatNumber($vatNumber, $countryCode);
    }

    /**
     * Scope to get only valid VAT verifications.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('is_valid', true);
    }
}
