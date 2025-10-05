<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Carbon;

/**
 * VatCategory Model
 *
 * Represents VAT categories for products and services with specific tax rates by country.
 * VAT rates are stored as base-100 integers (e.g., 21.50% => 2150).
 *
 * @property string $name
 * @property string|null $description
 * @property string $country_code
 * @property int $vat_rate Base-100 integer (e.g., 2150 => 21.50%)
 * @property string $category_type
 * @property bool $is_active
 * @property bool $applies_to_products
 * @property bool $applies_to_services
 * @property array|null $special_conditions
 * @property Carbon|null $last_updated
 * @property int|null $parent_category_id
 * @property int $sort_order
 */
class VatCategory extends Model
{
    /**
     * Category types.
     */
    public const CATEGORY_TYPE_STANDARD = 'standard';

    public const CATEGORY_TYPE_REDUCED = 'reduced';

    public const CATEGORY_TYPE_SUPER_REDUCED = 'super_reduced';

    public const CATEGORY_TYPE_EXEMPT = 'exempt';

    // Backward compatibility
    public const TYPE_STANDARD = 'standard';

    public const TYPE_REDUCED = 'reduced';

    public const TYPE_SUPER_REDUCED = 'super_reduced';

    public const TYPE_EXEMPT = 'exempt';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'description',
        'country_code',
        'vat_rate',
        'category_type',
        'is_active',
        'applies_to_products',
        'applies_to_services',
        'special_conditions',
        'last_updated',
        'parent_category_id',
        'sort_order',
    ];

    /**
     * Casts for attributes.
     *
     * Uses integers in base 100 for VAT rates (percentages)
     * Example: 21.50% is stored as 2150, 12.34% as 1234
     */
    public function casts(): array
    {
        return [
            'vat_rate' => 'integer', // Base 100: 21.50% = 2150
            'is_active' => 'boolean',
            'applies_to_products' => 'boolean',
            'applies_to_services' => 'boolean',
            'special_conditions' => 'array',
            'last_updated' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get VAT rate as base-100 integer.
     */
    public function getVatRateAttribute($value): int
    {
        return $value === null ? 0 : (int) $value;
    }

    /**
     * Mutator for vat_rate to convert percentage to base 100.
     */
    public function setVatRateAttribute($value): void
    {
        // If the value is a float (like 21.00), convert to base 100 integer
        if (is_float($value)) {
            $this->attributes['vat_rate'] = static::percentageToBase100($value);
        } else {
            $this->attributes['vat_rate'] = $value;
        }
    }

    /**
     * Convert percentage to base 100 integer.
     */
    public static function percentageToBase100(float $percentage): int
    {
        return (int) ($percentage * 100);
    }

    /**
     * Convert base 100 integer to percentage.
     */
    public static function base100ToPercentage(int $base100): float
    {
        return $base100 / 100.0;
    }

    /**
     * Get VAT rate as percentage.
     */
    public function getVatRateAsPercentage(): float
    {
        return static::base100ToPercentage($this->vat_rate);
    }

    /**
     * Set VAT rate from percentage.
     */
    public function setVatRateFromPercentage(float $percentage): self
    {
        $this->update(['vat_rate' => static::percentageToBase100($percentage)]);
        return $this;
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Set last_updated if not provided
            if (! $model->last_updated) {
                $model->last_updated = now();
            }

            // Set sort_order if not provided
            if (! $model->sort_order) {
                $model->sort_order = static::getNextSortOrder($model->country_code);
            }

            // Apply field mapping when creating
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('vat_category');
            if (! empty($fieldMapping)) {
                $attributes = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::reverseMapFields($attributes, 'vat_category');
                $model->setRawAttributes($mappedAttributes);
            }
        });

        static::updating(function ($model) {
            // Update last_updated on any change
            $model->last_updated = now();
        });

        static::retrieved(function ($model) {
            // Apply field mapping when retrieving
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('vat_category');
            if (! empty($fieldMapping)) {
                $attributes = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::mapFields($attributes, 'vat_category');
                $model->setRawAttributes($mappedAttributes);
            }
        });
    }

    /**
     * Get VAT categories by country.
     */
    public static function getByCountry(string $countryCode): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('country_code', $countryCode)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get VAT categories by category type.
     */
    public static function getByCategoryType(string $categoryType): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('category_type', $categoryType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get VAT categories by country and type.
     */
    public static function getByCountryAndType(string $countryCode, string $categoryType): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('country_code', $countryCode)
            ->where('category_type', $categoryType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Find VAT category by name and country.
     */
    public static function findByNameAndCountry(string $name, string $countryCode): ?self
    {
        return static::where('name', $name)
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get VAT rate for a specific category and country.
     */
    public static function getVatRate(string $categoryName, string $countryCode): ?float
    {
        $category = static::findByNameAndCountry($categoryName, $countryCode);

        return $category ? $category->vat_rate : null;
    }

    /**
     * Get VAT rate for this category instance.
     */
    public function getRate(): int
    {
        return (int) $this->vat_rate;
    }

    /**
     * Get standard VAT rate for a country.
     */
    public static function getStandardRate(string $countryCode): ?float
    {
        $category = static::where('country_code', $countryCode)
            ->where('category_type', self::TYPE_STANDARD)
            ->where('is_active', true)
            ->first();

        return $category ? $category->vat_rate : null;
    }

    /**
     * Get reduced VAT rate for a country.
     */
    public static function getReducedRate(string $countryCode): ?float
    {
        $category = static::where('country_code', $countryCode)
            ->where('category_type', self::TYPE_REDUCED)
            ->where('is_active', true)
            ->first();

        return $category ? $category->vat_rate : null;
    }

    /**
     * Get super reduced VAT rate for a country.
     */
    public static function getSuperReducedRate(string $countryCode): ?float
    {
        $category = static::where('country_code', $countryCode)
            ->where('category_type', self::TYPE_SUPER_REDUCED)
            ->where('is_active', true)
            ->first();

        return $category ? $category->vat_rate : null;
    }

    /**
     * Check if category applies to products.
     */
    public function appliesToProducts(): bool
    {
        return $this->applies_to_products;
    }

    /**
     * Check if category applies to services.
     */
    public function appliesToServices(): bool
    {
        return $this->applies_to_services;
    }

    /**
     * Check if category applies to both products and services.
     */
    public function appliesToBoth(): bool
    {
        return $this->applies_to_products && $this->applies_to_services;
    }

    /**
     * Get special conditions for this category.
     */
    public function getSpecialConditions(): array
    {
        return $this->special_conditions ?? [];
    }

    /**
     * Check if category has special conditions.
     */
    public function hasSpecialConditions(): bool
    {
        return ! empty($this->special_conditions);
    }

    /**
     * Get specific special condition.
     */
    public function getSpecialCondition(string $key): mixed
    {
        return $this->special_conditions[$key] ?? null;
    }

    /**
     * Set special condition.
     */
    public function setSpecialCondition(string $key, mixed $value): self
    {
        $conditions = $this->special_conditions ?? [];
        $conditions[$key] = $value;
        $this->update(['special_conditions' => $conditions]);

        return $this;
    }

    /**
     * Scope to get only active categories.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get categories by country.
     */
    public function scopeByCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope to get categories by type.
     */
    public function scopeByCategoryType($query, string $categoryType)
    {
        return $query->where('category_type', $categoryType);
    }

    /**
     * Scope to get categories that apply to products.
     */
    public function scopeForProducts($query)
    {
        return $query->where('applies_to_products', true);
    }

    /**
     * Scope to get categories that apply to services.
     */
    public function scopeForServices($query)
    {
        return $query->where('applies_to_services', true);
    }

    /**
     * Scope to get categories ordered by sort order.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Get parent category.
     */
    public function parentCategory()
    {
        return $this->belongsTo(self::class, 'parent_category_id');
    }

    /**
     * Get child categories.
     */
    public function childCategories()
    {
        return $this->hasMany(self::class, 'parent_category_id');
    }

    /**
     * Check if this is a parent category.
     */
    public function isParent(): bool
    {
        return $this->childCategories()->exists();
    }

    /**
     * Check if this is a child category.
     */
    public function isChild(): bool
    {
        return ! is_null($this->parent_category_id);
    }

    /**
     * Get the full category path (parent > child).
     */
    public function getFullPath(): string
    {
        $path = [$this->name];

        $parent = $this->parentCategory;
        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parentCategory;
        }

        return implode(' > ', $path);
    }

    /**
     * Get next sort order for a country.
     */
    protected static function getNextSortOrder(string $countryCode): int
    {
        $lastCategory = static::where('country_code', $countryCode)
            ->orderBy('sort_order', 'desc')
            ->first();

        return $lastCategory ? $lastCategory->sort_order + 1 : 1;
    }

    /**
     * Get all available category types.
     */
    public static function getCategoryTypes(): array
    {
        return [
            self::TYPE_STANDARD,
            self::TYPE_REDUCED,
            self::TYPE_SUPER_REDUCED,
            self::TYPE_EXEMPT,
        ];
    }

    /**
     * Get category type label.
     */
    public function getCategoryTypeLabel(): string
    {
        return match ($this->category_type) {
            self::TYPE_STANDARD => 'Standard Rate',
            self::TYPE_REDUCED => 'Reduced Rate',
            self::TYPE_SUPER_REDUCED => 'Super Reduced Rate',
            self::TYPE_EXEMPT => 'Exempt',
            default => 'Unknown',
        };
    }

    /**
     * Check if category is exempt.
     */
    public function isExempt(): bool
    {
        return $this->category_type === self::TYPE_EXEMPT || $this->vat_rate == 0;
    }

    /**
     * Get formatted VAT rate.
     */
    public function getFormattedVatRate(): string
    {
        if ($this->isExempt()) {
            return 'Exempt';
        }

        return number_format($this->vat_rate, 2).'%';
    }

    /**
     * Find VAT category by country and name.
     */
    public static function findByCountryAndName(string $countryCode, string $name): ?self
    {
        return static::where('country_code', $countryCode)
            ->where('name', $name)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Find VAT categories by country.
     */
    public static function findByCountry(string $countryCode): Builder
    {
        return static::where('country_code', $countryCode)
            ->where('is_active', true);
    }

    /**
     * Find VAT categories by type.
     */
    public static function findByType(string $categoryType): Builder
    {
        return static::where('category_type', $categoryType)
            ->where('is_active', true);
    }

    /**
     * Scope for inactive categories.
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Scope for categories that apply to both products and services.
     */
    public function scopeForBoth($query)
    {
        return $query->where('applies_to_products', true)
            ->where('applies_to_services', true);
    }

    /**
     * Find VAT categories by rate.
     */
    public static function findByRate(string $countryCode, int|string|float $vatRate): ?self
    {
        // Convert percentage to base 100 if needed
        if (is_float($vatRate)) {
            $rate = static::percentageToBase100($vatRate);
        } else {
            $rate = (int) $vatRate;
        }

        return static::where('country_code', $countryCode)
            ->where('vat_rate', $rate)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Scope to get categories by type.
     */
    public function scopeByType($query, string $categoryType)
    {
        return $query->where('category_type', $categoryType);
    }

    /**
     * Check if category is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Activate category.
     */
    public function activate(): self
    {
        $this->update(['is_active' => true]);
        return $this;
    }

    /**
     * Deactivate category.
     */
    public function deactivate(): self
    {
        $this->update(['is_active' => false]);
        return $this;
    }

    /**
     * Set special conditions.
     */
    public function setSpecialConditions(array $conditions): self
    {
        $this->update(['special_conditions' => $conditions]);
        return $this;
    }

    /**
     * Get category type name.
     */
    public function getCategoryTypeName(): string
    {
        return match ($this->category_type) {
            self::TYPE_STANDARD => 'Standard',
            self::TYPE_REDUCED => 'Reduced',
            self::TYPE_SUPER_REDUCED => 'Super Reduced',
            self::TYPE_EXEMPT => 'Exempt',
            default => 'Unknown',
        };
    }

    /**
     * Get available category types.
     */
    public static function getAvailableCategoryTypes(): array
    {
        return static::getCategoryTypes();
    }

    /**
     * Get category statistics.
     */
    public static function getCategoryStatistics(): array
    {
        $total = static::count();
        $active = static::where('is_active', true)->count();
        $inactive = static::where('is_active', false)->count();

        $byType = static::selectRaw('category_type, COUNT(*) as count')
            ->groupBy('category_type')
            ->pluck('count', 'category_type')
            ->toArray();

        $byCountry = static::selectRaw('country_code, COUNT(*) as count')
            ->groupBy('country_code')
            ->pluck('count', 'country_code')
            ->toArray();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'by_type' => $byType,
            'by_country' => $byCountry,
        ];
    }
}
