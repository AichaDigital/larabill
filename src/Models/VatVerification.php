<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * VatVerification Model
 *
 * Represents VAT number verification results from external APIs.
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
        'address',
        'verification_date',
        'api_used',
        'response_data',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_valid' => 'boolean',
        'verification_date' => 'datetime',
        'response_data' => 'array',
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
     * Scope to get only valid VAT verifications.
     */
    public function scopeValid($query)
    {
        return $query->where('is_valid', true);
    }
}
