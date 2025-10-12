<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CustomUserTaxProfile Model for testing model mapping
 */
class CustomUserTaxProfile extends Model
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
