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
        'total_tax_amount', // v0.3.3: Renamed from tax_amount
        'taxes_applied',    // v0.3.3: JSON snapshot
        'total',
    ];
}
