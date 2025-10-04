<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Company Configuration Model
 *
 * Represents the fiscal configuration for the company using the software.
 * This is a singleton model - only one record should exist.
 *
 * @property bool $is_oss Whether the company is registered in OSS (One Stop Shop)
 * @property bool $is_roi Whether the company is a Reverse Charge Operator (ROI)
 * @property int $eu_sales_threshold Base-100 integer (e.g., 1000000 => €10,000.00)
 * @property int $current_eu_sales_amount Base-100 integer (e.g., 1234 => €12.34)
 * @property Carbon|null $threshold_exceeded_at
 * @property bool $threshold_exceeded
 * @property int $fiscal_year
 * @property bool $auto_apply_destination
 * @property bool $notification_sent
 * @property string $fiscal_year_start
 * @property string $currency
 * @property string|null $threshold_notification_email
 * @property array|null $custom_threshold_rules
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CompanyConfig extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'company_config';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'is_oss',
        'is_roi',
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
     */
    public function casts(): array
    {
        return [
            'is_oss' => 'boolean',
            'is_roi' => 'boolean',
            'auto_apply_destination' => 'boolean',
            'notification_sent' => 'boolean',
            'threshold_exceeded' => 'boolean',
            'eu_sales_threshold' => 'integer', // Base 100: €10,000.00 = 1000000
            'current_eu_sales_amount' => 'integer', // Base 100: €12.34 = 1234
            'threshold_exceeded_at' => 'datetime',
            'custom_threshold_rules' => 'array',
        ];
    }

    /**
     * Get the current company configuration (singleton pattern).
     */
    public static function current(): self
    {
        $config = static::first();
        
        if (!$config) {
            $config = static::createDefault();
        }
        
        return $config;
    }

    /**
     * Create default company configuration.
     */
    public static function createDefault(): self
    {
        return static::create([
            'is_oss' => false,
            'is_roi' => false,
            'eu_sales_threshold' => static::amountToBase100(10000.0), // €10,000 default
            'current_eu_sales_amount' => 0,
            'threshold_exceeded' => false,
            'fiscal_year' => now()->year,
            'auto_apply_destination' => true,
            'notification_sent' => false,
            'fiscal_year_start' => '01-01',
            'currency' => 'EUR',
        ]);
    }

    /**
     * Convert monetary amount to base 100 integer.
     */
    public static function amountToBase100(float $amount): int
    {
        return (int) ($amount * 100);
    }

    /**
     * Convert base 100 integer to monetary amount.
     */
    public static function base100ToAmount(int $base100): float
    {
        return $base100 / 100.0;
    }

    /**
     * Accessor for eu_sales_threshold to return as float.
     */
    public function getEuSalesThresholdAttribute($value): float
    {
        return $value === null ? 0.0 : static::base100ToAmount($value);
    }

    /**
     * Accessor for current_eu_sales_amount to return as float.
     */
    public function getCurrentEuSalesAmountAttribute($value): float
    {
        return $value === null ? 0.0 : static::base100ToAmount($value);
    }

    /**
     * Mutator for eu_sales_threshold to store as base 100 integer.
     */
    public function setEuSalesThresholdAttribute($value): void
    {
        $this->attributes['eu_sales_threshold'] = $value === null ? 0 : static::amountToBase100($value);
    }

    /**
     * Mutator for current_eu_sales_amount to store as base 100 integer.
     */
    public function setCurrentEuSalesAmountAttribute($value): void
    {
        $this->attributes['current_eu_sales_amount'] = $value === null ? 0 : static::amountToBase100($value);
    }

    /**
     * Check if threshold has been exceeded.
     */
    public function checkThreshold(): bool
    {
        if ($this->current_eu_sales_amount >= $this->eu_sales_threshold) {
            if (!$this->threshold_exceeded_at) {
                $this->update(['threshold_exceeded_at' => now()]);
            }
            return true;
        }
        return false;
    }

    /**
     * Update EU sales amount and check threshold.
     */
    public function updateEuSales(float $amount): self
    {
        $amountInBase100 = static::amountToBase100($amount);
        $this->current_eu_sales_amount = $this->current_eu_sales_amount + $amount;
        $this->save();

        $this->checkThreshold();

        return $this;
    }

    /**
     * Reset EU sales amount for new fiscal year.
     */
    public function resetEuSales(): self
    {
        $this->update([
            'current_eu_sales_amount' => 0,
            'threshold_exceeded_at' => null,
            'notification_sent' => false,
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

        // If threshold exceeded and auto-apply is enabled, apply destination VAT
        return $this->auto_apply_destination && $this->checkThreshold();
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
     * Mark notification as sent.
     */
    public function markNotificationSent(): self
    {
        $this->update(['notification_sent' => true]);
        return $this;
    }

    /**
     * Get fiscal year start date.
     */
    public function getFiscalYearStartDate(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->fiscal_year . '-' . $this->fiscal_year_start);
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
        if (!$date) {
            $date = now();
        }

        $startDate = $this->getFiscalYearStartDate();
        $endDate = $this->getFiscalYearEndDate();

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
        $remaining = max(0, $this->eu_sales_threshold - $this->current_eu_sales_amount);
        return static::base100ToAmount($remaining);
    }

    /**
     * Check if company needs threshold notification.
     */
    public function needsNotification(): bool
    {
        return $this->threshold_exceeded && !$this->notification_sent;
    }

    /**
     * Get custom threshold rules for specific countries or products.
     */
    public function getCustomThresholdRule(string $key): ?array
    {
        return $this->custom_threshold_rules[$key] ?? null;
    }

    /**
     * Set custom threshold rule.
     */
    public function setCustomThresholdRule(string $key, array $rule): self
    {
        $rules = $this->custom_threshold_rules ?? [];
        $rules[$key] = $rule;
        $this->update(['custom_threshold_rules' => $rules]);

        return $this;
    }

    /**
     * Reset for new fiscal year.
     */
    public function resetForNewYear(int $newYear): self
    {
        $this->update([
            'fiscal_year' => $newYear,
            'current_eu_sales_amount' => 0,
            'threshold_exceeded' => false,
            'threshold_exceeded_at' => null,
            'notification_sent' => false,
        ]);

        return $this;
    }
}
