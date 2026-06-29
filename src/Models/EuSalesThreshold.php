<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\FixedDecimalCast;
use AichaDigital\Lara100\ValueObjects\FixedDecimal;
use AichaDigital\Larabill\Concerns\HasUserRelation;
use AichaDigital\Larabill\Services\ModelMappingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * EuSalesThreshold Model
 *
 * Represents EU sales thresholds for companies, tracking total sales amounts and threshold exceedance.
 * Monetary amounts are FixedDecimal:2 over integer base-100 minor-unit columns
 * (AID-240): €5,000.00 is stored as 500000. breakdown_by_country is a JSON map
 * of raw integer cents per country.
 *
 * @property string $user_id
 * @property int $fiscal_year
 * @property FixedDecimal $total_amount Base-100 minor units (e.g., 500000 => €5,000.00)
 * @property FixedDecimal $threshold_amount Base-100 minor units (e.g., 1000000 => €10,000.00)
 * @property bool $threshold_exceeded
 * @property Carbon|null $exceeded_at
 * @property bool $notification_sent
 * @property array<string, int>|null $breakdown_by_country Raw integer cents per country
 * @property string $currency
 * @property Carbon|null $last_updated
 */
class EuSalesThreshold extends Model
{
    use HasUserRelation;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
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
     * Monetary amounts are FixedDecimal:2 over integer base-100 columns.
     * Example: €5,000.00 is stored as 500000, €10,000.00 as 1000000.
     */
    public function casts(): array
    {
        return [
            'total_amount'         => FixedDecimalCast::class.':2',
            'threshold_amount'     => FixedDecimalCast::class.':2',
            'threshold_exceeded'   => 'boolean',
            'notification_sent'    => 'boolean',
            'breakdown_by_country' => 'array',
            'exceeded_at'          => 'datetime',
            'last_updated'         => 'datetime',
        ];
    }

