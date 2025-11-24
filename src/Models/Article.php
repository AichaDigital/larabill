<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100Int;
use AichaDigital\Larabill\Enums\{BillingFrequency, ItemType};
use Illuminate\Database\Eloquent\{Builder, Model, SoftDeletes};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

/**
 * Article Model
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $item_type
 * @property string|null $category
 * @property int $base_price
 * @property int|null $cost_price
 * @property bool $is_recurring
 * @property string|null $billing_frequency
 * @property int|null $billing_interval
 * @property int|null $billing_days_in_advance
 * @property string|null $subscription_type
 * @property int|null $tax_group_id
 * @property int|null $unit_measure_id
 * @property bool $is_active
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \AichaDigital\Larabill\Models\TaxGroup|null $taxGroup
 * @property-read \AichaDigital\Larabill\Models\UnitMeasure|null $unitMeasure
 * @property-read \Illuminate\Database\Eloquent\Collection|\AichaDigital\Larabill\Models\ArticleOverride[] $overrides
 * @property-read \Illuminate\Database\Eloquent\Collection|\AichaDigital\Larabill\Models\ArticleServiceStatus[] $serviceStatuses
 */
class Article extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'articles';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'item_type',
        'category',
        'base_price',
        'cost_price',
        'is_recurring',
        'billing_frequency',
        'billing_interval',
        'billing_days_in_advance',
        'subscription_type',
        'tax_group_id',
        'unit_measure_id',
        'is_active',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'item_type'                => ItemType::class,
        'billing_frequency'        => BillingFrequency::class,
        'base_price'               => Base100Int::class,
        'cost_price'               => Base100Int::class,
        'is_recurring'             => 'boolean',
        'is_active'                => 'boolean',
        'billing_interval'         => 'integer',
        'billing_days_in_advance'  => 'integer',
        'metadata'                 => 'array',
    ];

    /**
     * Get the tax group for this article.
     */
    public function taxGroup(): BelongsTo
    {
        return $this->belongsTo(TaxGroup::class);
    }

    /**
     * Get the unit measure for this article.
     */
    public function unitMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitMeasure::class);
    }

    /**
     * Get all price overrides for this article.
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(ArticleOverride::class);
    }

    /**
     * Get all service status records for this article.
     */
    public function serviceStatuses(): HasMany
    {
        return $this->hasMany(ArticleServiceStatus::class);
    }

    /**
     * Scope to filter only active articles.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to filter only goods (products).
     */
    public function scopeGoods(Builder $query): void
    {
        $query->where('item_type', ItemType::GOOD);
    }

    /**
     * Scope to filter only services.
     */
    public function scopeServices(Builder $query): void
    {
        $query->where('item_type', ItemType::SERVICE);
    }

    /**
     * Scope to filter only recurring articles.
     */
    public function scopeRecurring(Builder $query): void
    {
        $query->where('is_recurring', true);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeByCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    /**
     * Check if this article is a service.
     */
    public function isService(): bool
    {
        return $this->item_type === ItemType::SERVICE;
    }

    /**
     * Check if this article is a good (product).
     */
    public function isGood(): bool
    {
        return $this->item_type === ItemType::GOOD;
    }

    /**
     * Check if this article requires an instance identifier.
     */
    public function requiresInstanceIdentifier(): bool
    {
        if ($this->isGood()) {
            return false;
        }

        // Check metadata configuration
        return $this->metadata['service']['requires_instance'] ?? true;
    }

    /**
     * Validate an instance identifier according to article rules.
     */
    public function validateInstanceIdentifier(?string $identifier): bool
    {
        if (! $this->requiresInstanceIdentifier()) {
            return true;
        }

        if (! $identifier) {
            return false;
        }

        $rules = $this->metadata['service']['instance_validation'] ?? [];

        return match ($rules['type'] ?? 'any') {
            'domain' => $this->validateDomain($identifier),
            'email'  => (bool) filter_var($identifier, FILTER_VALIDATE_EMAIL),
            'url'    => (bool) filter_var($identifier, FILTER_VALIDATE_URL),
            default  => true,
        };
    }

    /**
     * Validate domain name format.
     */
    protected function validateDomain(string $domain): bool
    {
        // Basic domain validation regex
        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain);
    }

    /**
     * Get the effective price for a customer.
     * Checks for active overrides first, falls back to base price.
     */
    public function getEffectivePriceFor(?int $customerId): float
    {
        if (! $customerId) {
            return $this->base_price;
        }

        $override = $this->getActiveOverrideFor($customerId);

        return $override->custom_price ?? $this->base_price;
    }

    /**
     * Get active price override for a customer.
     */
    public function getActiveOverrideFor(int $customerId): ?ArticleOverride
    {
        return $this->overrides()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', now());
            })
            ->first();
    }

    /**
     * Check if customer has an active override.
     */
    public function hasActiveOverrideFor(int $customerId): bool
    {
        return $this->getActiveOverrideFor($customerId) !== null;
    }

    /**
     * Get profit margin (difference between base_price and cost_price).
     */
    public function getProfitMargin(): ?float
    {
        if (! $this->cost_price) {
            return null;
        }

        return $this->base_price - $this->cost_price;
    }

    /**
     * Get profit margin as percentage (rounded to int).
     */
    public function getProfitMarginPercentage(): ?int
    {
        if (! $this->cost_price || $this->base_price == 0) {
            return null;
        }

        $margin = $this->getProfitMargin();

        return (int) (($margin / $this->base_price) * 100);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \AichaDigital\Larabill\Database\Factories\ArticleFactory::new();
    }
}
