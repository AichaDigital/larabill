<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
use Dyrynda\Database\Support\Casts\EfficientUuid;
use Dyrynda\Database\Support\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceItem Model
 *
 * Represents an item within an invoice.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 * Tax rates are stored as base-100 integers (e.g., 21.50% => 2150).
 *
 * @property string $invoice_id UUID foreign key to invoices table
 * @property string $description
 * @property int $quantity Base-100 integer for fractional quantities (e.g., 1.5 => 150)
 * @property int $unit_price Base-100 integer (e.g., 1234 => €12.34)
 * @property int $subtotal Base-100 integer (e.g., 1234 => €12.34)
 * @property int $tax_rate Base-100 integer (e.g., 2150 => 21.50%)
 * @property int $tax_amount Base-100 integer (e.g., 1234 => €12.34)
 * @property int $total Base-100 integer (e.g., 1234 => €12.34)
 */
class InvoiceItem extends Model
{
    use GeneratesUuid;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = true;

    /**
     * The UUID columns for this model.
     * Including foreign key to enable proper UUID binary conversions in queries.
     */
    public function uuidColumns(): array
    {
        return ['invoice_id'];
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
    ];

    /**
     * Casts for attributes.
     *
     * Uses Base100 cast from lara100 package for monetary values, quantities and percentages
     * Automatically handles conversion between decimals and base-100 integers
     * Example: €12.34 ↔ 1234, 1.5 ↔ 150, 21.50% ↔ 2150
     */
    public function casts(): array
    {
        return [
            'invoice_id' => EfficientUuid::class, // UUID binary(16)
            'quantity'   => Base100::class, // 1.5 ↔ 150
            'unit_price' => Base100::class, // €12.34 ↔ 1234
            'subtotal'   => Base100::class, // €12.34 ↔ 1234
            'tax_rate'   => Base100::class, // 21.50% ↔ 2150
            'tax_amount' => Base100::class, // €12.34 ↔ 1234
            'total'      => Base100::class, // €12.34 ↔ 1234
        ];
    }

    /**
     * Calculate subtotal from quantity and unit price.
     * Base100 cast handles conversion automatically.
     */
    public function calculateSubtotal(): float
    {
        return $this->quantity * $this->unit_price;
    }

    /**
     * Calculate tax amount from subtotal and tax rate.
     * Base100 cast handles conversion automatically.
     */
    public function calculateTaxAmount(): float
    {
        return round($this->subtotal * ($this->tax_rate / 100), 2);
    }

    /**
     * Calculate total from subtotal and tax amount.
     * Base100 cast handles conversion automatically.
     */
    public function calculateTotal(): float
    {
        return $this->subtotal + $this->tax_amount;
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * Uses custom BinaryUuidBuilder to handle UUID binary conversions in relationships.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \AichaDigital\Larabill\Database\Query\BinaryUuidBuilder
     */
    public function newEloquentBuilder($query)
    {
        return new \AichaDigital\Larabill\Database\Query\BinaryUuidBuilder($query);
    }

    /**
     * Get the invoice that owns the item.
     *
     * @return BelongsTo<\AichaDigital\Larabill\Models\Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        $invoiceModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('invoice');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($invoiceModel, 'invoice_id', 'id');
    }
}
