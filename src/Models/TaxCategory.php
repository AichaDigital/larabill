<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
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
 * @property float $default_rate Default tax rate percentage
 * @property string $country_code ISO 3166-1 alpha-2
 * @property string|null $region_code State/Province code
 * @property bool $is_active
 * @property int $sort_order
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TaxCategory extends Model
{
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
            'default_rate' => Base100::class,
            'is_active'    => 'boolean',
            'sort_order'   => 'integer',
            'metadata'     => 'array',
        ];
    }

    /**
     * Get invoice items using this tax category
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Scope for active categories only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for specific country
     */
    public function scopeCountry($query, string $countryCode)
    {
        return $query->where('country_code', strtoupper($countryCode));
    }

    /**
     * Scope for specific region
     */
    public function scopeRegion($query, string $regionCode)
    {
        return $query->where('region_code', strtoupper($regionCode));
    }

    /**
     * Scope for specific tax type
     */
    public function scopeTaxType($query, string $taxType)
    {
        return $query->where('tax_type', $taxType);
    }

    /**
     * Scope ordered by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
