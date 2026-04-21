<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Database\Factories\TaxRateFactory;
use AichaDigital\Larabill\Enums\TaxType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TaxRate Model - Configuration Layer (Mutable)
 *
 * Represents an atomic tax rate definition in the configuration layer.
 * This is a "living" table that can be updated when tax laws change.
 * Tax rates are stored as base-100 integers (e.g., 21.50% => 2150).
 *
 * Examples:
 * - IVA General (Spain): rate=2100, region="ES", type=VAT
 * - MA State Sales Tax: rate=625, region="US-MA", type=SALES_TAX
 * - Boston City Surcharge: rate=50, region="US-MA-BOSTON", type=SALES_TAX
 *
 * @property int $id
 * @property string $name Tax rate name (e.g., "IVA General", "MA State Sales Tax")
 * @property int $rate Tax rate as base-100 integer (21% => 2100)
 * @property string|null $region Region/jurisdiction code (e.g., "ES", "IC", "US-MA", "US-MA-BOSTON")
 * @property TaxType $type Tax type enum (vat, sales_tax, gst, other)
 * @property array|null $special_conditions Additional metadata for special cases (IGIC, IPSI, exemptions)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class TaxRate extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'rate',
        'region',
        'type',
        'special_conditions',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate'               => 'integer',
            'type'               => TaxType::class,
            'special_conditions' => 'array',
        ];
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * The tax groups that belong to this tax rate.
     * Many-to-many relationship through tax_group_tax_rate pivot.
     *
     * @return BelongsToMany<TaxGroup>
     */
    public function taxGroups(): BelongsToMany
    {
        return $this->belongsToMany(TaxGroup::class, 'tax_group_tax_rate')
            ->withPivot('priority')
            ->orderBy('priority');
    }

    // ========================================
    // FACTORY
    // ========================================

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TaxRateFactory
    {
        return TaxRateFactory::new();
    }
}
