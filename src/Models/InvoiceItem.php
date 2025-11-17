<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
use AichaDigital\Larabill\Database\Factories\InvoiceItemFactory;
use AichaDigital\Larabill\Enums\ItemType;
use Dyrynda\Database\Support\Casts\EfficientUuid;
use Dyrynda\Database\Support\GeneratesUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceItem Model - Immutable Record Layer
 *
 * Represents an item within an invoice with immutable tax snapshot.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 *
 * v0.3.3: Agnostic Tax System Refactor
 * - Removed: tax_rate, tax_category_id (old single-tax approach)
 * - Added: total_tax_amount, taxes_applied (JSON) for multiple taxes support
 * - Tax immutability: Once created, the invoice item stores a permanent snapshot
 *   of all applied taxes, independent of future changes to tax_rates or tax_groups
 *
 * Tax Structure:
 * - total_tax_amount: Sum of all taxes (base-100 integer) - optimized for aggregation
 * - taxes_applied: JSON array with detailed tax breakdown
 *   Example: [{"source_rate_id":12,"name":"MA State Sales Tax","rate":625,"amount":625}]
 *
 * @property int $id
 * @property string $invoice_id UUID foreign key to invoices table
 * @property int|null $article_id FK to articles (v0.4.0)
 * @property ItemType $item_type Good or Service
 * @property string $description
 * @property string|null $internal_code User product/service code
 * @property float $quantity Base-100 integer (1.5 => 150)
 * @property int|null $unit_measure_id FK to unit_measures
 * @property float $unit_price Base-100 integer (€12.34 => 1234)
 * @property float $taxable_amount Base-100 integer (€12.34 => 1234)
 * @property float $total_tax_amount Base-100 integer - sum of all taxes
 * @property array $taxes_applied JSON snapshot of applied taxes (immutable)
 * @property float $total_amount Base-100 integer (€12.34 => 1234)
 * @property \Carbon\Carbon|null $service_date_from Service start date
 * @property \Carbon\Carbon|null $service_date_to Service end date
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class InvoiceItem extends Model
{
    use GeneratesUuid, HasFactory;

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
        'total_tax_amount',
        'taxes_applied',
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
            'total_tax_amount'  => Base100::class,
            'taxes_applied'     => 'array',
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

    // ========================================
    // CALCULATION HELPERS
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
     * Get tax breakdown from the immutable taxes_applied snapshot.
     *
     * @return array<int, array{source_rate_id: int, name: string, rate: int, amount: int}>
     */
    public function getTaxBreakdown(): array
    {
        return $this->taxes_applied ?? [];
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

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): InvoiceItemFactory
    {
        return InvoiceItemFactory::new();
    }
}
