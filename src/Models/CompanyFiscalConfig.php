<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * CompanyFiscalConfig Model
 *
 * Represents fiscal configuration for companies, including
 * destination VAT settings and EU sales thresholds.
 */
class CompanyFiscalConfig extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'company_id',
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
     * The attributes that should be cast.
     */
    protected $casts = [
        'apply_destination_iva' => 'boolean',
        'auto_apply_destination' => 'boolean',
        'notification_sent' => 'boolean',
        'threshold_exceeded' => 'boolean',
        'eu_sales_threshold' => 'float',
        'current_eu_sales_amount' => 'float',
        'threshold_exceeded_at' => 'datetime',
        'custom_threshold_rules' => 'array',
    ];

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
                $model->eu_sales_threshold = config('larabill.destination_vat.default_threshold', 10000);
            }

            // Set default currency if not provided
            if (! $model->currency) {
                $model->currency = config('larabill.destination_vat.currency', 'EUR');
            }

            // Set fiscal year start if not provided
            if (! $model->fiscal_year_start) {
                $model->fiscal_year_start = config('larabill.destination_vat.fiscal_year_start', '01-01');
            }

            // Apply field mapping when creating
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('company_fiscal_config');
            if (! empty($fieldMapping)) {
                $attributes = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::reverseMapFields($attributes, 'company_fiscal_config');
                $model->setRawAttributes($mappedAttributes);
            }
        });

        static::retrieved(function ($model) {
            // Apply field mapping when retrieving
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('company_fiscal_config');
            if (! empty($fieldMapping)) {
                $attributes = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::mapFields($attributes, 'company_fiscal_config');
                $model->setRawAttributes($mappedAttributes);
            }
        });
    }

    /**
     * Find fiscal config by company and fiscal year.
     */
    public static function findByCompanyAndYear(string $companyId, ?int $fiscalYear = null): ?self
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        return static::where('company_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->first();
    }

    /**
     * Get or create fiscal config for company and year.
     */
    public static function getOrCreateForCompany(string $companyId, ?int $fiscalYear = null): self
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        return static::firstOrCreate(
            [
                'company_id' => $companyId,
                'fiscal_year' => $fiscalYear,
            ],
            [
                'eu_sales_threshold' => config('larabill.destination_vat.default_threshold', 10000),
                'currency' => config('larabill.destination_vat.currency', 'EUR'),
                'fiscal_year_start' => config('larabill.destination_vat.fiscal_year_start', '01-01'),
                'auto_apply_destination' => config('larabill.destination_vat.auto_apply_destination', true),
                'apply_destination_iva' => false,
                'current_eu_sales_amount' => 0,
            ]
        );
    }

    /**
     * Check if threshold has been exceeded.
     */
    public function checkThreshold(): bool
    {
        if ($this->current_eu_sales_amount >= $this->eu_sales_threshold) {
            if (! $this->threshold_exceeded_at) {
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
        $this->current_eu_sales_amount += $amount;
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
            'threshold_exceeded_at' => $this->threshold_exceeded_at ?: now(),
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
     */
    public function scopeByFiscalYear($query, int $fiscalYear)
    {
        return $query->where('fiscal_year', $fiscalYear);
    }

    /**
     * Scope to get configs that have exceeded threshold.
     */
    public function scopeThresholdExceeded($query)
    {
        return $query->where('threshold_exceeded_at', '!=', null);
    }

    /**
     * Scope to get configs by company.
     */
    public function scopeByCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope to get configs that apply destination VAT.
     */
    public function scopeApplyDestinationVat($query)
    {
        return $query->where('apply_destination_iva', true);
    }

    /**
     * Scope to get configs with auto apply enabled.
     */
    public function scopeAutoApplyEnabled($query)
    {
        return $query->where('auto_apply_destination', true);
    }

    /**
     * Scope to get configs that need notification.
     */
    public function scopeNeedsNotification($query)
    {
        return $query->where('threshold_exceeded_at', '!=', null)
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
        $endDate = $this->getFiscalYearEndDate();

        return $date->between($startDate, $endDate);
    }

    /**
     * Get threshold percentage (current vs threshold).
     */
    public function getThresholdPercentage(): float
    {
        if ($this->eu_sales_threshold <= 0) {
            return 0;
        }

        return min(100, ($this->current_eu_sales_amount / $this->eu_sales_threshold) * 100);
    }

    /**
     * Get remaining amount until threshold.
     */
    public function getRemainingThresholdAmount(): float
    {
        return max(0, $this->eu_sales_threshold - $this->current_eu_sales_amount);
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
     * Get default threshold from config.
     */
    public static function getDefaultThreshold(): float
    {
        return config('larabill.destination_vat.default_threshold', 10000);
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
}
