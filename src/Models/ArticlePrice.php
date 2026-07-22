<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Database\Factories\ArticlePriceFactory;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article Price Model
 *
 * Represents a price for an article at a specific billing frequency.
 * Allows the same article to have different prices based on billing cycle
 * (e.g., monthly: €29, quarterly: €79, yearly: €290).
 *
 * @property int $id
 * @property int $article_id
 * @property BillingFrequency $billing_frequency
 * @property FixedDecimal $price
 * @property int|null $billing_days_in_advance
 * @property \Illuminate\Support\Carbon|null $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Article $article
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class ArticlePrice extends Model
{
    /** @use HasFactory<ArticlePriceFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'article_prices';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'article_id',
        'billing_frequency',
        'price',
        'billing_days_in_advance',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'billing_frequency'        => BillingFrequency::class,
        'price'                    => FixedDecimalCast::class.':2',
        'billing_days_in_advance'  => 'integer',
        'valid_from'               => 'date',
        'valid_to'                 => 'date',
        'is_active'                => 'boolean',
    ];

    /**
     * Enforce the non-overlap invariant on every Eloquent write (AID-601).
     *
     * Safety net, not a guarantee: without a transaction two processes can
     * validate and write concurrently. The guaranteed path is
     * ArticlePriceService, which locks the parent article first.
     *
     * Only active rows are checked — an inactive row is disabled history and
     * cannot violate the invariant — which also covers reactivation
     * (is_active false→true), as real an overlap path as any create.
     *
     * Note: this runs on every save of an active row, and is_active defaults to
     * true, so a bulk seeder pays one extra query per price. Deliberate.
     */
    protected static function booted(): void
    {
        static::saving(function (self $price): void {
            if (! $price->is_active) {
                return;
            }

            $conflicts = self::query()
                ->overlapping(
                    $price->article_id,
                    $price->billing_frequency,
                    $price->valid_from,
                    $price->valid_to,
                )
                // Explicit self-exclusion on update. Not a bug fix:
                // whereKeyNot(null) would NOT emit `id != NULL` — the query
                // builder rewrites a null value with `!=` into `IS NOT NULL`
                // (Query/Builder.php) — but relying on that rewrite is
                // unreadable, so the condition is spelled out.
                ->when($price->exists, fn (Builder $query) => $query->whereKeyNot($price->getKey()))
                ->get();

            if ($conflicts->isNotEmpty()) {
                throw OverlappingArticlePriceException::forCandidate($price, $conflicts);
            }
        });
    }

    /**
     * Get the article for this price.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Scope to filter only active prices.
     *
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to filter prices valid at a specific date.
     *
     * @param  Builder<static>  $query
     */
    public function scopeValidAt(Builder $query, Carbon $date): void
    {
        $query->where(function ($q) use ($date) {
            $q->whereNull('valid_from')
                ->orWhere('valid_from', '<=', $date);
        })
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $date);
            });
    }

    /**
     * Scope to filter currently valid prices.
     *
     * @param  Builder<static>  $query
     */
    public function scopeCurrentlyValid(Builder $query): void
    {
        $query->where('is_active', true)
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
     * Scope to the ACTIVE rows of one (article, frequency) whose validity range
     * intersects the candidate range. NULL is an open end on both sides.
     *
     * Single source of truth for the non-overlap invariant (AID-601): used by
     * the saving hook and by ArticlePriceService. Deliberately pure — it does
     * NOT exclude the candidate's own row; that is the caller's business, and
     * keeping it out lets the diagnose command reuse the same condition.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOverlapping(
        Builder $query,
        int $articleId,
        BillingFrequency $frequency,
        ?Carbon $validFrom,
        ?Carbon $validTo,
    ): void {
        $query->where('article_id', $articleId)
            ->where('billing_frequency', $frequency)
            ->where('is_active', true);

        // candidate.from <= existing.to  (either side NULL ⇒ always true)
        if ($validFrom !== null) {
            $query->where(function (Builder $q) use ($validFrom) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $validFrom);
            });
        }

        // existing.from <= candidate.to  (either side NULL ⇒ always true)
        if ($validTo !== null) {
            $query->where(function (Builder $q) use ($validTo) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $validTo);
            });
        }
    }

    /**
     * Scope to filter by billing frequency.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForFrequency(Builder $query, BillingFrequency $frequency): void
    {
        $query->where('billing_frequency', $frequency);
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
     * Check if this price is valid at a specific date.
     */
    public function isValidAt(Carbon $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from && $date->isBefore($this->valid_from)) {
            return false;
        }

        if ($this->valid_to && $date->isAfter($this->valid_to)) {
            return false;
        }

        return true;
    }

    /**
     * Check if this price is currently valid.
     */
    public function isCurrentlyValid(): bool
    {
        return $this->isValidAt(now());
    }

    /**
     * Check if this price has expired.
     */
    public function isExpired(): bool
    {
        if (! $this->valid_to) {
            return false;
        }

        return now()->isAfter($this->valid_to);
    }

    /**
     * Get days until expiration.
     * Returns null if no expiration date or already expired.
     */
    public function daysUntilExpiration(): ?int
    {
        if (! $this->valid_to) {
            return null;
        }

        if ($this->isExpired()) {
            return null;
        }

        return (int) now()->diffInDays($this->valid_to);
    }

    /**
     * Calculate monthly equivalent price for comparison.
     * Useful for displaying "equivalent to €X/month" on longer frequencies.
     */
    public function getMonthlyEquivalent(): ?float
    {
        $months = $this->billing_frequency->months();

        if ($months === null) {
            // For weekly/biweekly, calculate based on approximate 4.33 weeks/month
            $days = $this->billing_frequency->days();
            if ($days === null) {
                return null; // ONE_TIME has no monthly equivalent
            }

            // Convert to monthly: price * (30 / days). Display approximation in
            // base-100 cents; read the unscaled cents and keep the float ratio math.
            return $this->price->unscaledValue() * (30 / $days);
        }

        return $this->price->unscaledValue() / $months;
    }

    /**
     * Calculate yearly equivalent price for comparison.
     */
    public function getYearlyEquivalent(): ?float
    {
        $days = $this->billing_frequency->approximateDays();

        if ($days === null) {
            return null; // ONE_TIME has no yearly equivalent
        }

        return $this->price->unscaledValue() * (365 / $days);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ArticlePriceFactory
    {
        return ArticlePriceFactory::new();
    }
}
