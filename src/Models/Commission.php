<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Commission Model
 *
 * Multi-level commission structure:
 * - Global: Applies to all sales
 * - Product Group: Applies to a group of products
 * - Product: Applies to a specific article
 *
 * Priority: product > product_group > global
 *
 * @property int $id
 * @property string $level global|product_group|product
 * @property int|null $article_id FK to articles (for level=product)
 * @property string|null $product_group (for level=product_group)
 * @property string $type percentage|fixed
 * @property float $rate Percentage (20.5000) or fixed amount (20.50)
 * @property int|null $rate_base100 Base-100 for calculations
 * @property string $applies_to taxable_amount|total_amount
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property bool $is_active
 * @property int|null $min_amount_base100 Base-100 minimum amount
 * @property int|null $max_amount_base100 Base-100 maximum amount
 * @property int|null $min_quantity Minimum quantity
 * @property string $name
 * @property string|null $description
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Commission extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'commissions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'level',
        'article_id',
        'product_group',
        'type',
        'rate',
        'rate_base100',
        'applies_to',
        'valid_from',
        'valid_until',
        'is_active',
        'min_amount_base100',
        'max_amount_base100',
        'min_quantity',
        'name',
        'description',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'rate'               => 'decimal:4',
            'rate_base100'       => 'integer',
            'valid_from'         => 'date',
            'valid_until'        => 'date',
            'is_active'          => 'boolean',
            'min_amount_base100' => 'integer',
            'max_amount_base100' => 'integer',
            'min_quantity'       => 'integer',
            'metadata'           => 'array',
        ];
    }

    /**
     * Get the article (if level=product).
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Calculate commission amount.
     *
     * @param  float  $baseAmount  Amount to calculate on (taxable or total)
     * @param  int  $quantity  Quantity of items
     * @return float Commission amount
     */
    public function calculateAmount(float $baseAmount, int $quantity = 1): float
    {
        // Check minimum quantity
        if ($this->min_quantity && $quantity < $this->min_quantity) {
            return 0;
        }

        // Check amount limits
        if ($this->min_amount_base100 && $baseAmount * 100 < $this->min_amount_base100) {
            return 0;
        }

        if ($this->max_amount_base100 && $baseAmount * 100 > $this->max_amount_base100) {
            return 0;
        }

        if ($this->type === 'percentage') {
            return $baseAmount * ($this->rate / 100);
        }

        // Fixed amount
        return (float) $this->rate;
    }

    /**
     * Check if this commission applies to given parameters.
     */
    public function appliesTo(?int $articleId = null, ?string $productGroup = null, ?\DateTimeInterface $date = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // Check validity period
        if ($date) {
            $carbonDate = \Carbon\Carbon::parse($date);
            $afterStart = $carbonDate->greaterThanOrEqualTo($this->valid_from);
            $beforeEnd  = $this->valid_until === null || $carbonDate->lessThanOrEqualTo($this->valid_until);

            if (! ($afterStart && $beforeEnd)) {
                return false;
            }
        }

        // Check level matching
        return match ($this->level) {
            'product'       => $this->article_id          === $articleId,
            'product_group' => $this->product_group       === $productGroup,
            'global'        => true,
            default         => false,
        };
    }

    /**
     * Get priority for this commission level.
     */
    public function getPriorityAttribute(): int
    {
        return match ($this->level) {
            'product'       => 3,
            'product_group' => 2,
            'global'        => 1,
            default         => 0,
        };
    }

    /**
     * Check if commission is currently valid.
     */
    public function isCurrentlyValid(): bool
    {
        $now = now();

        // Must be active
        if (! $this->is_active) {
            return false;
        }

        // Check valid_from
        if ($this->valid_from && $now->lessThan($this->valid_from)) {
            return false;
        }

        // Check valid_until (null means no end date)
        if ($this->valid_until && $now->greaterThan($this->valid_until)) {
            return false;
        }

        return true;
    }

    /**
     * Scope active commissions.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by level.
     */
    public function scopeLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope by level (alias for better readability).
     */
    public function scopeForLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope by type.
     */
    public function scopeForType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for article.
     */
    public function scopeForArticle($query, int $articleId)
    {
        return $query->where('level', 'product')->where('article_id', $articleId);
    }

    /**
     * Scope for product group.
     */
    public function scopeForProductGroup($query, string $productGroup)
    {
        return $query->where('level', 'product_group')->where('product_group', $productGroup);
    }

    /**
     * Scope global commissions.
     */
    public function scopeGlobal($query)
    {
        return $query->where('level', 'global');
    }

    /**
     * Scope valid for date.
     */
    public function scopeValidForDate($query, \DateTimeInterface $date)
    {
        $carbonDate = \Carbon\Carbon::parse($date);

        return $query->where('valid_from', '<=', $carbonDate)
            ->where(function ($q) use ($carbonDate) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $carbonDate);
            });
    }

    /**
     * Scope ordered by priority (product > product_group > global).
     */
    public function scopeByPriority($query)
    {
        return $query->orderByRaw("FIELD(level, 'product', 'product_group', 'global')");
    }
}
