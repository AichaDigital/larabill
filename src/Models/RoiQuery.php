<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RoiQuery Model
 *
 * Represents ROI queries for legal protection and audit purposes.
 * All ROI verification queries are logged here for compliance.
 */
class RoiQuery extends Model
{
    /**
     * Query types.
     */
    public const QUERY_TYPE_API = 'api';

    public const QUERY_TYPE_CACHE = 'cache';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'vat_number',
        'country_code',
        'query_type',
        'api_source',
        'response_data',
        'queried_at',
        'cache_used',
        'legal_retention_until',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'cache_used'            => 'boolean',
        'queried_at'            => 'datetime',
        'legal_retention_until' => 'datetime',
        'response_data'         => 'array',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Set legal retention period (7 years by default)
            if (! $model->legal_retention_until) {
                $model->legal_retention_until = now()->addDays(self::getLegalRetentionDays());
            }

            // Set queried_at if not provided
            if (! $model->queried_at) {
                $model->queried_at = now();
            }

            // Apply field mapping when creating
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('roi_query');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::reverseMapFields($attributes, 'roi_query');
                $model->setRawAttributes($mappedAttributes);
            }
        });

        static::retrieved(function ($model) {
            // Apply field mapping when retrieving
            $fieldMapping = \AichaDigital\Larabill\Services\ModelMappingService::getFieldMapping('roi_query');
            if (! empty($fieldMapping)) {
                $attributes       = $model->getAttributes();
                $mappedAttributes = \AichaDigital\Larabill\Services\ModelMappingService::mapFields($attributes, 'roi_query');
                $model->setRawAttributes($mappedAttributes);
            }
        });
    }

    /**
     * Find queries by user and date range.
     */
    public static function findByUserAndDateRange(string $userId, Carbon $startDate, Carbon $endDate)
    {
        return static::where('user_id', $userId)
            ->whereBetween('queried_at', [$startDate, $endDate])
            ->orderBy('queried_at', 'desc');
    }

    /**
     * Find queries by user and VAT number.
     */
    public static function findByUserAndVat(string $userId, string $vatNumber, string $countryCode)
    {
        return static::where('user_id', $userId)
            ->where('vat_number', $vatNumber)
            ->where('country_code', $countryCode)
            ->orderBy('queried_at', 'desc');
    }

    /**
     * Scope to get queries by date range.
     */
    public function scopeByDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('queried_at', [$startDate, $endDate]);
    }

    /**
     * Scope to get queries by type.
     */
    public function scopeByQueryType($query, string $queryType)
    {
        return $query->where('query_type', $queryType);
    }

    /**
     * Scope to get queries within legal retention period.
     */
    public function scopeLegalRetention($query)
    {
        return $query->where('legal_retention_until', '>', now());
    }

    /**
     * Scope to get queries by user.
     */
    public function scopeByUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get queries by country.
     */
    public function scopeByCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope to get API queries.
     */
    public function scopeApiQueries($query)
    {
        return $query->where('query_type', self::QUERY_TYPE_API);
    }

    /**
     * Scope to get cache queries.
     */
    public function scopeCacheQueries($query)
    {
        return $query->where('query_type', self::QUERY_TYPE_CACHE);
    }

    /**
     * Scope to get queries that used cache.
     */
    public function scopeUsedCache($query)
    {
        return $query->where('cache_used', true);
    }

    /**
     * Get the user that owns the ROI query.
     */
    public function user(): BelongsTo
    {
        $userModel = \AichaDigital\Larabill\Services\ModelMappingService::getModelClass('user');

        return $this->belongsTo($userModel);
    }

    /**
     * Create a new ROI query record.
     */
    public static function createQuery(array $data): self
    {
        return static::create(array_merge($data, [
            'queried_at'            => now(),
            'legal_retention_until' => now()->addDays(self::getLegalRetentionDays()),
        ]));
    }

    /**
     * Create an API query record.
     */
    public static function createApiQuery(string $userId, string $vatNumber, string $countryCode, string $apiSource, array $responseData): self
    {
        return static::createQuery([
            'user_id'       => $userId,
            'vat_number'    => $vatNumber,
            'country_code'  => $countryCode,
            'query_type'    => self::QUERY_TYPE_API,
            'api_source'    => $apiSource,
            'response_data' => $responseData,
            'cache_used'    => false,
        ]);
    }

    /**
     * Create a cache query record.
     */
    public static function createCacheQuery(string $userId, string $vatNumber, string $countryCode, array $responseData): self
    {
        return static::createQuery([
            'user_id'       => $userId,
            'vat_number'    => $vatNumber,
            'country_code'  => $countryCode,
            'query_type'    => self::QUERY_TYPE_CACHE,
            'api_source'    => 'cache',
            'response_data' => $responseData,
            'cache_used'    => true,
        ]);
    }

    /**
     * Check if the query is within legal retention period.
     */
    public function isWithinLegalRetention(): bool
    {
        return $this->legal_retention_until && $this->legal_retention_until->isFuture();
    }

    /**
     * Get queries that are outside legal retention period.
     */
    public static function getExpiredLegalRetention()
    {
        return static::where('legal_retention_until', '<=', now())->get();
    }

    /**
     * Clean up expired legal retention queries.
     */
    public static function cleanupExpiredLegalRetention(): int
    {
        return static::where('legal_retention_until', '<=', now())->delete();
    }

    /**
     * Get legal retention period in days.
     */
    public static function getLegalRetentionDays(): int
    {
        return config('larabill.roi_verification.legal_retention_days', 2555); // 7 years
    }

    /**
     * Get query statistics for a user.
     */
    public static function getQueryStatistics(string $userId, ?Carbon $startDate = null, ?Carbon $endDate = null): array
    {
        $query = static::where('user_id', $userId);

        if ($startDate && $endDate) {
            $query->whereBetween('queried_at', [$startDate, $endDate]);
        }

        $total        = $query->count();
        $apiQueries   = $query->clone()->where('query_type', self::QUERY_TYPE_API)->count();
        $cacheQueries = $query->clone()->where('query_type', self::QUERY_TYPE_CACHE)->count();

        return [
            'total'           => $total,
            'api_queries'     => $apiQueries,
            'cache_queries'   => $cacheQueries,
            'cache_hit_ratio' => $total > 0 ? round(($cacheQueries / $total) * 100, 2) : 0,
        ];
    }
}
