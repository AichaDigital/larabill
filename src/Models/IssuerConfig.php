<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Lara100\Casts\Base100;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * IssuerConfig Model
 *
 * Singleton configuration for the sole invoice issuer (e.g., AichaDigital SL)
 * Only one record exists (ID=1)
 *
 * @property int $id Always 1 (singleton)
 * @property int|null $current_tax_profile_id FK to issuer_tax_profiles
 * @property bool $is_roi_registered
 * @property bool $is_oss_registered
 * @property float $eu_sales_threshold Base-100 converted to decimal
 * @property float $current_eu_sales Base-100 converted to decimal
 * @property int $fiscal_year
 * @property \Illuminate\Support\Carbon $fiscal_year_start
 * @property \Illuminate\Support\Carbon $fiscal_year_end
 * @property bool $threshold_exceeded
 * @property \Illuminate\Support\Carbon|null $threshold_exceeded_at
 * @property bool $threshold_notification_sent
 * @property array|null $fiscal_settings
 * @property array|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class IssuerConfig extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'issuer_config';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'current_tax_profile_id',
        'is_roi_registered',
        'is_oss_registered',
        'eu_sales_threshold',
        'current_eu_sales',
        'fiscal_year',
        'fiscal_year_start',
        'fiscal_year_end',
        'threshold_exceeded',
        'threshold_exceeded_at',
        'threshold_notification_sent',
        'fiscal_settings',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_roi_registered'           => 'boolean',
            'is_oss_registered'           => 'boolean',
            'eu_sales_threshold'          => Base100::class,
            'current_eu_sales'            => Base100::class,
            'fiscal_year'                 => 'integer',
            'fiscal_year_start'           => 'date',
            'fiscal_year_end'             => 'date',
            'threshold_exceeded'          => 'boolean',
            'threshold_exceeded_at'       => 'datetime',
            'threshold_notification_sent' => 'boolean',
            'fiscal_settings'             => 'array',
            'metadata'                    => 'array',
        ];
    }

    /**
     * Get the singleton instance (ID=1).
     */
    public static function current(): self
    {
        return static::findOrFail(1);
    }

    /**
     * Get the current tax profile of the issuer.
     */
    public function currentTaxProfile(): HasOne
    {
        return $this->hasOne(IssuerTaxProfile::class, 'id', 'current_tax_profile_id');
    }

    /**
     * Update EU sales and check threshold.
     */
    public function addEuSales(float $amount): void
    {
        $this->current_eu_sales += $amount;

        if (! $this->threshold_exceeded && $this->current_eu_sales >= $this->eu_sales_threshold) {
            $this->threshold_exceeded    = true;
            $this->threshold_exceeded_at = now();
        }

        $this->save();
    }

    /**
     * Reset EU sales for new fiscal year.
     */
    public function resetEuSales(int $newFiscalYear): void
    {
        $this->current_eu_sales            = 0;
        $this->threshold_exceeded          = false;
        $this->threshold_exceeded_at       = null;
        $this->threshold_notification_sent = false;
        $this->fiscal_year                 = $newFiscalYear;
        $this->save();
    }

    /**
     * Get remaining threshold amount.
     */
    public function getRemainingThresholdAttribute(): float
    {
        $remaining = $this->eu_sales_threshold - $this->current_eu_sales;

        return max(0, $remaining);
    }

    /**
     * Get threshold percentage used.
     */
    public function getThresholdPercentageAttribute(): float
    {
        if ($this->eu_sales_threshold == 0) {
            return 0;
        }

        return min(100, ($this->current_eu_sales / $this->eu_sales_threshold) * 100);
    }

    /**
     * Check if we need to send threshold notification.
     */
    public function needsThresholdNotification(): bool
    {
        return $this->threshold_exceeded && ! $this->threshold_notification_sent;
    }

    /**
     * Mark notification as sent.
     */
    public function markNotificationSent(): void
    {
        $this->threshold_notification_sent = true;
        $this->save();
    }
}
