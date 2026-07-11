<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Database\Factories\UnitMeasureFactory;
use AichaDigital\Larabill\Enums\UnitMeasureCategory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unit Measure Model
 *
 * Extensible system for defining units of measure.
 * Users can add custom units (e.g., barrels, boxes, sessions).
 *
 * @property int $id
 * @property string $code Unique code (unit, kg, liter, meter)
 * @property string $symbol Display symbol (ud., kg, L, m)
 * @property string $name Full name
 * @property UnitMeasureCategory $category
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class UnitMeasure extends Model
{
    /** @use HasFactory<UnitMeasureFactory> */
    use HasFactory;

    protected static function newFactory(): UnitMeasureFactory
    {
        return UnitMeasureFactory::new();
    }

    protected $table = 'unit_measures';

    protected $fillable = [
        'code',
        'symbol',
        'name',
        'category',
        'is_active',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category'   => UnitMeasureCategory::class,
            'is_active'  => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get invoice items using this unit measure
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Scope for active units only
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific category
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCategory(Builder $query, UnitMeasureCategory $category): Builder
    {
        return $query->where('category', $category->value);
    }

    /**
     * Scope ordered by sort_order
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
