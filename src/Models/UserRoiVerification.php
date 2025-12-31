<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Concerns\HasUserRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserRoiVerification Model
 *
 * Represents ROI (Reverse Charge Operator) verification results for users.
 * This model handles caching of ROI verifications with configurable expiration.
 *
 * @property int $id
 * @property string|int $user_id
 * @property string $vat_code
 * @property string $country_code
 * @property bool $is_roi
 * @property bool $cache_hit
 * @property string|null $company_name
 * @property string|null $company_address
 * @property \Illuminate\Support\Carbon|null $last_check
 * @property \Illuminate\Support\Carbon|null $expired_at
 * @property string|null $api_source
 * @property array<string,mixed>|null $response_data
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class UserRoiVerification extends Model
{
    use HasUserRelation;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'vat_code',
        'country_code',
        'is_roi',
        'company_name',
        'company_address',
        'last_check',
        'expired_at',
        'api_source',
        'response_data',
        'cache_hit',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_roi'        => 'boolean',
        'cache_hit'     => 'boolean',
        'last_check'    => 'datetime',
        'expired_at'    => 'datetime',
        'response_data' => 'array',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Apply field mapping when creating
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('user_roi_verification');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::reverseMapFields($attributes, 'user_roi_verification');
                $model->setRawAttributes($mappedAttributes);
            }
        });

        static::retrieved(function ($model) {
            // Apply field mapping when retrieving
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('user_roi_verification');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::mapFields($attributes, 'user_roi_verification');
                $model->setRawAttributes($mappedAttributes);
            }
        });
    }

    /**
     * Check if the cache has expired.
     */
    public function isExpired(): bool
    {
        return $this->expired_at && $this->expired_at->isPast();
    }

    /**
     * Check if the ROI verification is valid and not expired.
     */
    public function isValid(): bool
    {
        return $this->is_roi && ! $this->isExpired();
    }

    /**
     * Check if this verification is still valid (not expired).
     */
    public function isCacheValid(): bool
    {
        return ! $this->isExpired();
    }

    /**
     * Find ROI verification by user, VAT number and country code.
     */
    public static function findByUserAndVat(string $userId, string $vatNumber, string $countryCode): ?self
    {
        return static::where('user_id', $userId)
            ->where('vat_code', $vatNumber)
            ->where('country_code', $countryCode)
            ->first();
    }

    /**
     * Find valid (not expired) ROI verification.
     */
    public static function findValidByUserAndVat(string $userId, string $vatNumber, string $countryCode): ?self
    {
        return static::where('user_id', $userId)
            ->where('vat_code', $vatNumber)
            ->where('country_code', $countryCode)
            ->where('expired_at', '>', now())
            ->first();
    }

    /**
     * Scope to get only valid (not expired) ROI verifications.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where('expired_at', '>', now());
    }

    /**
     * Scope to get only expired ROI verifications.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expired_at', '<=', now());
    }

    /**
     * Scope to get ROI verifications by country.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope to get ROI verifications by user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get ROI verifications that are actually ROI.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRoi(Builder $query): Builder
    {
        return $query->where('is_roi', true);
    }

    /**
     * Get the user that owns the ROI verification.
     *
     * @return BelongsTo<\Illuminate\Foundation\Auth\User, $this>
     */
    public function user(): BelongsTo
    {
        $userModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user');

        // @phpstan-ignore-next-line return.type,argument.templateType
        return $this->belongsTo($userModel);
    }

    /**
     * Create or update ROI verification with automatic expiration.
     *
     * @param  array<string, mixed>  $data
     */
    public static function createOrUpdateRoiVerification(array $data): self
    {
        $cacheDuration = config('larabill.roi_verification.cache_duration_days', 15);
        $expiredAt     = now()->addDays($cacheDuration);

        return static::updateOrCreate(
            [
                'user_id'      => $data['user_id'],
                'vat_code'     => $data['vat_code'],
                'country_code' => $data['country_code'],
            ],
            array_merge($data, [
                'expired_at' => $expiredAt,
                'last_check' => now(),
            ])
        );
    }

    /**
     * Mark as cache hit (used when retrieving from cache).
     */
    public function markAsCacheHit(): void
    {
        $this->update(['cache_hit' => true]);
    }

    /**
     * Get the cache duration in days.
     */
    public static function getCacheDurationDays(): int
    {
        return config('larabill.roi_verification.cache_duration_days', 15);
    }

    /**
     * Check if force API check is enabled.
     */
    public static function shouldForceApiCheck(): bool
    {
        return config('larabill.roi_verification.force_api_check', false);
    }

    /**
     * Get legal retention period in days.
     */
    public static function getLegalRetentionDays(): int
    {
        return config('larabill.roi_verification.legal_retention_days', 2555); // 7 years
    }
}
