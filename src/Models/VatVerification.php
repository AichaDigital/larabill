<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};

/**
 * VatVerification Model
 *
 * Represents VAT code verification results from external APIs.
 * Stores verification data for international tax codes (VAT/IVA/Sales Tax/etc).
 *
 * @property int $id
 * @property string $vat_code
 * @property string $country_code
 * @property bool $is_valid
 * @property string|null $company_name
 * @property string|null $company_address
 * @property string|null $api_source
 * @property array<string, mixed>|null $response_data
 * @property \Illuminate\Support\Carbon|null $checked_at
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class VatVerification extends Model
{
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'vat_code',
        'country_code',
        'is_valid',
        'company_name',
        'company_address',
        'api_source',
        'response_data',
        'checked_at',
        'verified_at',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_valid'      => 'boolean',
        'response_data' => 'array',
        'checked_at'    => 'datetime',
        'verified_at'   => 'datetime',
        'expires_at'    => 'datetime',
    ];

    /**
     * Find verification by VAT code and country code.
     */
    public static function findByVatCode(string $vatCode, string $countryCode): ?self
    {
        return static::where('vat_code', $vatCode)
            ->where('country_code', $countryCode)
            ->first();
    }

    /**
     * Find verification by VAT code and country code (alias).
     */
    public static function findByVatCodeAndCountry(string $vatCode, string $countryCode): ?self
    {
        return static::findByVatCode($vatCode, $countryCode);
    }

    /**
     * Get user tax profiles that use this VAT code.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<UserTaxProfile, $this>
     */
    public function userTaxProfiles(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(UserTaxProfile::class, 'tax_id', 'vat_code');
    }

    /**
     * Check if VAT verification is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
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
