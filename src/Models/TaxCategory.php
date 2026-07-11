<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Database\Factories\TaxCategoryFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tax Category Model
 *
 * Global tax system support (VAT, Sales Tax, GST, HST, etc.)
 * Extensible by region and country.
 *
 * @property int $id
 * @property string $code Tax code (vat_standard, sales_tax_ca, etc.)
 * @property string $name Display name
 * @property string $tax_type Tax system (vat, sales_tax, gst, hst)
 * @property string|null $description
 * @property FixedDecimal $default_rate Default tax rate percentage
 * @property string $country_code ISO 3166-1 alpha-2
 * @property string|null $region_code State/Province code
 * @property bool $is_active
 * @property int $sort_order
 * @property array<string, mixed>|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class TaxCategory extends Model
{
    /** @use HasFactory<TaxCategoryFactory> */
    use HasFactory;

    protected $table = 'tax_categories';

    protected $fillable = [
        'code',
        'name',
        'tax_type',
        'description',
        'default_rate',
        'country_code',
        'region_code',
        'is_active',
        'sort_order',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_rate' => FixedDecimalCast::class.':2',
            'is_active'    => 'boolean',
            'sort_order'   => 'integer',
            'metadata'     => 'array',
        ];
    }

    /**
     * Get invoice items using this tax category
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Scope for active categories only
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific country
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', strtoupper($countryCode));
    }

    /**
     * Scope for specific region
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRegion(Builder $query, string $regionCode): Builder
    {
        return $query->where('region_code', strtoupper($regionCode));
    }

    /**
     * Scope for specific tax type
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTaxType(Builder $query, string $taxType): Builder
    {
        return $query->where('tax_type', $taxType);
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

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TaxCategoryFactory
    {
        return TaxCategoryFactory::new();
    }
}
