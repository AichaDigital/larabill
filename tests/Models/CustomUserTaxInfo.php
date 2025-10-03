<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CustomUserTaxInfo Model for testing model mapping
 */
class CustomUserTaxInfo extends Model
{
    protected $fillable = [
        'customer_id',
        'fiscal_id',
        'business_name',
        'street_address',
        'municipality',
        'zip_code',
        'country_code',
        'region',
        'contact_phone',
        'is_current',
    ];

    protected $casts = [
        'is_current' => 'boolean',
    ];
}
