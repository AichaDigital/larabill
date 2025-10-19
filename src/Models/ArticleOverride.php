<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleOverride extends Model
{
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
        'custom_price' => Base100::class,
        'valid_from'   => 'date',
        'valid_to'     => 'date',
        'is_active'    => 'boolean',
    ];

    /**
     * Get the article for this override.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Get the customer for this override.
     * Note: Relationship is agnostic, uses configured user model.
     */
    public function customer(): BelongsTo
    {
        $userModel = config('larabill.user_model', 'App\\Models\\User');

        return $this->belongsTo($userModel, 'customer_id');
    }

    /**
     * Scope to filter only active overrides.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to filter overrides valid at a specific date.
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
     * Scope to filter by customer.
     */
    public function scopeForCustomer(Builder $query, int $customerId): void
    {
        $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by article.
     */
    public function scopeForArticle(Builder $query, int $articleId): void
    {
        $query->where('article_id', $articleId);
    }

    /**
     * Check if this override is valid at a specific date.
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
     * Check if this override is currently valid.
     */
    public function isCurrentlyValid(): bool
    {
        return $this->isValidAt(now());
    }

    /**
     * Check if this override has expired.
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
     * Get the discount amount compared to base price.
     */
    public function getDiscountAmount(): int
    {
        return $this->article->base_price - $this->custom_price;
    }

    /**
     * Get the discount percentage compared to base price.
     */
    public function getDiscountPercentage(): int
    {
        if ($this->article->base_price === 0) {
            return 0;
        }

        return (int) ((($this->article->base_price - $this->custom_price) / $this->article->base_price) * 100);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \AichaDigital\Larabill\Database\Factories\ArticleOverrideFactory::new();
    }
}
