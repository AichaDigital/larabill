<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Models;

use AichaDigital\Larabill\Concerns\HasUserRelation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RoiQuery Model
 *
 * Represents ROI queries for legal protection and audit purposes.
 * All ROI verification queries are logged here for compliance.
 *
 * @property int $id
 * @property string $user_id
 * @property string $vat_code
 * @property string $country_code
 * @property string $query_type
 * @property string|null $api_source
 * @property array<string, mixed>|null $response_data
 * @property \Carbon\Carbon $queried_at
 * @property bool $cache_used
 * @property \Carbon\Carbon $legal_retention_until
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class RoiQuery extends Model
{
    use HasUserRelation;

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
        'vat_code',
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
     *
     * @return Builder<RoiQuery>
     */
    public static function findByUserAndDateRange(string $userId, Carbon $startDate, Carbon $endDate): Builder
    {
        /** @var Builder<RoiQuery> $query */
        $query = static::where('user_id', $userId)
            ->whereBetween('queried_at', [$startDate, $endDate])
            ->orderBy('queried_at', 'desc');

        return $query;
    }

    /**
     * Find queries by user and VAT number.
     *
     * @return Builder<RoiQuery>
     */
    public static function findByUserAndVat(string $userId, string $vatNumber, string $countryCode): Builder
    {
        /** @var Builder<RoiQuery> $query */
        $query = static::where('user_id', $userId)
            ->where('vat_code', $vatNumber)
            ->where('country_code', $countryCode)
            ->orderBy('queried_at', 'desc');

        return $query;
    }

    /**
     * Scope to get queries by date range.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByDateRange(Builder $query, Carbon $startDate, Carbon $endDate): Builder
    {
        return $query->whereBetween('queried_at', [$startDate, $endDate]);
    }

    /**
     * Scope to get queries by type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByQueryType(Builder $query, string $queryType): Builder
    {
        return $query->where('query_type', $queryType);
    }

    /**
     * Scope to get queries within legal retention period.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLegalRetention(Builder $query): Builder
    {
        return $query->where('legal_retention_until', '>', now());
    }

    /**
     * Scope to get queries by user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get queries by country.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByCountry(Builder $query, string $countryCode): Builder
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * Scope to get API queries.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApiQueries(Builder $query): Builder
    {
        return $query->where('query_type', self::QUERY_TYPE_API);
    }

    /**
     * Scope to get cache queries.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCacheQueries(Builder $query): Builder
    {
        return $query->where('query_type', self::QUERY_TYPE_CACHE);
    }

    /**
     * Scope to get queries that used cache.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUsedCache(Builder $query): Builder
    {
        return $query->where('cache_used', true);
    }

    /**
     * Get the user that owns the ROI query.
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
     * Create a new ROI query record.
     *
     * @param  array<string, mixed>  $data
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
     *
     * @param  array<string, mixed>  $responseData
     */
    public static function createApiQuery(string $userId, string $vatNumber, string $countryCode, string $apiSource, array $responseData): self
    {
        return static::createQuery([
            'user_id'       => $userId,
            'vat_code'      => $vatNumber,
            'country_code'  => $countryCode,
            'query_type'    => self::QUERY_TYPE_API,
            'api_source'    => $apiSource,
            'response_data' => $responseData,
            'cache_used'    => false,
        ]);
    }

    /**
     * Create a cache query record.
     *
     * @param  array<string, mixed>  $responseData
     */
    public static function createCacheQuery(string $userId, string $vatNumber, string $countryCode, array $responseData): self
    {
        return static::createQuery([
            'user_id'       => $userId,
            'vat_code'      => $vatNumber,
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
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RoiQuery>
     */
    public static function getExpiredLegalRetention(): \Illuminate\Database\Eloquent\Collection
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
     *
     * @return array<string, mixed>
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
