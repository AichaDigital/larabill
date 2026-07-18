<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\RoundingMode;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Database\Factories\InvoiceItemFactory;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\Services\ModelMappingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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
 * @property FixedDecimal $quantity Scale-2 FixedDecimal (1.50 stored unscaled 150)
 * @property int|null $unit_measure_id FK to unit_measures
 * @property FixedDecimal $unit_price Scale-2 FixedDecimal (€12.34 stored unscaled 1234)
 * @property FixedDecimal $taxable_amount Scale-2 FixedDecimal (€12.34 stored unscaled 1234)
 * @property FixedDecimal $total_tax_amount Scale-2 FixedDecimal - sum of all taxes
 * @property array<int, array{source_rate_id: int, name: string, rate: int, amount: int}> $taxes_applied JSON snapshot of applied taxes (immutable)
 * @property FixedDecimal $total_amount Scale-2 FixedDecimal (€12.34 stored unscaled 1234)
 * @property int $tax_rate Computed from taxes_applied (first tax rate or 0)
 * @property Carbon|null $service_date_from Service start date
 * @property Carbon|null $service_date_to Service end date
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use HasFactory;

    /**
     * The UUID columns for this model.
     * Including foreign key to enable proper UUID binary conversions in queries.
     *
     * @return list<string>
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
        'article_id',
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
     *
     * Note: invoice_id is handled by accessor/mutator for binary UUID conversion
     */
    public function casts(): array
    {
        return [
            'item_type'         => ItemType::class, // PHP Enum
            'quantity'          => FixedDecimalCast::class.':2', // 1.50 ↔ 150
            'unit_price'        => FixedDecimalCast::class.':2', // €12.34 ↔ 1234
            'taxable_amount'    => FixedDecimalCast::class.':2', // €12.34 ↔ 1234
            'total_tax_amount'  => FixedDecimalCast::class.':2',
            'taxes_applied'     => 'array',
            'total_amount'      => FixedDecimalCast::class.':2', // €12.34 ↔ 1234
            'service_date_from' => 'date',
            'service_date_to'   => 'date',
            'metadata'          => 'array',
        ];
    }

    /**
     * Override update to prevent modifications of immutable invoices' lines.
     *
     * Same pattern as Invoice::update() (AID-558, D8): the fiscal content of
     * a frozen invoice is protected at the line level too. save() stays as
     * the deliberate internal door, mirroring the header guard. Raw SQL and
     * query-builder mass updates remain outside this guarantee — the DB-level
     * half of D8 was cancelled in AID-468.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if ($this->invoice?->is_immutable) {
            throw new \Exception('Cannot update items of an immutable invoice');
        }

        return parent::update($attributes, $options);
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * Get the invoice that owns the item.
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        $invoiceModel = ModelMappingService::getModelClass('invoice');

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
     *
     * EU/Spain norm: settle quantity × unit_price to the cent with HalfUp
     * (round half away from zero), never truncation. The exact product is
     * scale 4; toScale(2, HalfUp) rounds it to the persisted cent.
     */
    public function calculateTaxableAmount(): FixedDecimal
    {
        return $this->quantity
            ->multipliedBy($this->unit_price)
            ->toScale(2, RoundingMode::HalfUp);
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

    /**
     * Accessor for tax_rate (computed from taxes_applied).
     * Returns the first tax rate in base100 format, or 0 if no taxes.
     */
    public function getTaxRateAttribute(): int
    {
        $taxes = $this->taxes_applied ?? [];

        if (empty($taxes)) {
            return 0;
        }

        // Return the rate from the first tax
        return (int) ($taxes[0]['rate'] ?? 0);
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope for goods only
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeGoods(Builder $query): Builder
    {
        return $query->where('item_type', ItemType::GOOD->value);
    }

    /**
     * Scope for services only
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeServices(Builder $query): Builder
    {
        return $query->where('item_type', ItemType::SERVICE->value);
    }

    /**
     * Scope for items with service dates
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithServiceDates(Builder $query): Builder
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
