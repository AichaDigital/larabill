<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CustomVatVerification Model for testing model mapping
 */
class CustomVatVerification extends Model
{
    protected $fillable = [
        'vat_code',
        'country_code',
        'is_valid',
        'company_name',
        'address',
        'verification_date',
        'api_used',
        'response_data',
    ];
}
