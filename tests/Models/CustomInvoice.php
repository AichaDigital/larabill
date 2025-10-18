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
        'total_tax_amount', // v0.3.3: Renamed from tax_amount
        'total',
    ];
}
