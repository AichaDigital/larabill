<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Enums\UnitMeasureCategory;
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
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class UnitMeasure extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return \AichaDigital\Larabill\Database\Factories\UnitMeasureFactory::new();
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
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Scope for active units only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific category
     */
    public function scopeCategory($query, UnitMeasureCategory $category)
    {
        return $query->where('category', $category->value);
    }

    /**
     * Scope ordered by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
