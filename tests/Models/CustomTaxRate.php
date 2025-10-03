<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CustomTaxRate Model for testing model mapping
 */
class CustomTaxRate extends Model
{
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
}
