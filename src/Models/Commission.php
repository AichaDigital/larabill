<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Enums\CommissionAppliesTo;
use AichaDigital\Larabill\Enums\CommissionLevel;
use AichaDigital\Larabill\Enums\CommissionType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
 * @property CommissionLevel $level Commission level enum
 * @property int|null $article_id FK to articles (for level=product)
 * @property string|null $product_group (for level=product_group)
 * @property CommissionType $type Commission type enum
 * @property FixedDecimal $rate Percentage (20.50) or fixed amount (€20.50), base-100 cents
 * @property CommissionAppliesTo $applies_to What the commission applies to
 * @property \Illuminate\Support\Carbon $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property bool $is_active
 * @property FixedDecimal|null $min_amount Base-100 minimum amount (FixedDecimal:2)
 * @property FixedDecimal|null $max_amount Base-100 maximum amount (FixedDecimal:2)
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
        'applies_to',
        'valid_from',
        'valid_until',
        'is_active',
        'min_amount',
        'max_amount',
        'min_quantity',
        'name',
        'description',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * Uses Base100 cast from lara100 package for monetary values
     * Automatically handles conversion between decimals and base-100 integers
     * Example: 10.5% ↔ 1050, €20.50 ↔ 2050
     */
    protected function casts(): array
    {
        return [
            'level'              => CommissionLevel::class,
            'type'               => CommissionType::class,
            'applies_to'         => CommissionAppliesTo::class,
            'rate'               => FixedDecimalCast::class.':2', // 10.50% ↔ 1050 or €20.50 ↔ 2050
            'valid_from'         => 'date',
            'valid_until'        => 'date',
            'is_active'          => 'boolean',
            'min_amount'         => FixedDecimalCast::class.':2',
            'max_amount'         => FixedDecimalCast::class.':2',
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

        // Check amount limits (base in cents vs FixedDecimal:2 minor units).
        $baseCents = (int) round($baseAmount * 100);

        if ($this->min_amount && $baseCents < $this->min_amount->unscaledValue()) {
            return 0;
        }

        if ($this->max_amount && $baseCents > $this->max_amount->unscaledValue()) {
            return 0;
        }

        if ($this->type === CommissionType::PERCENTAGE) {
            return $baseAmount * ($this->rate->unscaledValue() / 100);
        }

        // Fixed amount: the stored base-100 cents value (FixedDecimal → cents).
        return (float) $this->rate->unscaledValue();
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
            $carbonDate = Carbon::parse($date);
            $afterStart = $carbonDate->greaterThanOrEqualTo($this->valid_from);
            $beforeEnd  = $this->valid_until === null || $carbonDate->lessThanOrEqualTo($this->valid_until);

            if (! ($afterStart && $beforeEnd)) {
                return false;
            }
        }

        // Check level matching
        return match ($this->level) {
            CommissionLevel::PRODUCT       => $this->article_id    === $articleId,
            CommissionLevel::PRODUCT_GROUP => $this->product_group === $productGroup,
            CommissionLevel::GLOBAL        => true,
        };
    }

    /**
     * Get priority for this commission level.
     */
    public function getPriorityAttribute(): int
    {
        return match ($this->level) {
            CommissionLevel::PRODUCT       => 3,
            CommissionLevel::PRODUCT_GROUP => 2,
            CommissionLevel::GLOBAL        => 1,
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
    public function scopeLevel($query, CommissionLevel $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope by level (alias for better readability).
     */
    public function scopeForLevel($query, CommissionLevel $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope by type.
     */
    public function scopeForType($query, CommissionType $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for article.
     */
    public function scopeForArticle($query, int $articleId)
    {
        return $query->where('level', CommissionLevel::PRODUCT)->where('article_id', $articleId);
    }

    /**
     * Scope for product group.
     */
    public function scopeForProductGroup($query, string $productGroup)
    {
        return $query->where('level', CommissionLevel::PRODUCT_GROUP)->where('product_group', $productGroup);
    }

    /**
     * Scope global commissions.
     */
    public function scopeGlobal($query)
    {
        return $query->where('level', CommissionLevel::GLOBAL);
    }

    /**
     * Scope valid for date.
     */
    public function scopeValidForDate($query, \DateTimeInterface $date)
    {
        $carbonDate = Carbon::parse($date);

        return $query->where('valid_from', '<=', $carbonDate)
            ->where(function ($q) use ($carbonDate) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $carbonDate);
            });
    }

    /**
     * Scope ordered by priority (product > product_group > global).
     * CommissionLevel: PRODUCT=2, PRODUCT_GROUP=1, GLOBAL=0
     * Order by level DESC puts PRODUCT first, then PRODUCT_GROUP, then GLOBAL
     */
    public function scopeByPriority($query)
    {
        return $query->orderByDesc('level');
    }
}
