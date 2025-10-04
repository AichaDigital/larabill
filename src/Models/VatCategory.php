<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * VatCategory Model
 *
 * Represents VAT categories for products and services
 * with specific tax rates by country.
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
     * The attributes that should be cast.
     */
    protected $casts = [
        'vat_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'applies_to_products' => 'boolean',
        'applies_to_services' => 'boolean',
        'special_conditions' => 'array',
        'last_updated' => 'datetime',
        'sort_order' => 'integer',
    ];

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
}