    /**
     * Accessor for breakdown_by_country: raw integer cents per country.
     *
     * @param  mixed  $value
     * @return array<string, int>
     */
    public function getBreakdownByCountryAttribute($value): array
    {
        if (empty($value)) {
            return [];
        }

        $breakdown = is_array($value) ? $value : (json_decode($value, true) ?? []);

        // Storage representation is integer base-100 minor units.
        foreach ($breakdown as $country => $amount) {
            $breakdown[$country] = (int) $amount;
        }

        return $breakdown;
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
                $model->threshold_amount = FixedDecimal::ofUnscaled(
                    (int) config('larabill.destination_vat.default_threshold', 1000000),
                    2
                );
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
            $fieldMapping = ModelMappingService::getFieldMapping('eu_sales_threshold');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = ModelMappingService::reverseMapFields($attributes, 'eu_sales_threshold');
                $model->setRawAttributes($mappedAttributes);
            }
        });

        static::updating(function ($model) {
            // Update last_updated on any change
            $model->last_updated = now();
        });

        static::retrieved(function ($model) {
            // Apply field mapping when retrieving
            $fieldMapping = ModelMappingService::getFieldMapping('eu_sales_threshold');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = ModelMappingService::mapFields($attributes, 'eu_sales_threshold');
                $model->setRawAttributes($mappedAttributes);
            }
        });
    }

    /**
     * Find threshold by company and fiscal year.
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
     * Get or create threshold for company and year.
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
                'total_amount'         => FixedDecimal::zero(2),
                'threshold_exceeded'   => false,
                'notification_sent'    => false,
                'breakdown_by_country' => [],
                'threshold_amount'     => FixedDecimal::ofUnscaled(
                    (int) config('larabill.destination_vat.default_threshold', 1000000),
                    2
                ),
                'currency'             => config('larabill.destination_vat.currency', 'EUR'),
            ]
        );
    }

    /**
     * Calculate total sales amount from the per-country breakdown.
     */
    public function calculateTotal(): FixedDecimal
    {
        $breakdown = $this->breakdown_by_country ?? [];
        $total     = 0;

        foreach ($breakdown as $amount) {
            $total += (int) $amount;
        }

        $totalAmount = FixedDecimal::ofUnscaled($total, 2);
        $this->update(['total_amount' => $totalAmount]);

        return $totalAmount;
    }

    /**
     * Check if threshold has been exceeded.
     */
    public function checkThreshold(): bool
    {
        $exceeded = $this->total_amount->compareTo($this->threshold_amount) >= 0;

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
    public function addSalesForCountry(string $countryCode, FixedDecimal $amount): self
    {
        $breakdown               = $this->breakdown_by_country ?? [];
        $currentAmount           = (int) ($breakdown[$countryCode] ?? 0);
        $breakdown[$countryCode] = $currentAmount + $amount->unscaledValue();

        $this->update([
            'breakdown_by_country' => $breakdown,
            'total_amount'         => $this->total_amount->plus($amount),
        ]);

        $this->checkThreshold();

        return $this;
    }

    /**
     * Get sales amount for a specific country.
     */
    public function getSalesForCountry(string $countryCode): FixedDecimal
    {
        $breakdown = $this->breakdown_by_country ?? [];

        return FixedDecimal::ofUnscaled((int) ($breakdown[$countryCode] ?? 0), 2);
    }

    /**
     * Get all countries with sales.
     *
     * @return array<int, string>
     */
    public function getCountriesWithSales(): array
    {
        $breakdown = $this->breakdown_by_country ?? [];

        return array_keys(array_filter($breakdown, function ($amount) {
            return (int) $amount > 0;
        }));
    }

    /**
     * Get top countries by sales amount (instance method).
     *
     * @return array<string, int> Raw integer cents per country
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
        if (! $this->threshold_amount->isPositive()) {
            return 0.0;
        }

        return (float) min(100, ($this->total_amount->toFloat() / $this->threshold_amount->toFloat()) * 100);
    }

    /**
     * Get remaining amount until threshold.
     */
    public function getRemainingThresholdAmount(): FixedDecimal
    {
        $remaining = $this->threshold_amount->minus($this->total_amount);

        return $remaining->isNegative() ? FixedDecimal::zero(2) : $remaining;
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
            'total_amount'         => FixedDecimal::zero(2),
            'threshold_exceeded'   => false,
            'exceeded_at'          => null,
            'notification_sent'    => false,
            'breakdown_by_country' => [],
        ]);

        return $this;
    }

    /**
     * Scope to get thresholds by fiscal year.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByFiscalYear(Builder $query, int $fiscalYear): Builder
    {
        return $query->where('fiscal_year', $fiscalYear);
    }

    /**
     * Scope to get thresholds that have been exceeded.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExceeded(Builder $query): Builder
    {
        return $query->where('threshold_exceeded', true);
    }

    /**
     * Scope to get thresholds by company.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByUser(Builder $query, string|int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get thresholds that need notification.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsNotification(Builder $query): Builder
    {
        return $query->where('threshold_exceeded', true)
            ->where('notification_sent', false);
    }

    /**
     * Scope to get thresholds close to being exceeded.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCloseToThreshold(Builder $query, float $percentage = 90): Builder
    {
        return $query->whereRaw('(total_amount / threshold_amount) * 100 >= ?', [$percentage]);
    }

    /**
     * Scope to get current fiscal year thresholds.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCurrentFiscalYear(Builder $query): Builder
    {
        return $query->where('fiscal_year', now()->year);
    }

    /**
     * Get threshold statistics for a company.
     *
     * @return array<string, mixed>
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
                    $breakdown[$country] += (int) $amount;
                }
            }
        }

        $totalSales = $thresholds->sum(fn (self $threshold): int => $threshold->total_amount->unscaledValue());

        return [
            'total_companies'      => $thresholds->count(),
            'exceeded_companies'   => $thresholds->where('threshold_exceeded', true)->count(),
            'total_sales_amount'   => FixedDecimal::ofUnscaled((int) $totalSales, 2),
            'breakdown_by_country' => $breakdown,
        ];
    }

    /**
     * Get default threshold amount from config (base-100 minor units).
     */
    public static function getDefaultThreshold(): int
    {
        return (int) config('larabill.destination_vat.default_threshold', 1000000);
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
    public function addSalesAmount(string $countryCode, FixedDecimal $amount): self
    {
        $breakdown               = $this->breakdown_by_country ?? [];
        $breakdown[$countryCode] = (int) ($breakdown[$countryCode] ?? 0) + $amount->unscaledValue();

        $this->update([
            'breakdown_by_country' => $breakdown,
            'total_amount'         => $this->total_amount->plus($amount),
            'last_updated'         => now(),
        ]);

        return $this;
    }

    /**
     * Add amount to total (without country breakdown).
     */
    public function addAmount(FixedDecimal $amount): self
    {
        $this->update([
            'total_amount'  => $this->total_amount->plus($amount),
            'last_updated'  => now(),
        ]);

        $this->checkThreshold();

        return $this;
    }

    /**
     * Check if threshold is exceeded.
     */
    public function isThresholdExceeded(): bool
    {
        return $this->total_amount->compareTo($this->threshold_amount) >= 0;
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
    public function getSalesAmountByCountry(string $countryCode): FixedDecimal
    {
        $breakdown = $this->breakdown_by_country ?? [];

        return FixedDecimal::ofUnscaled((int) ($breakdown[$countryCode] ?? 0), 2);
    }

    /**
     * Reset sales amounts.
     */
    public function resetSalesAmounts(): self
    {
        $this->update([
            'total_amount'         => FixedDecimal::zero(2),
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
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeThresholdExceeded(Builder $query): Builder
    {
        return $query->where('threshold_exceeded', true);
    }

    /**
     * Get companies exceeding threshold.
     *
     * @return Collection<int, EuSalesThreshold>
     */
    public static function getCompaniesExceedingThreshold(?int $fiscalYear = null): Collection
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
     *
     * @return Collection<int, EuSalesThreshold>
     */
    public static function getCompaniesNeedingNotification(?int $fiscalYear = null): Collection
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
        return ! static::where('user_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->exists();
    }

    /**
     * Get fiscal year start date.
     */
    public function getFiscalYearStartDate(): Carbon
    {
        $startDate = config('larabill.destination_vat.fiscal_year_start', '01-01');

        $date = Carbon::create($this->fiscal_year, (int) substr($startDate, 0, 2), (int) substr($startDate, 3, 2));

        if (! $date instanceof Carbon) {
            throw new \RuntimeException("Invalid fiscal-year start date for fiscal year {$this->fiscal_year}.");
        }

        return $date;
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
     *
     * @return array<int, array<string, mixed>>
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
                    $countryTotals[$country] += (int) $amount;
                }
            }
        }

        // Sort by amount descending
        arsort($countryTotals);

        // Convert to array format expected by tests (amount as FixedDecimal).
        $result = [];
        $count  = 0;
        foreach ($countryTotals as $country => $amount) {
            if ($count >= $limit) {
                break;
            }
            $result[] = [
                'country' => $country,
                'amount'  => FixedDecimal::ofUnscaled((int) $amount, 2),
            ];
            $count++;
        }

        return $result;
    }

    /**
     * Get sales growth by company.
     *
     * @return array<string, mixed>
     */
    public static function getSalesGrowthByCompany(string $companyId, int $fiscalYear): array
    {
        $current = static::where('user_id', $companyId)
            ->where('fiscal_year', $fiscalYear)
            ->first();

        $previous = static::where('user_id', $companyId)
            ->where('fiscal_year', $fiscalYear - 1)
            ->first();

        $currentAmount  = $current ? $current->total_amount : FixedDecimal::zero(2);
        $previousAmount = $previous ? $previous->total_amount : FixedDecimal::zero(2);

        $growthAmount     = $currentAmount->minus($previousAmount);
        $growthPercentage = $previousAmount->isPositive()
            ? (($currentAmount->toFloat() - $previousAmount->toFloat()) / $previousAmount->toFloat()) * 100
            : 0.0;

        return [
            'user_id'           => $companyId,
            'current_year'      => $fiscalYear,
            'current_amount'    => $currentAmount,
            'previous_year'     => $fiscalYear - 1,
            'previous_amount'   => $previousAmount,
            'growth_amount'     => $growthAmount,
            'growth_percentage' => $growthPercentage,
        ];
    }
}
