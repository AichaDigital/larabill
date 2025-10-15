<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
use AichaDigital\Larabill\Enums\ItemType;
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
 * v0.3.3: Enhanced for fiscal compliance (CEE/EU):
 * - Distinction between goods and services (item_type)
 * - Service dates (service_date_from/to) for EU compliance
 * - Extensible unit measures (FK to unit_measures)
 * - Extensible tax categories (FK to tax_categories)
 *
 * @property int $id
 * @property string $invoice_id UUID foreign key to invoices table
 * @property ItemType $item_type Good or Service
 * @property string $description
 * @property string|null $internal_code User product/service code
 * @property float $quantity Base-100 integer (1.5 => 150)
 * @property int|null $unit_measure_id FK to unit_measures
 * @property float $unit_price Base-100 integer (€12.34 => 1234)
 * @property float $taxable_amount Base-100 integer (€12.34 => 1234)
 * @property float $tax_rate Base-100 integer (21% => 2100)
 * @property int|null $tax_category_id FK to tax_categories
 * @property float $tax_amount Base-100 integer (€12.34 => 1234)
 * @property float $total_amount Base-100 integer (€12.34 => 1234)
 * @property \Carbon\Carbon|null $service_date_from Service start date
 * @property \Carbon\Carbon|null $service_date_to Service end date
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
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
        'item_type',
        'description',
        'internal_code',
        'quantity',
        'unit_measure_id',
        'unit_price',
        'taxable_amount',
        'tax_rate',
        'tax_category_id',
        'tax_amount',
        'total_amount',
        'service_date_from',
        'service_date_to',
        'metadata',
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
            'invoice_id'        => EfficientUuid::class, // UUID binary(16)
            'item_type'         => ItemType::class, // PHP Enum
            'quantity'          => Base100::class, // 1.5 ↔ 150
            'unit_price'        => Base100::class, // €12.34 ↔ 1234
            'taxable_amount'    => Base100::class, // €12.34 ↔ 1234
            'tax_rate'          => Base100::class, // 21.50% ↔ 2150
            'tax_amount'        => Base100::class, // €12.34 ↔ 1234
            'total_amount'      => Base100::class, // €12.34 ↔ 1234
            'service_date_from' => 'date',
            'service_date_to'   => 'date',
            'metadata'          => 'array',
        ];
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

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

    /**
     * Get the unit measure for this item
     *
     * @return BelongsTo<UnitMeasure, $this>
     */
    public function unitMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitMeasure::class);
    }

    /**
     * Get the tax category for this item
     *
     * @return BelongsTo<TaxCategory, $this>
     */
    public function taxCategory(): BelongsTo
    {
        return $this->belongsTo(TaxCategory::class);
    }

    // ========================================
    // CALCULATION METHODS
    // ========================================

    /**
     * Calculate taxable amount from quantity and unit price.
     * Base100 cast handles conversion automatically.
     */
    public function calculateTaxableAmount(): float
    {
        return round($this->quantity * $this->unit_price, 2);
    }

    /**
     * Calculate tax amount from taxable amount and tax rate.
     * Base100 cast handles conversion automatically.
     */
    public function calculateTaxAmount(): float
    {
        return round($this->taxable_amount * ($this->tax_rate / 100), 2);
    }

    /**
     * Calculate total from taxable amount and tax amount.
     * Base100 cast handles conversion automatically.
     */
    public function calculateTotalAmount(): float
    {
        return $this->taxable_amount + $this->tax_amount;
    }

    // ========================================
    // QUERY BUILDER
    // ========================================

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

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope for goods only
     */
    public function scopeGoods($query)
    {
        return $query->where('item_type', ItemType::GOOD->value);
    }

    /**
     * Scope for services only
     */
    public function scopeServices($query)
    {
        return $query->where('item_type', ItemType::SERVICE->value);
    }

    /**
     * Scope for items with service dates
     */
    public function scopeWithServiceDates($query)
    {
        return $query->whereNotNull('service_date_from')
            ->whereNotNull('service_date_to');
    }
}
