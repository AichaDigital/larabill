<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CustomInvoice Model for testing model mapping
 */
class CustomInvoice extends Model
{
    protected $fillable = [
        'number',
        'type',
        'status',
        'user_id',
        'taxable_amount',
        'tax_amount',
        'total',
    ];
}
