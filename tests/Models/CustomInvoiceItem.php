<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Tests\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CustomInvoiceItem Model for testing model mapping
 */
class CustomInvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price',
        'taxable_amount',
        'tax_rate',
        'tax_amount',
        'total',
    ];
}
