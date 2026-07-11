<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Database\Factories\ArticleServiceStatusFactory;
use AichaDigital\Larabill\Enums\BillingFrequency;
use AichaDigital\Larabill\Enums\CancellationType;
use AichaDigital\Larabill\Enums\ServiceStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Article Service Status Model
 *
 * @property int $id
 * @property int|string $customer_id
 * @property int $article_id
 * @property string|null $instance_identifier
 * @property string|null $instance_name
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $next_billing_date
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property ServiceStatus $status
 * @property CancellationType|null $cancellation_type
 * @property \Illuminate\Support\Carbon|null $cancellation_requested_at
 * @property \Illuminate\Support\Carbon|null $cancellation_effective_at
 * @property bool $refund_unused
 * @property BillingFrequency $billing_frequency
 * @property FixedDecimal|null $effective_price
 * @property int|null $current_override_id
 * @property string|null $external_reference
 * @property array<string, mixed>|null $instance_data
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Article $article
 * @property-read ArticleOverride|null $currentOverride
 *
 * @api Supported public surface (AID-413; see docs/api-surface.md).
 */
class ArticleServiceStatus extends Model
{
    /** @use HasFactory<ArticleServiceStatusFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'article_service_status';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'customer_id',
        'article_id',
        'instance_identifier',
        'instance_name',
        'started_at',
        'next_billing_date',
        'expires_at',
        'status',
        'cancellation_type',
        'cancellation_requested_at',
        'cancellation_effective_at',
        'refund_unused',
        'billing_frequency',
        'effective_price',
        'current_override_id',
        'external_reference',
        'instance_data',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'status'                    => ServiceStatus::class,
        'cancellation_type'         => CancellationType::class,
        'started_at'                => 'date',
        'next_billing_date'         => 'date',
        'expires_at'                => 'date',
        'cancellation_requested_at' => 'date',
        'cancellation_effective_at' => 'date',
        'refund_unused'             => 'boolean',
        'billing_frequency'         => BillingFrequency::class,
        'effective_price'           => FixedDecimalCast::class.':2',
        'instance_data'             => 'array',
        'metadata'                  => 'array',
    ];

    /**
     * Get the article for this service status.
     *
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Get the customer for this service.
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
     * Get the current active price override.
     *
     * @return BelongsTo<ArticleOverride, $this>
     */
    public function currentOverride(): BelongsTo
    {
        return $this->belongsTo(ArticleOverride::class, 'current_override_id');
    }

    /**
     * Scope to filter only active services.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ServiceStatus::ACTIVE);
    }

    /**
     * Scope to filter services pending billing.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopePendingBilling(Builder $query): void
    {
        $query->where('status', ServiceStatus::ACTIVE)
            ->whereNotNull('next_billing_date');
    }

    /**
     * Scope to filter services due for billing.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeDueForBilling(Builder $query, ?Carbon $date = null): void
    {
        $date = $date ?? now();

        $query->where('status', ServiceStatus::ACTIVE)
            ->whereNotNull('next_billing_date')
            ->whereDate('next_billing_date', '<=', $date);
    }

    /**
     * Scope to filter by customer.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeForCustomer(Builder $query, int|string $customerId): void
    {
        $query->where('customer_id', $customerId);
    }

    /**
     * Scope to filter by article.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeForArticle(Builder $query, int $articleId): void
    {
        $query->where('article_id', $articleId);
    }

    /**
     * Scope to filter by status.
     *
     * @param  Builder<ArticleServiceStatus>  $query
     */
    public function scopeWithStatus(Builder $query, ServiceStatus $status): void
    {
        $query->where('status', $status);
    }

    /**
     * Activate the service.
     */
    public function activate(): void
    {
        $this->update([
            'status' => ServiceStatus::ACTIVE,
        ]);
    }

