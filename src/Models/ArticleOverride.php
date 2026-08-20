<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Database\Factories\ArticleOverrideFactory;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\OverlappingArticleOverrideException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article Override Model
 *
 * @property int $id
 * @property int|string $customer_id
 * @property int $article_id
 * @property FixedDecimal|null $custom_price
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Article $article
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class ArticleOverride extends Model
{
    /** @use HasFactory<ArticleOverrideFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'article_overrides';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'customer_id',
        'article_id',
        'custom_price',
        'reason',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'custom_price' => FixedDecimalCast::class.':2',
        'valid_from'   => 'date',
        'valid_to'     => 'date',
        'is_active'    => 'boolean',
    ];

    /**
     * Default attribute values.
     *
     * `is_active` is declared here so the model agrees with its own schema,
     * which carries `->default(true)`. Without it, a write that omits the key
     * leaves the attribute unset, the boolean cast reads it back as `null`, and
     * the saving hook below — which only had to guard against inactive rows —
     * returned before running the overlap query; the database then stamped
     * `is_active = 1` and persisted a second active override in silence
     * (AID-974).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Model event hooks.
     *
     * The `saving` hook is the safety net that rejects an active override
     * that would overlap another active one for the same customer/article
     * pair (AID-974). It is a net, not the primary guarantee — callers that
     * care about concurrency still go through the dedicated write path.
     */
    protected static function booted(): void
    {
        static::saving(function (self $override): void {
            // Only an explicit `false` is a deactivation. The default above
            // covers the omitted key; this comparison covers an explicit
            // `is_active => null`, which the cast leaves as null and a truthy
            // test would read as "inactive" — opening the same hole from the
            // other side.
            if ($override->is_active === false) {
                return;
            }

            $conflicts = self::query()
                ->overlapping(
                    $override->customer_id,
                    $override->article_id,
                    $override->valid_from,
                    $override->valid_to,
                )
                // Self-exclusion on update: without it the row detects itself
                // as a conflict and no override could ever be edited.
                ->when($override->exists, fn (Builder $query) => $query->whereKeyNot($override->getKey()))
                ->get();

            if ($conflicts->isNotEmpty()) {
                throw OverlappingArticleOverrideException::forRange($override, $conflicts);
            }
        });
    }

    /**
     * Get the article for this override.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Get the customer for this override.
     * Note: Relationship is agnostic, uses configured user model.
     *
     * @return BelongsTo<Model, $this>
     */
    public function customer(): BelongsTo
    {
        /** @var class-string<Model> $userModel */
        $userModel = config('larabill.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'customer_id');
    }

    /**
     * Scope to filter only active overrides.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to filter overrides valid at a specific date.
     *
     * @param  Builder<static>  $query
     */
    public function scopeValidAt(Builder $query, Carbon $date): void
    {
        // The columns are `date`: comparing them against an instant carrying a
        // time of day expires an override during its own last day, and the two
        // engines do not even agree on the edge (AID-974 D2bis). Normalised
        // here, in a single place.
        $day = $date->toDateString();

        $query->where(function ($q) use ($day) {
            $q->whereNull('valid_from')
                ->orWhereDate('valid_from', '<=', $day);
        })
            ->where(function ($q) use ($day) {
                $q->whereNull('valid_to')
                    ->orWhereDate('valid_to', '>=', $day);
            });
    }

    /**
     * Scope to filter by customer.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForCustomer(Builder $query, int|string $customerId): void
    {
        $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by article.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForArticle(Builder $query, int $articleId): void
    {
        $query->where('article_id', $articleId);
    }

    /**
     * Overlap condition — the SINGLE definition shared by the saving hook and
     * ArticleOverrideService (AID-974 D1). NULL is an open end on both sides.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOverlapping(
        Builder $query,
        int|string $customerId,
        int $articleId,
        ?Carbon $validFrom,
        ?Carbon $validTo,
    ): void {
        $from = $validFrom?->toDateString();
        $to   = $validTo?->toDateString();

        $query->where('customer_id', $customerId)
            ->where('article_id', $articleId)
            ->where('is_active', true)
            ->when($to !== null, fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner->whereNull('valid_from')->orWhereDate('valid_from', '<=', $to)
            ))
            ->when($from !== null, fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from)
            ));
    }

    /**
     * Check if this override is valid at a specific date.
     */
    public function isValidAt(Carbon $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // Same day-grain, inclusive semantics as scopeValidAt() (AID-974
        // D2bis): valid_from/valid_to are `date` columns, so comparing the
        // full instant (with its time-of-day) against them would expire an
        // override during its own last day. Compare the calendar day only.
        $day = $date->toDateString();

        if ($this->valid_from && $day < $this->valid_from->toDateString()) {
            return false;
        }

        if ($this->valid_to && $day > $this->valid_to->toDateString()) {
            return false;
        }

        return true;
    }

    /**
     * Check if this override is currently valid.
     */
    public function isCurrentlyValid(): bool
    {
        return $this->isValidAt(now());
    }

    /**
     * Check if this override has expired.
     *
     * Day grain, like isValidAt() and scopeValidAt() (AID-974 D2bis), which the
     * spec requires to agree for the same data. Comparing the full instant
     * against a `date` column reported an override as expired from the first
     * second of its own last day, so a row the resolver was still applying came
     * back both currently valid AND expired.
     */
    public function isExpired(): bool
    {
        if (! $this->valid_to) {
            return false;
        }

        return now()->toDateString() > $this->valid_to->toDateString();
    }

    /**
     * Get days until expiration.
     * Returns null if no expiration date or already expired.
     *
     * Whole calendar days, on the same grain as the window: 0 on the last day
     * in force — which is a row still being applied, not a null.
     */
    public function daysUntilExpiration(): ?int
    {
        if (! $this->valid_to) {
            return null;
        }

        if ($this->isExpired()) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->valid_to->copy()->startOfDay());
    }

    /**
     * Get the discount amount compared to base price.
     * Note: Requires a billing frequency to determine the base price.
     */
    public function getDiscountAmount(BillingFrequency $frequency): float
    {
        $basePrice = $this->article->getPriceFor($frequency) ?? 0.0;

        // getPriceFor() reads the raw base-100 cents column; custom_price is a
        // FixedDecimal — read its cents to stay in the same base-100 space.
        return $basePrice - ($this->custom_price?->unscaledValue() ?? 0);
    }

    /**
     * Get the discount percentage compared to base price.
     * Note: Requires a billing frequency to determine the base price.
     */
    public function getDiscountPercentage(BillingFrequency $frequency): int
    {
        $basePrice = $this->article->getPriceFor($frequency);

        if ($basePrice === null || $basePrice === 0.0 || $basePrice === 0) {
            return 0;
        }

        return (int) ((($basePrice - ($this->custom_price?->unscaledValue() ?? 0)) / $basePrice) * 100);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ArticleOverrideFactory
    {
        return ArticleOverrideFactory::new();
    }
}
