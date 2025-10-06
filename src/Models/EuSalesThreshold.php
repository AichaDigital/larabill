<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * EuSalesThreshold Model
 *
 * Represents EU sales thresholds for companies, tracking total sales amounts and threshold exceedance.
 * All monetary amounts are stored as decimal values (e.g., €12.34 => 12.34).
 *
 * @property string $company_id
 * @property int $fiscal_year
 * @property float $total_amount Monetary amount (e.g., 12.34 => €12.34)
 * @property float $threshold_amount Monetary amount (e.g., 10000.00 => €10,000.00)
 * @property bool $threshold_exceeded
 * @property Carbon|null $exceeded_at
 * @property bool $notification_sent
 * @property array|null $breakdown_by_country
 * @property string $currency
 * @property Carbon|null $last_updated
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
     * Casts for attributes.
     *
     * Uses decimal values for monetary amounts (amounts, thresholds)
     * Example: €12.34 is stored as 12.34, €10,000.00 as 10000.00
     */
    public function casts(): array
    {
        return [
            'total_amount'         => 'float', // Monetary amount: €12.34
            'threshold_amount'     => 'float', // Monetary amount: €10000.00
            'threshold_exceeded'   => 'boolean',
            'notification_sent'    => 'boolean',
            'breakdown_by_country' => 'array',
            'exceeded_at'          => 'datetime',
            'last_updated'         => 'datetime',
        ];
    }

    /**
     * Accessor for breakdown_by_country to ensure float values.
     */
    public function getBreakdownByCountryAttribute($value): array
    {
        if (empty($value)) {
            return [];
        }

        $breakdown = json_decode($value, true) ?? [];

        // Ensure all values are float
        foreach ($breakdown as $country => $amount) {
            $breakdown[$country] = (float) $amount;
        }

        return $breakdown;
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
     * Get total amount as monetary amount.
     */
    public function getTotalAmountAsAmount(): float
    {
        return (float) $this->total_amount;
    }

    /**
     * Get threshold amount as monetary amount.
     */
    public function getThresholdAmountAsAmount(): float
    {
        return (float) $this->threshold_amount;
    }

    /**
     * Set total amount from monetary amount.
     */
    public function setTotalAmountFromAmount(float $amount): self
    {
        $this->update(['total_amount' => static::amountToBase100($amount)]);

        return $this;
    }

    /**
     * Set threshold amount from monetary amount.
     */
    public function setThresholdAmountFromAmount(float $amount): self
    {
        $this->update(['threshold_amount' => static::amountToBase100($amount)]);

        return $this;
    }

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
                $attributes       = $model->getAttributes();
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
                $attributes       = $model->getAttributes();
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
                'company_id'  => $companyId,
                'fiscal_year' => $fiscalYear,
            ],
            [
                'total_amount'         => 0,
                'threshold_exceeded'   => false,
                'notification_sent'    => false,
                'breakdown_by_country' => [],
                'threshold_amount'     => config('larabill.destination_vat.default_threshold', 10000),
                'currency'             => config('larabill.destination_vat.currency', 'EUR'),
            ]
        );
    }

    /**
     * Calculate total sales amount.
     */
    public function calculateTotal(): float
    {
        $breakdown = $this->breakdown_by_country ?? [];
        $total     = 0;

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
                'exceeded_at'        => now(),
            ]);
        }

        return $exceeded;
    }

    /**
     * Add sales amount for a specific country.
     */
    public function addSalesForCountry(string $countryCode, float $amount): self
    {
        $breakdown               = $this->breakdown_by_country ?? [];
        $currentAmount           = $breakdown[$countryCode]    ?? 0;
        $breakdown[$countryCode] = $currentAmount + $amount;

        $this->update([
            'breakdown_by_country' => $breakdown,
            'total_amount'         => $this->total_amount + $amount,
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
     * Get top countries by sales amount (instance method).
     */
    public function getTopCountriesBySalesForInstance(int $limit = 10): array
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
            return 0.0;
        }

        return (float) min(100, ($this->total_amount / $this->threshold_amount) * 100);
    }

    /**
     * Get remaining amount until threshold.
     */
    public function getRemainingThresholdAmount(): float
    {
        return (float) max(0, $this->threshold_amount - $this->total_amount);
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
            'fiscal_year'          => $newFiscalYear,
            'total_amount'         => 0,
            'threshold_exceeded'   => false,
            'exceeded_at'          => null,
            'notification_sent'    => false,
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
    public static function getThresholdStatistics(int $fiscalYear): array
    {
        $thresholds = static::where('fiscal_year', $fiscalYear)->get();

        $breakdown = [];
        foreach ($thresholds as $threshold) {
            $countryData = $threshold->breakdown_by_country;
            if (is_array($countryData)) {
                foreach ($countryData as $country => $amount) {
                    if (! isset($breakdown[$country])) {
                        $breakdown[$country] = 0;
                    }
                    $breakdown[$country] += (float) $amount;
                }
            }
        }

        return [
            'total_companies'      => $thresholds->count(),
            'exceeded_companies'   => $thresholds->where('threshold_exceeded', true)->count(),
            'total_sales_amount'   => $thresholds->sum('total_amount'),
            'breakdown_by_country' => $breakdown,
        ];
    }

    /**
     * Get default threshold amount from config.
     */
    public static function getDefaultThreshold(): int
    {
        return config('larabill.destination_vat.default_threshold', 1000000); // Base 100 integer
    }

    /**
     * Get default currency from config.
     */
    public static function getDefaultCurrency(): string
    {
        return config('larabill.destination_vat.currency', 'EUR');
    }

    /**
     * Add sales amount for a specific country.
     */
    public function addSalesAmount(string $countryCode, float $amount): self
    {
        $breakdown               = $this->breakdown_by_country ?? [];
        $breakdown[$countryCode] = ($breakdown[$countryCode] ?? 0) + $amount;

        $this->update([
            'breakdown_by_country' => $breakdown,
            'total_amount'         => $this->total_amount + $amount,
            'last_updated'         => now(),
        ]);

        return $this;
    }

    /**
     * Check if threshold is exceeded.
     */
    public function isThresholdExceeded(): bool
    {
        return $this->total_amount >= $this->threshold_amount;
    }

    /**
     * Mark threshold as exceeded.
     */
    public function markThresholdExceeded(): self
    {
        $this->update([
            'threshold_exceeded' => true,
            'exceeded_at'        => now(),
        ]);

        return $this;
    }

    /**
     * Get sales amount by country.
     */
    public function getSalesAmountByCountry(string $countryCode): float
    {
        $breakdown = $this->breakdown_by_country ?? [];

        return $breakdown[$countryCode] ?? 0.0;
    }

    /**
     * Reset sales amounts.
     */
    public function resetSalesAmounts(): self
    {
        $this->update([
            'total_amount'         => 0,
            'breakdown_by_country' => [],
            'threshold_exceeded'   => false,
            'exceeded_at'          => null,
            'notification_sent'    => false,
            'last_updated'         => now(),
        ]);

        return $this;
    }

    /**
     * Scope for threshold exceeded.
     */
    public function scopeThresholdExceeded($query)
    {
        return $query->where('threshold_exceeded', true);
    }

    /**
     * Get companies exceeding threshold.
     */
    public static function getCompaniesExceedingThreshold(?int $fiscalYear = null)
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        return static::thresholdExceeded()
            ->where('fiscal_year', $fiscalYear)
            ->get();
    }

    /**
     * Get companies needing notification.
     */
    public static function getCompaniesNeedingNotification(?int $fiscalYear = null)
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        return static::thresholdExceeded()
            ->where('fiscal_year', $fiscalYear)
            ->where('notification_sent', false)
            ->get();
    }

    /**
     * Check if company needs threshold monitoring.
     */
    public static function companyNeedsThresholdMonitoring(string $companyId, ?int $fiscalYear = null): bool
    {
        if (! $fiscalYear) {
            $fiscalYear = now()->year;
        }

        // Company needs monitoring if it doesn't have a threshold record yet
        return ! static::where('company_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->exists();
    }

    /**
     * Get fiscal year start date.
     */
    public function getFiscalYearStartDate(): Carbon
    {
        $startDate = config('larabill.destination_vat.fiscal_year_start', '01-01');

        return Carbon::create($this->fiscal_year, (int) substr($startDate, 0, 2), (int) substr($startDate, 3, 2));
    }

    /**
     * Get fiscal year end date.
     */
    public function getFiscalYearEndDate(): Carbon
    {
        return $this->getFiscalYearStartDate()->copy()->addYear()->subDay();
    }

    /**
     * Check if date is within fiscal year.
     */
    public function isWithinFiscalYear(\Carbon\Carbon $date): bool
    {
        $startDate = $this->getFiscalYearStartDate();
        $endDate   = $startDate->copy()->addYear()->subDay();

        return $date->between($startDate, $endDate);
    }

    /**
     * Get top countries by sales amount.
     */
    public static function getTopCountriesBySales(int $fiscalYear, int $limit = 5): array
    {
        $thresholds = static::where('fiscal_year', $fiscalYear)->get();

        $countryTotals = [];
        foreach ($thresholds as $threshold) {
            $breakdown = $threshold->breakdown_by_country;
            if (is_array($breakdown)) {
                foreach ($breakdown as $country => $amount) {
                    if (! isset($countryTotals[$country])) {
                        $countryTotals[$country] = 0;
                    }
                    $countryTotals[$country] += (float) $amount;
                }
            }
        }

        // Sort by amount descending
        arsort($countryTotals);

        // Convert to array format expected by tests
        $result = [];
        $count  = 0;
        foreach ($countryTotals as $country => $amount) {
            if ($count >= $limit) {
                break;
            }
            $result[] = [
                'country' => $country,
                'amount'  => $amount,
            ];
            $count++;
        }

        return $result;
    }

    /**
     * Get sales growth by company.
     */
    public static function getSalesGrowthByCompany(string $companyId, int $fiscalYear): array
    {
        $current = static::where('company_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->first();

        $previous = static::where('company_id', $companyId)
            ->where('fiscal_year', $fiscalYear - 1)
            ->first();

        $currentAmount  = $current ? $current->total_amount : 0;
        $previousAmount = $previous ? $previous->total_amount : 0;

        $growthAmount     = $currentAmount - $previousAmount;
        $growthPercentage = $previousAmount > 0 ? (($currentAmount - $previousAmount) / $previousAmount) * 100 : 0;

        return [
            'company_id'        => $companyId,
            'current_year'      => $fiscalYear,
            'current_amount'    => $currentAmount,
            'previous_year'     => $fiscalYear - 1,
            'previous_amount'   => $previousAmount,
            'growth_amount'     => $growthAmount,
            'growth_percentage' => $growthPercentage,
        ];
    }
}
