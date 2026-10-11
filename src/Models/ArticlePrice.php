<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Database\Factories\ArticlePriceFactory;
use AichaDigital\Larabill\Exceptions\OverlappingArticlePriceException;
use AichaDigital\Larabill\ValueObjects\BillingInterval;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article Price Model
 *
 * Represents a price for an article at a specific billing cadence — the
 * ADR-015 interval domain (unit + quantity) since the AID-952 re-key; a NULL
 * cadence is a one-time price, which is real persisted state.
 * Allows the same article to have different prices based on billing cycle
 * (e.g., 1 month: €29, 3 months: €79, 1 year: €290).
 *
 * @property int $id
 * @property int $article_id
 * @property string|null $billing_unit 'month'|'year'; NULL = one-time (ADR-015)
 * @property int|null $billing_quantity 1-12 (months) / 1-10 (years); NULL = one-time
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
        'billing_unit',
        'billing_quantity',
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
        'billing_quantity'        => 'integer',
        'price'                   => FixedDecimalCast::class.':2',
        'billing_days_in_advance' => 'integer',
        'valid_from'              => 'date',
        'valid_to'                => 'date',
        'is_active'               => 'boolean',
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
                    $price->interval(),
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
     * This price's cadence as the ADR-015 value object. NULL for a one-time
     * price — null cadence is a real state, not an absent one.
     */
    public function interval(): ?BillingInterval
    {
        return $this->billing_unit === null
            ? null
            : new BillingInterval($this->billing_unit, (int) $this->billing_quantity);
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
     * Day-granular on purpose (AID-1592): `valid_from`/`valid_to` are DATE
     * casts, so a price valid until today stays valid THROUGH the whole
     * last day — comparing against `now()` with a time component dropped it
     * at the first second of that day (pre-existing 6.x defect). The
     * parameterised sibling `scopeValidAt()` takes its `$date` as-is.
     *
     * @param  Builder<static>  $query
     */
    public function scopeCurrentlyValid(Builder $query): void
    {
        $today = Carbon::today();

        $query->where('is_active', true)
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
     * Scope to the ACTIVE rows of one (article, cadence) whose validity range
     * intersects the candidate range. NULL is an open end on both sides, and a
     * NULL `$interval` matches the NULL cadence (one-time) exactly — a NULL
     * cadence never overlaps a month/year cadence.
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
        ?BillingInterval $interval,
        ?Carbon $validFrom,
        ?Carbon $validTo,
    ): void {
        $query->where('article_id', $articleId)
            ->where('is_active', true);

        if ($interval === null) {
            $query->whereNull('billing_unit')->whereNull('billing_quantity');
        } else {
            $query->where('billing_unit', $interval->unit)
                ->where('billing_quantity', $interval->quantity);
        }

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
     * Scope to filter by billing cadence. NULL matches the one-time (NULL
     * cadence) prices exactly.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForInterval(Builder $query, ?BillingInterval $interval): void
    {
        if ($interval === null) {
            $query->whereNull('billing_unit')->whereNull('billing_quantity');

            return;
        }

        $query->where('billing_unit', $interval->unit)
            ->where('billing_quantity', $interval->quantity);
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
     * Useful for displaying "equivalent to €X/month" on longer cadences.
     * A one-time price (NULL cadence) has no monthly equivalent.
     */
    public function getMonthlyEquivalent(): ?float
    {
        if ($this->billing_unit === null) {
            return null; // one-time (NULL cadence) has no monthly equivalent
        }

        $months = $this->billing_unit === 'month'
            ? $this->billing_quantity
            : $this->billing_quantity * 12;

        return $this->price->unscaledValue() / $months;
    }

    /**
     * Calculate yearly equivalent price for comparison.
     */
    public function getYearlyEquivalent(): ?float
    {
        $interval = $this->interval();

        if ($interval === null) {
            return null; // one-time (NULL cadence) has no yearly equivalent
        }

        $days = $interval->approximateDays();

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