    /**
     * Suspend the service.
     */
    public function suspend(string $reason): void
    {
        $this->update([
            'status'   => ServiceStatus::SUSPENDED,
            'metadata' => array_merge($this->metadata ?? [], [
                'suspension' => [
                    'suspended_at' => now()->toIso8601String(),
                    'reason'       => $reason,
                ],
            ]),
        ]);
    }

    /**
     * Cancel the service.
     */
    public function cancel(CancellationType $type, ?string $reason = null): void
    {
        $effectiveDate = match ($type) {
            CancellationType::IMMEDIATE     => now(),
            CancellationType::END_OF_PERIOD => $this->next_billing_date ?? now(),
            CancellationType::NOTICE_PERIOD => now()->addDays(
                $this->article->metadata['service']['cancellation_policy']['notice_days'] ?? 30
            ),
        };

        $userId = Auth::check() ? Auth::id() : null;

        $this->update([
            'status'                    => $type === CancellationType::IMMEDIATE ? ServiceStatus::CANCELLED : $this->status,
            'cancellation_type'         => $type,
            'cancellation_requested_at' => now(),
            'cancellation_effective_at' => $effectiveDate,
            'refund_unused'             => $type->requiresRefund(),
            'next_billing_date'         => $type === CancellationType::IMMEDIATE ? null : $this->next_billing_date,
            'metadata'                  => array_merge($this->metadata ?? [], [
                'cancellation' => [
                    'reason'       => $reason,
                    'requested_by' => $userId,
                    'requested_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
    }

    /**
     * Expire the service.
     */
    public function expire(): void
    {
        $this->update([
            'status'            => ServiceStatus::EXPIRED,
            'next_billing_date' => null,
        ]);
    }

    /**
     * Calculate the next billing date based on service billing frequency.
     */
    public function calculateNextBillingDate(): Carbon
    {
        $currentDate = $this->next_billing_date ?? $this->started_at;

        if ($currentDate === null) {
            throw new \RuntimeException("Service {$this->id} has neither next_billing_date nor started_at to compute the next billing date.");
        }

        return $this->billing_frequency->addToDate($currentDate);
    }

    /**
     * Update the effective price (checks for active overrides).
     */
    public function updateEffectivePrice(): void
    {
        $override = $this->article->getActiveOverrideFor($this->customer_id);

        // Get price from ArticlePrice for current billing frequency
        $basePrice = $this->article->getPriceFor($this->billing_frequency);

        $this->update([
            'effective_price'     => $override->custom_price
                ?? ($basePrice !== null ? FixedDecimal::ofUnscaled((int) $basePrice, 2) : null),
            'current_override_id' => $override?->id,
        ]);
    }

    /**
     * Check if this service should be billed.
     */
    public function shouldBeBilled(): bool
    {
        return $this->status->canBill()
            && $this->next_billing_date !== null
            && $this->next_billing_date->isPast();
    }

    /**
     * Get days until next billing.
     */
    public function getDaysUntilNextBilling(): int
    {
        if (! $this->next_billing_date) {
            return 0;
        }

        return (int) max(0, now()->diffInDays($this->next_billing_date, false));
    }

    /**
     * Get instance data by key with optional default.
     */
    public function getInstanceData(string $key, mixed $default = null): mixed
    {
        return data_get($this->instance_data, $key, $default);
    }

    /**
     * Set instance data by key.
     */
    public function setInstanceData(string $key, mixed $value): void
    {
        $data = $this->instance_data ?? [];
        data_set($data, $key, $value);

        $this->update(['instance_data' => $data]);
    }

    /**
     * Get metadata by key with optional default.
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata, $key, $default);
    }

    /**
     * Set metadata by key.
     */
    public function setMetadata(string $key, mixed $value): void
    {
        $data = $this->metadata ?? [];
        data_set($data, $key, $value);

        $this->update(['metadata' => $data]);
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return ArticleServiceStatusFactory
     */
    protected static function newFactory()
    {
        return ArticleServiceStatusFactory::new();
    }
}
