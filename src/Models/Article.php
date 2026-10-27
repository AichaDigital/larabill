<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Casts\AichaMoneyCast;
use AichaDigital\Larabill\Database\Factories\ArticleFactory;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\ItemType;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Translatable\HasTranslations;

/**
 * Article Model
 *
 * Uses spatie/laravel-translatable for name and description fields.
 *
 * @property int $id
 * @property string $code
 * @property array<string, string> $name Name (translatable JSON)
 * @property array<string, string>|null $description Description (translatable JSON)
 * @property ItemType $item_type
 * @property string|null $category
 * @property FixedDecimal|null $cost_price
 * @property string|null $subscription_type
 * @property int|null $tax_group_id
 * @property int|null $unit_measure_id
 * @property bool $is_active
 * @property int|null $guarantee_days Guarantee refund window in days (ADR-015 §2.6 product capability); NULL/0 = no window
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read TaxGroup|null $taxGroup
 * @property-read UnitMeasure|null $unitMeasure
 * @property-read \Illuminate\Database\Eloquent\Collection|ArticlePrice[] $prices
 * @property-read \Illuminate\Database\Eloquent\Collection|ArticleOverride[] $overrides
 * @property-read \Illuminate\Database\Eloquent\Collection|ArticleServiceStatus[] $serviceStatuses
 * @property-read \Illuminate\Database\Eloquent\Collection|InvoiceItem[] $invoiceItems
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
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
        'guarantee_days',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'item_type'      => ItemType::class,
        'cost_price'     => AichaMoneyCast::class.':2',
        'is_active'      => 'boolean',
        'guarantee_days' => 'integer',
        'metadata'       => 'array',
    ];

    /**
     * Get the tax group for this article.
     *
     * @return BelongsTo<TaxGroup, $this>
     */
    public function taxGroup(): BelongsTo
    {
        return $this->belongsTo(TaxGroup::class);
    }

    /**
     * Get the unit measure for this article.
     *
     * @return BelongsTo<UnitMeasure, $this>
     */
    public function unitMeasure(): BelongsTo
    {
        return $this->belongsTo(UnitMeasure::class);
    }

    /**
     * Get all prices for this article.
     *
     * @return HasMany<ArticlePrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ArticlePrice::class);
    }

    /**
     * Get currently active prices for this article.
     *
     * @return HasMany<ArticlePrice, $this>
     */
    public function activePrices(): HasMany
    {
        // Day-granular on purpose (AID-1592): valid_from/valid_to are DATE
        // casts — a price valid until today stays valid THROUGH the whole
        // last day. Comparing against now() (with time) dropped it at 00:00.
        $today = Carbon::today();

        return $this->prices()
            ->where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $today);
            });
    }

    /**
     * Get all price overrides for this article.
     *
     * @return HasMany<ArticleOverride, $this>
     */
    public function overrides(): HasMany
    {
        return $this->hasMany(ArticleOverride::class);
    }

    /**
     * Get all service status records for this article.
     *
     * @return HasMany<ArticleServiceStatus, $this>
     */
    public function serviceStatuses(): HasMany
    {
        return $this->hasMany(ArticleServiceStatus::class);
    }

    /**
     * Get invoice items created from this article.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * Scope to filter only active articles.
     *
     * @param  Builder<Article>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to filter only goods (products).
     *
     * @param  Builder<Article>  $query
     */
    public function scopeGoods(Builder $query): void
    {
        $query->where('item_type', ItemType::GOOD);
    }

    /**
     * Scope to filter only services.
     *
     * @param  Builder<Article>  $query
     */
    public function scopeServices(Builder $query): void
    {
        $query->where('item_type', ItemType::SERVICE);
    }

    /**
     * Scope to filter by category.
     *
     * @param  Builder<Article>  $query
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
     * Resolve the deterministic price of one cadence — the interval-keyed
     * read of the price catalogue (ADR-015 §2.3).
     *
     * A NULL interval matches the NULL cadence exactly: the one-time price,
     * which is real persisted data (legacy ONE_TIME).
     */
    public function getPriceForInterval(?BillingInterval $interval): ?float
    {
        return $this->resolvePriceForInterval($interval);
    }

    /**
     * Resolve the deterministic price of one cadence — the interval-keyed
     * body of `getPriceFor()`.
     *
     * Eloquent's value() hydrates the casted attribute, so price comes back
     * as a FixedDecimal; expose the raw base-100 cents to preserve the
     * ?float contract and the int-cents expectations of every caller.
     */
    private function resolvePriceForInterval(?BillingInterval $interval): ?float
    {
        $query = $this->activePrices();

        if ($interval === null) {
            $query->whereNull('billing_unit')->whereNull('billing_quantity');
        } else {
            $query->where('billing_unit', $interval->unit)->where('billing_quantity', $interval->quantity);
        }

        return $query
            // Deterministic pick when a legacy database still holds overlapping
            // rows (AID-601): most recent start wins, id breaks the tie. NULL
            // sorts lowest in MySQL and SQLite, so a dated row beats an
            // open-start legacy one. Aimed at predictability, not at guessing
            // the operator's intent — the real fix is removing the duplicate.
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->value('price')?->unscaledValue();
    }

    /**
     * Get all active cadences this article can bill on — the interval-native
     * read of the price catalogue (ADR-015 §2.3; added in 9.0.0 when the
     * frequency bridge read was deprecated).
     *
     * The NULL element is REAL data, not an absence: a NULL cadence is a
     * one-time cadence (§2.3/§4.1 — persisted, queryable, and the key the
     * interval API uses for the one-time price: `getPriceForInterval(null)`,
     * `getBillingDaysInAdvanceForInterval(null)`). Keeping it `null` (not
     * collapsing it into a marker interval) keeps this read symmetric with
     * those interval-keyed consumers, so a caller can feed an element of the
     * collection straight back into the interval API. Deduplication runs on
     * the unit/quantity pair; NULL deduplicates against NULL.
     *
     * @return Collection<int, BillingInterval|null>
     */
    public function getAvailableIntervals(): Collection
    {
        return $this->activePrices()
            ->get(['billing_unit', 'billing_quantity'])
            ->map(fn (ArticlePrice $price): ?BillingInterval => $price->interval())
            ->unique(fn (?BillingInterval $interval): string => $interval === null ? '' : $interval->unit.':'.$interval->quantity)
            ->values();
    }

    /**
     * Get all available billing frequencies for this article — the legacy
     * bridge read over the retired `BillingFrequency` enum (ADR-015 §5).
     *
     * @deprecated since 9.0.0, removal in 10.0 — use `getAvailableIntervals()`, the interval-native read (a NULL element is the one-time cadence) — see UPGRADE-9.0.md
     *
     * Each cadence is bridged back through the interval domain. A cadence
     * without a legacy equivalent throws — the bridge fails loud instead of
     * silently dropping the cadence from a read.
     *
     * @return Collection<int, BillingFrequency>
     */
    public function getAvailableFrequencies(): Collection
    {
        $this->triggerLegacyDeprecation(
            'Article::getAvailableFrequencies()',
            'Article::getAvailableIntervals()',
        );

        return $this->activePrices()
            ->get(['billing_unit', 'billing_quantity'])
            ->map(fn (ArticlePrice $price): BillingFrequency => $price->interval()?->toLegacyFrequency() ?? BillingFrequency::ONE_TIME)
            ->unique()
            ->values();
    }

    /**
     * Get the default price (ONE_TIME or first available).
     *
     * @api Amber-band contract operation (AID-407): consumers call it only behind an app-side port.
     */
    public function getDefaultPrice(): ?float
    {
        // NULL cadence IS the ONE_TIME price in the ADR-015 interval domain.
        $oneTimePrice = $this->getPriceForInterval(null);

        if ($oneTimePrice !== null) {
            return $oneTimePrice;
        }

        /** @var ArticlePrice|null $firstPrice */
        $firstPrice = $this->activePrices()->first();

        return $firstPrice?->price?->unscaledValue();
    }

    /**
     * Resolve the override in force for a customer at a given instant.
     *
     * Single resolution path (AID-974 D3): validity comes from the shared scope,
     * and the order is deterministic — most recent start wins, id breaks ties.
     * NULL sorts lowest in MySQL and SQLite, so a dated row beats a legacy
     * open-start one. Predictability, not intent-guessing: the real fix is not
     * having the duplicate, which ArticleOverrideService guarantees.
     *
     * `$at` is the ordinary `Carbon\Carbon`, not `Illuminate\Support\Carbon`:
     * that is what `scopeValidAt()`, `scopeOverlapping()` and
     * `ArticleOverrideService::setOverride()` already take, and the Illuminate
     * subclass satisfies it. The stricter type would reject a plain Carbon and
     * the contract snapshot freezes it, so narrowing it later costs a major.
     */
    public function resolveOverrideFor(int|string $customerId, Carbon $at): ?ArticleOverride
    {
        return $this->overrides()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->validAt($at)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Get active price override for a customer.
     */
    public function getActiveOverrideFor(int|string $customerId): ?ArticleOverride
    {
        return $this->resolveOverrideFor($customerId, now());
    }

    /**
     * Check if customer has an active override.
     */
    public function hasActiveOverrideFor(int|string $customerId): bool
    {
        return $this->getActiveOverrideFor($customerId) !== null;
    }

    /**
     * Get billing days in advance for one cadence — the interval-keyed read
     * of the price catalogue (ADR-015 §2.3).
     *
     * This is the interval-native path the recurring engine uses (the
     * agreement's cadence is unit/quantity since slice 2b): the inverse
     * enum bridge is NOT applied here, so cadences inside the new domain but
     * outside the legacy image (e.g. month/5) resolve their per-cadence
     * advance window instead of throwing.
     *
     * A NULL interval matches the NULL cadence exactly: the one-time price's
     * window (legacy ONE_TIME).
     */
    public function getBillingDaysInAdvanceForInterval(?BillingInterval $interval): ?int
    {
        $query = $this->activePrices();

        if ($interval === null) {
            $query->whereNull('billing_unit')->whereNull('billing_quantity');
        } else {
            $query->where('billing_unit', $interval->unit)->where('billing_quantity', $interval->quantity);
        }

        return $query->value('billing_days_in_advance');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return ArticleFactory
     */
    protected static function newFactory()
    {
        return ArticleFactory::new();
    }

    /**
     * ONE legacy-deprecation emitter for this class's shim paths (ADR-015
     * §5): every delegating public path emits the same-shaped
     * `E_USER_DEPRECATED` through here. Internal code never calls legacy
     * paths, so nothing internal emits.
     */
    private function triggerLegacyDeprecation(string $method, string $replacement): void
    {
        trigger_error(sprintf(
            '%s is deprecated since larabill 9.0.0, use %s instead — see UPGRADE-9.0.md.',
            $method,
            $replacement,
        ), E_USER_DEPRECATED);
    }
}
