<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EuSalesThreshold Model
 *
 * Represents EU sales thresholds for companies,
 * tracking total sales amounts and threshold exceedance.
 */
class EuSalesThreshold extends Model
{
    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'company_id',
        'fiscal_year',
        'total_amount',
        'threshold_exceeded',
        'exceeded_at',
        'notification_sent',
        'breakdown_by_country',
        'currency',
        'threshold_amount',
        'last_updated',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'total_amount' => 'float',
        'threshold_exceeded' => 'boolean',
        'notification_sent' => 'boolean',
        'breakdown_by_country' => 'array',
        'threshold_amount' => 'float',
        'exceeded_at' => 'datetime',
        'last_updated' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Set default threshold amount if not provided
            if (! $model->threshold_amount) {
                $model->threshold_amount = config('larabill.destination_vat.default_threshold', 10000);
            }

            // Set default currency if not provided
            if (! $model->currency) {
                $model->currency = config('larabill.destination_vat.currency', 'EUR');
            }

            // Set last_updated if not provided
            if (! $model->last_updated) {
                $model->last_updated = now();
            }

            // Apply field mapping when creating
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('eu_sales_threshold');
            if (! empty($fieldMapping)) {
                $attributes = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::reverseMapFields($attributes, 'eu_sales_threshold');
                $model->setRawAttributes($mappedAttributes);
            }
        });

        static::updating(function ($model) {
            // Update last_updated on any change
            $model->last_updated = now();
        });

        static::retrieved(function ($model) {
            // Apply field mapping when retrieving
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('eu_sales_threshold');
            if (! empty($fieldMapping)) {
                $attributes = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::mapFields($attributes, 'eu_sales_threshold');
                $model->setRawAttributes($mappedAttributes);
            }
        });
    }

    /**
     * Find threshold by company and fiscal year.
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
     * Get or create threshold for company and year.
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
                'total_amount' => 0,
                'threshold_exceeded' => false,
                'notification_sent' => false,
                'breakdown_by_country' => [],
                'threshold_amount' => config('larabill.destination_vat.default_threshold', 10000),
                'currency' => config('larabill.destination_vat.currency', 'EUR'),
            ]
        );
    }

    /**
     * Calculate total sales amount.
     */
    public function calculateTotal(): float
    {
        $breakdown = $this->breakdown_by_country ?? [];
        $total = 0;

        foreach ($breakdown as $country => $amount) {
            $total += (float) $amount;
        }

        $this->update(['total_amount' => $total]);

        return $total;
    }

    /**
     * Check if threshold has been exceeded.
     */
    public function checkThreshold(): bool
    {
        $exceeded = $this->total_amount >= $this->threshold_amount;

        if ($exceeded && ! $this->threshold_exceeded) {
            $this->update([
                'threshold_exceeded' => true,
                'exceeded_at' => now(),
            ]);
        }

        return $exceeded;
    }

    /**
     * Add sales amount for a specific country.
     */
    public function addSalesForCountry(string $countryCode, float $amount): self
    {
        $breakdown = $this->breakdown_by_country ?? [];
        $currentAmount = $breakdown[$countryCode] ?? 0;
        $breakdown[$countryCode] = $currentAmount + $amount;

        $this->update([
            'breakdown_by_country' => $breakdown,
            'total_amount' => $this->total_amount + $amount,
        ]);

        $this->checkThreshold();

        return $this;
    }

    /**
     * Get sales amount for a specific country.
     */
    public function getSalesForCountry(string $countryCode): float
    {
        $breakdown = $this->breakdown_by_country ?? [];

        return (float) ($breakdown[$countryCode] ?? 0);
    }

    /**
     * Get all countries with sales.
     */
    public function getCountriesWithSales(): array
    {
        $breakdown = $this->breakdown_by_country ?? [];

        return array_keys(array_filter($breakdown, function ($amount) {
            return $amount > 0;
        }));
    }

    /**
     * Get top countries by sales amount.
     */
    public function getTopCountriesBySales(int $limit = 10): array
    {
        $breakdown = $this->breakdown_by_country ?? [];

        arsort($breakdown);

        return array_slice($breakdown, 0, $limit, true);
    }

    /**
     * Get threshold percentage (current vs threshold).
     */
    public function getThresholdPercentage(): float
    {
        if ($this->threshold_amount <= 0) {
            return 0;
        }

        return min(100, ($this->total_amount / $this->threshold_amount) * 100);
    }

    /**
     * Get remaining amount until threshold.
     */
    public function getRemainingThresholdAmount(): float
    {
        return max(0, $this->threshold_amount - $this->total_amount);
    }

    /**
     * Check if threshold is close to being exceeded (within 10%).
     */
    public function isCloseToThreshold(float $percentage = 90): bool
    {
        return $this->getThresholdPercentage() >= $percentage;
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
     * Reset threshold for new fiscal year.
     */
    public function resetForNewYear(int $newFiscalYear): self
    {
        $this->update([
            'fiscal_year' => $newFiscalYear,
            'total_amount' => 0,
            'threshold_exceeded' => false,
            'exceeded_at' => null,
            'notification_sent' => false,
            'breakdown_by_country' => [],
        ]);

        return $this;
    }

    /**
     * Scope to get thresholds by fiscal year.
     */
    public function scopeByFiscalYear($query, int $fiscalYear)
    {
        return $query->where('fiscal_year', $fiscalYear);
    }

    /**
     * Scope to get thresholds that have been exceeded.
     */
    public function scopeExceeded($query)
    {
        return $query->where('threshold_exceeded', true);
    }

    /**
     * Scope to get thresholds by company.
     */
    public function scopeByCompany($query, string $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope to get thresholds that need notification.
     */
    public function scopeNeedsNotification($query)
    {
        return $query->where('threshold_exceeded', true)
            ->where('notification_sent', false);
    }

    /**
     * Scope to get thresholds close to being exceeded.
     */
    public function scopeCloseToThreshold($query, float $percentage = 90)
    {
        return $query->whereRaw('(total_amount / threshold_amount) * 100 >= ?', [$percentage]);
    }

    /**
     * Scope to get current fiscal year thresholds.
     */
    public function scopeCurrentFiscalYear($query)
    {
        return $query->where('fiscal_year', now()->year);
    }

    /**
     * Get threshold statistics for a company.
     */
    public static function getThresholdStatistics(string $companyId, ?int $fiscalYear = null): array
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        $threshold = static::findByCompanyAndYear($companyId, $fiscalYear);

        if (! $threshold) {
            return [
                'total_amount' => 0,
                'threshold_amount' => config('larabill.destination_vat.default_threshold', 10000),
                'threshold_percentage' => 0,
                'remaining_amount' => config('larabill.destination_vat.default_threshold', 10000),
                'exceeded' => false,
                'countries_count' => 0,
                'top_countries' => [],
            ];
        }

        return [
            'total_amount' => $threshold->total_amount,
            'threshold_amount' => $threshold->threshold_amount,
            'threshold_percentage' => $threshold->getThresholdPercentage(),
            'remaining_amount' => $threshold->getRemainingThresholdAmount(),
            'exceeded' => $threshold->threshold_exceeded,
            'countries_count' => count($threshold->getCountriesWithSales()),
            'top_countries' => $threshold->getTopCountriesBySales(5),
        ];
    }

    /**
     * Get default threshold amount from config.
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
}
