<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100Int;
use AichaDigital\Larabill\Database\Factories\ArticleFactory;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\ItemType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Translatable\HasTranslations;

/**
 * Article Model
 *
 * Uses spatie/laravel-translatable for name and description fields.
 *
 * @property int $id
 * @property string $code
 * @property array $name Name (translatable JSON)
 * @property array|null $description Description (translatable JSON)
 * @property ItemType $item_type
 * @property string|null $category
 * @property int|null $cost_price
 * @property string|null $subscription_type
 * @property int|null $tax_group_id
 * @property int|null $unit_measure_id
 * @property bool $is_active
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read TaxGroup|null $taxGroup
 * @property-read UnitMeasure|null $unitMeasure
 * @property-read \Illuminate\Database\Eloquent\Collection|ArticlePrice[] $prices
 * @property-read \Illuminate\Database\Eloquent\Collection|ArticleOverride[] $overrides
 * @property-read \Illuminate\Database\Eloquent\Collection|ArticleServiceStatus[] $serviceStatuses
 */
class Article extends Model
{
    use HasFactory;
    use HasTranslations;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'articles';

    /**
     * Translatable attributes (spatie/laravel-translatable).
     *
     * @var array<string>
     */
    public array $translatable = [
        'name',
        'description',
    ];

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'item_type',
        'category',
        'cost_price',
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
        'item_type'  => ItemType::class,
        'cost_price' => Base100Int::class,
        'is_active'  => 'boolean',
        'metadata'   => 'array',
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
     * Get all prices for this article.
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ArticlePrice::class);
    }

    /**
     * Get currently active prices for this article.
     */
    public function activePrices(): HasMany
    {
        return $this->prices()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', now());
            });
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
     * Scope to filter articles that have recurring prices.
     */
    public function scopeRecurring(Builder $query): void
    {
        $query->whereHas('activePrices', function ($q) {
            $q->where('billing_frequency', '!=', BillingFrequency::ONE_TIME);
        });
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
     * Check if this article has any recurring prices.
     */
    public function isRecurring(): bool
    {
        return $this->activePrices()
            ->where('billing_frequency', '!=', BillingFrequency::ONE_TIME)
            ->exists();
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
     * Get price for a specific billing frequency.
     */
    public function getPriceFor(BillingFrequency $frequency): ?float
    {
        return $this->activePrices()
            ->where('billing_frequency', $frequency)
            ->value('price');
    }

    /**
     * Get all available billing frequencies for this article.
     *
     * @return Collection<int, BillingFrequency>
     */
    public function getAvailableFrequencies(): Collection
    {
        return $this->activePrices()
            ->pluck('billing_frequency')
            ->unique()
            ->values();
    }

    /**
     * Get the default price (ONE_TIME or first available).
     */
    public function getDefaultPrice(): ?float
    {
        $oneTimePrice = $this->getPriceFor(BillingFrequency::ONE_TIME);

        if ($oneTimePrice !== null) {
            return $oneTimePrice;
        }

        /** @var ArticlePrice|null $firstPrice */
        $firstPrice = $this->activePrices()->first();

        return $firstPrice?->price;
    }

    /**
     * Get the effective price for a customer at a specific frequency.
     * Checks for active overrides first, falls back to standard price.
     */
    public function getEffectivePriceFor(?int $customerId, BillingFrequency $frequency): ?float
    {
        if ($customerId) {
            $override = $this->getActiveOverrideFor($customerId);
            if ($override) {
                return $override->custom_price;
            }
        }

        return $this->getPriceFor($frequency);
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
     * Get billing days in advance for a specific frequency.
     */
    public function getBillingDaysInAdvanceFor(BillingFrequency $frequency): ?int
    {
        return $this->activePrices()
            ->where('billing_frequency', $frequency)
            ->value('billing_days_in_advance');
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return ArticleFactory::new();
    }
}
