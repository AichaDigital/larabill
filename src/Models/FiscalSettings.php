<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\{Builder, Model};

/**
 * FiscalSettings Model
 *
 * Represents fiscal configuration for users, including destination VAT settings and EU sales thresholds.
 * All monetary amounts are stored as base-100 integers (e.g., €12.34 => 1234).
 *
 * @property int $id
 * @property string|int $user_id
 * @property bool $is_oss
 * @property bool $is_roi
 * @property bool $apply_destination_iva
 * @property int $eu_sales_threshold Monetary amount as integer (e.g., 1000000 => €10,000.00)
 * @property int $current_eu_sales_amount Monetary amount as integer (e.g., 1234 => €12.34)
 * @property Carbon|null $threshold_exceeded_at
 * @property bool $threshold_exceeded
 * @property int $fiscal_year
 * @property bool $auto_apply_destination
 * @property bool $notification_sent
 * @property string $fiscal_year_start
 * @property string $currency
 * @property string|null $threshold_notification_email
 * @property array<string, mixed>|null $custom_threshold_rules
 */
class FiscalSettings extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'fiscal_settings';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'is_oss',
        'is_roi',
        'apply_destination_iva',
        'eu_sales_threshold',
        'current_eu_sales_amount',
        'threshold_exceeded_at',
        'threshold_exceeded',
        'fiscal_year',
        'auto_apply_destination',
        'notification_sent',
        'fiscal_year_start',
        'currency',
        'threshold_notification_email',
        'custom_threshold_rules',
    ];

    /**
     * Casts for attributes.
     *
     * Uses Base100 cast from lara100 package for monetary values
     * Automatically handles conversion between decimals and base-100 integers
     * Example: €10,000.00 ↔ 1000000 (stored as integer, accessed as decimal)
     */
    public function casts(): array
    {
        return [
            'is_oss'                  => 'boolean',
            'is_roi'                  => 'boolean',
            'apply_destination_iva'   => 'boolean',
            'auto_apply_destination'  => 'boolean',
            'notification_sent'       => 'boolean',
            'threshold_exceeded'      => 'boolean',
            'eu_sales_threshold'      => Base100::class, // €10,000.00 ↔ 1000000
            'current_eu_sales_amount' => Base100::class, // €12.34 ↔ 1234
            'threshold_exceeded_at'   => 'datetime',
            'custom_threshold_rules'  => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Set default fiscal year if not provided
            if (! $model->fiscal_year) {
                $model->fiscal_year = now()->year;
            }

            // Set default threshold if not provided
            if (! $model->eu_sales_threshold) {
                // Set from human amount; mutator will convert to base-100
                $model->eu_sales_threshold = config('larabill.destination_vat.default_threshold', 10000.0);
            }

            // Set default currency if not provided
            if (! $model->currency) {
                $model->currency = config('larabill.destination_vat.currency', 'EUR');
            }

            // Set fiscal year start if not provided
            if (! $model->fiscal_year_start) {
                $model->fiscal_year_start = config('larabill.destination_vat.fiscal_year_start', '01-01');
            }

            // Set default boolean values if not provided
            if ($model->auto_apply_destination === null) {
                $model->auto_apply_destination = config('larabill.destination_vat.auto_apply_destination', true);
            }

            if ($model->apply_destination_iva === null) {
                $model->apply_destination_iva = false;
            }

            if ($model->notification_sent === null) {
                $model->notification_sent = false;
            }

            if ($model->current_eu_sales_amount === null) {
                $model->current_eu_sales_amount = 0;
            }

            // Apply field mapping when creating
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('fiscal_settings');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::reverseMapFields($attributes, 'fiscal_settings');
                $model->setRawAttributes($mappedAttributes);
            }
        });

        static::retrieved(function ($model) {
            // Apply field mapping when retrieving
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('fiscal_settings');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::mapFields($attributes, 'fiscal_settings');
                $model->setRawAttributes($mappedAttributes);
            }
        });
    }

    /**
     * Find fiscal config by user and fiscal year.
     */
    public static function findByUserAndYear(string|int $userId, ?int $fiscalYear = null): ?self
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        return static::where('user_id', $userId)
            ->where('fiscal_year', $fiscalYear)
            ->first();
    }

    /**
     * Get or create fiscal config for the user (singleton pattern).
     */
    public static function getOrCreateForUser(string|int $userId, ?int $fiscalYear = null): self
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        return static::firstOrCreate(
            [
                'user_id'     => $userId,
                'fiscal_year' => $fiscalYear,
            ],
            [
                'is_oss'                  => false,
                'is_roi'                  => false,
                // Human amount; mutator converts to base-100
                'eu_sales_threshold'      => config('larabill.destination_vat.default_threshold', 10000.0),
                'currency'                => config('larabill.destination_vat.currency', 'EUR'),
                'fiscal_year_start'       => config('larabill.destination_vat.fiscal_year_start', '01-01'),
                'auto_apply_destination'  => config('larabill.destination_vat.auto_apply_destination', true),
                'apply_destination_iva'   => false,
                'current_eu_sales_amount' => 0.0,
                'threshold_exceeded'      => false,
            ]
        );
    }

    /**
     * Get the user that owns the fiscal settings.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        $userModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel);
    }

    /**
     * Check if threshold has been exceeded.
     * If auto_apply_destination is enabled, automatically enables apply_destination_iva.
     */
    public function checkThreshold(): bool
    {
        if ($this->current_eu_sales_amount >= $this->eu_sales_threshold) {
            if (! $this->threshold_exceeded_at) {
                $updateData = [
                    'threshold_exceeded_at' => now(),
                    'threshold_exceeded'    => true,
                ];

                // Auto-apply destination VAT if enabled
                if ($this->auto_apply_destination && ! $this->apply_destination_iva) {
                    $updateData['apply_destination_iva'] = true;
                }

                $this->update($updateData);
            }

            return true;
        }

        return false;
    }

    /**
     * Increment EU sales amount and check threshold.
     *
     * @param  float  $amount  The monetary amount to add (can be negative for refunds)
     */
    public function incrementEuSales(float $amount): self
    {
        // Base100 cast handles conversion automatically
        $newAmount                     = $this->current_eu_sales_amount + $amount;
        $this->current_eu_sales_amount = $newAmount;
        $this->save();

        $this->checkThreshold();

        return $this;
    }

    /**
     * Update EU sales amount and check threshold (alias).
     *
     * @param  float  $amount  The monetary amount to add (e.g., 12.34)
     */
    public function updateEuSales(float $amount): self
    {
        return $this->incrementEuSales($amount);
    }

    /**
     * Reset EU sales amount for new fiscal year.
     */
    public function resetEuSales(): self
    {
        $this->update([
            'current_eu_sales_amount' => 0.0,
            'threshold_exceeded_at'   => null,
            'notification_sent'       => false,
        ]);

        return $this;
    }

    /**
     * Check if destination VAT should be applied.
     */
    public function shouldApplyDestinationVat(): bool
    {
        // If already registered in OSS, always apply destination VAT
        if ($this->is_oss) {
            return true;
        }

        return $this->apply_destination_iva ||
               ($this->auto_apply_destination && $this->checkThreshold());
    }

    /**
     * Enable destination VAT application.
     */
    public function enableDestinationVat(): self
    {
        $this->update([
            'apply_destination_iva' => true,
        ]);

        return $this;
    }

    /**
     * Disable destination VAT application.
     */
    public function disableDestinationVat(): self
    {
        $this->update([
            'apply_destination_iva' => false,
        ]);

        return $this;
    }

    /**
     * Mark notification as sent.
     */
    public function markNotificationSent(): self
    {
        $this->update(['notification_sent' => true]);

        return $this;
    }

    /**
     * Scope to get configs by fiscal year.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByFiscalYear(Builder $query, int $fiscalYear): Builder
    {
        return $query->where('fiscal_year', $fiscalYear);
    }

    /**
     * Scope to get configs that have exceeded threshold.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeThresholdExceeded(Builder $query): Builder
    {
        return $query->whereColumn('current_eu_sales_amount', '>=', 'eu_sales_threshold');
    }

    /**
     * Scope to get configs by user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByUser(Builder $query, string|int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get configs that apply destination VAT.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApplyDestinationVat(Builder $query): Builder
    {
        return $query->where('apply_destination_iva', true);
    }

    /**
     * Scope to get configs with auto apply enabled.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAutoApplyEnabled(Builder $query): Builder
    {
        return $query->where('auto_apply_destination', true);
    }

    /**
     * Scope to get configs that need notification.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsNotification(Builder $query): Builder
    {
        return $query->whereColumn('current_eu_sales_amount', '>=', 'eu_sales_threshold')
            ->where('notification_sent', false);
    }

    /**
     * Get fiscal year start date.
     */
    public function getFiscalYearStartDate(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->fiscal_year.'-'.$this->fiscal_year_start);
    }

    /**
     * Get fiscal year end date.
     */
    public function getFiscalYearEndDate(): Carbon
    {
        return $this->getFiscalYearStartDate()->addYear()->subDay();
    }

    /**
     * Check if current date is within fiscal year.
     */
    public function isWithinFiscalYear(?Carbon $date = null): bool
    {
        if (! $date) {
            $date = now();
        }

        $startDate = $this->getFiscalYearStartDate();
        $endDate   = $this->getFiscalYearEndDate();

        return $date->between($startDate, $endDate);
    }

    /**
     * Get threshold percentage (current vs threshold).
     */
    public function getThresholdPercentage(): float
    {
        if ($this->eu_sales_threshold <= 0) {
            return 0.0;
        }

        return min(100.0, ($this->current_eu_sales_amount / $this->eu_sales_threshold) * 100.0);
    }

    /**
     * Get remaining amount until threshold.
     */
    public function getRemainingThresholdAmount(): float
    {
        // Base100 cast handles conversion automatically - both values are already floats
        return max(0.0, $this->eu_sales_threshold - $this->current_eu_sales_amount);
    }

    /**
     * Get custom threshold rules for specific countries or products.
     *
     * @return array<string, mixed>|null
     */
    public function getCustomThresholdRule(string $key): ?array
    {
        return $this->custom_threshold_rules[$key] ?? null;
    }

    /**
     * Set custom threshold rule.
     *
     * @param  array<string, mixed>  $rule
     */
    public function setCustomThresholdRule(string $key, array $rule): self
    {
        $rules       = $this->custom_threshold_rules ?? [];
        $rules[$key] = $rule;
        $this->update(['custom_threshold_rules' => $rules]);

        return $this;
    }

    /**
     * Get default threshold from config.
     */
    public static function getDefaultThreshold(): float
    {
        // Returns decimal amount (Base100 cast will handle DB conversion)
        return (float) config('larabill.destination_vat.default_threshold', 10000.0);
    }

    /**
     * Get default currency from config.
     */
    public static function getDefaultCurrency(): string
    {
        return config('larabill.destination_vat.currency', 'EUR');
    }

    /**
     * Get default fiscal year start from config.
     */
    public static function getDefaultFiscalYearStart(): string
    {
        return config('larabill.destination_vat.fiscal_year_start', '01-01');
    }

    /**
     * Enable OSS registration.
     */
    public function enableOSS(): self
    {
        $this->update(['is_oss' => true]);

        return $this;
    }

    /**
     * Disable OSS registration.
     */
    public function disableOSS(): self
    {
        $this->update(['is_oss' => false]);

        return $this;
    }

    /**
     * Enable ROI status.
     */
    public function enableROI(): self
    {
        $this->update(['is_roi' => true]);

        return $this;
    }

    /**
     * Disable ROI status.
     */
    public function disableROI(): self
    {
        $this->update(['is_roi' => false]);

        return $this;
    }

    /**
     * Reset configuration for new fiscal year.
     */
    public function resetForNewYear(int $newYear): self
    {
        $this->update([
            'fiscal_year'             => $newYear,
            'current_eu_sales_amount' => 0.0,
            'threshold_exceeded'      => false,
            'threshold_exceeded_at'   => null,
            'notification_sent'       => false,
        ]);

        return $this;
    }

    /**
     * Check if notification is needed.
     */
    public function shouldSendNotification(): bool
    {
        return $this->threshold_exceeded && ! $this->notification_sent;
    }
}
