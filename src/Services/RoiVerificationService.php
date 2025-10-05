<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use AichaDigital\Larabill\Models\{RoiQuery, UserRoiVerification};
use Illuminate\Support\Facades\Log;

/**
 * ROI Verification Service
 *
 * Handles ROI (Reverse Charge Operator) verification with cache support
 * and legal compliance logging.
 */
class RoiVerificationService
{
    private CacheService $cacheService;

    private VatVerificationService $vatVerificationService;

    /**
     * Constructor.
     */
    public function __construct(?CacheService $cacheService = null, ?VatVerificationService $vatVerificationService = null)
    {
        $this->cacheService           = $cacheService           ?? app(CacheService::class);
        $this->vatVerificationService = $vatVerificationService ?? app(VatVerificationService::class);
    }

    /**
     * Verify ROI status for a user.
     */
    public function verifyRoiStatus(string $userId, string $vatNumber, string $countryCode): array
    {
        // Log verification start
        Log::info('ROI verification started', [
            'user_id'    => $userId,
            'vat_number' => $vatNumber,
        ]);

        // Validate VAT number format
        if (! $this->isValidVatFormat($vatNumber, $countryCode)) {
            // Create ROI verification record for invalid format
            $roiVerification = UserRoiVerification::createOrUpdateRoiVerification([
                'user_id'         => $userId,
                'vat_number'      => $vatNumber,
                'country_code'    => $countryCode,
                'is_roi'          => false,
                'company_name'    => null,
                'company_address' => null,
                'api_source'      => 'validation_error',
                'response_data'   => ['error' => 'Invalid VAT number format'],
                'cache_hit'       => false,
            ]);

            // Log invalid format query
            $this->logRoiQuery($userId, $vatNumber, $countryCode, RoiQuery::QUERY_TYPE_API, 'validation_error', [
                'error'  => 'Invalid VAT number format',
                'failed' => true,
            ]);

            $result = [
                'is_roi'       => false,
                'vat_number'   => $vatNumber,
                'country_code' => $countryCode,
                'error'        => 'Invalid VAT number format',
                'cache_hit'    => false,
                'api_source'   => 'validation_error',
            ];

            Log::info('ROI verification completed', [
                'user_id'    => $userId,
                'vat_number' => $vatNumber,
                'is_roi'     => $result['is_roi'],
            ]);

            return $result;
        }

        // Check if force API check is enabled
        if (UserRoiVerification::shouldForceApiCheck()) {
            return $this->verifyFromApi($userId, $vatNumber, $countryCode);
        }

        // Try to get from cache first
        $cachedVerification = $this->getCachedRoiVerification($userId, $vatNumber, $countryCode);

        if ($cachedVerification && $cachedVerification->isCacheValid()) {
            // Log cache hit
            $this->logRoiQuery($userId, $vatNumber, $countryCode, RoiQuery::QUERY_TYPE_CACHE, 'cache', [
                'cached'     => true,
                'expired_at' => $cachedVerification->expired_at,
            ]);

            $cachedVerification->markAsCacheHit();

            $result = [
                'is_roi'          => $cachedVerification->is_roi,
                'vat_number'      => $cachedVerification->vat_number,
                'country_code'    => $cachedVerification->country_code,
                'company_name'    => $cachedVerification->company_name,
                'company_address' => $cachedVerification->company_address,
                'cache_hit'       => true,
                'api_source'      => 'cache',
                'expired_at'      => $cachedVerification->expired_at,
                'last_check'      => $cachedVerification->last_check,
            ];

            Log::info('ROI verification completed', [
                'user_id'    => $userId,
                'vat_number' => $vatNumber,
                'is_roi'     => $result['is_roi'],
            ]);

            return $result;
        }

        // Cache miss or expired, verify from API
        return $this->verifyFromApi($userId, $vatNumber, $countryCode);
    }

    /**
     * Check if ROI is valid (not expired).
     */
    public function isRoiValid(string $userId, string $vatNumber, string $countryCode): bool
    {
        $verification = UserRoiVerification::findValidByUserAndVat($userId, $vatNumber, $countryCode);

        return $verification && $verification->isValid();
    }

    /**
     * Verify ROI from API.
     */
    private function verifyFromApi(string $userId, string $vatNumber, string $countryCode): array
    {
        try {
            // Use existing VAT verification service
            $vatResult = $this->vatVerificationService->verifyVatNumber($vatNumber, $countryCode);

            // Determine if it's ROI based on VAT verification result
            // If all APIs failed, consider it as not ROI for safety
            $isRoi = ($vatResult['is_valid'] ?? false) && ! ($vatResult['all_apis_failed'] ?? false);

            // If all APIs failed, return error response
            if ($vatResult['all_apis_failed'] ?? false) {
                // Create ROI verification record for failed verification
                $roiVerification = UserRoiVerification::createOrUpdateRoiVerification([
                    'user_id'         => $userId,
                    'vat_number'      => $vatNumber,
                    'country_code'    => $countryCode,
                    'is_roi'          => false,
                    'company_name'    => null,
                    'company_address' => null,
                    'api_source'      => $vatResult['api_source'] ?? 'api_failure',
                    'response_data'   => $vatResult,
                    'cache_hit'       => false,
                ]);

                // Log failed query
                $this->logRoiQuery($userId, $vatNumber, $countryCode, RoiQuery::QUERY_TYPE_API, $vatResult['api_source'] ?? 'api_failure', $vatResult);

                $result = [
                    'is_roi'       => false,
                    'vat_number'   => $vatNumber,
                    'country_code' => $countryCode,
                    'error'        => 'API verification failed',
                    'cache_hit'    => false,
                    'api_source'   => $vatResult['api_source'] ?? 'api_failure',
                ];

                Log::info('ROI verification completed with API failure', [
                    'user_id'    => $userId,
                    'vat_number' => $vatNumber,
                    'is_roi'     => $result['is_roi'],
                ]);

                return $result;
            }

            // Create or update ROI verification
            $roiVerification = UserRoiVerification::createOrUpdateRoiVerification([
                'user_id'         => $userId,
                'vat_number'      => $vatNumber,
                'country_code'    => $countryCode,
                'is_roi'          => $isRoi,
                'company_name'    => $vatResult['company_name']    ?? '',
                'company_address' => $vatResult['company_address'] ?? null,
                'api_source'      => $vatResult['api_source']      ?? 'unknown',
                'response_data'   => $vatResult,
                'cache_hit'       => false,
            ]);

            // Log API query
            $this->logRoiQuery($userId, $vatNumber, $countryCode, RoiQuery::QUERY_TYPE_API, $vatResult['api_source'] ?? 'unknown', $vatResult);

            // Cache the result
            $this->cacheRoiVerification($roiVerification);

            $result = [
                'is_roi'          => $isRoi,
                'vat_number'      => $vatNumber,
                'country_code'    => $countryCode,
                'company_name'    => $vatResult['company_name']    ?? '',
                'company_address' => $vatResult['company_address'] ?? null,
                'cache_hit'       => false,
                'expired_at'      => $roiVerification->expired_at,
                'last_check'      => $roiVerification->last_check,
                'api_source'      => $vatResult['api_source'] ?? 'unknown',
            ];

            Log::info('ROI verification completed', [
                'user_id'    => $userId,
                'vat_number' => $vatNumber,
                'is_roi'     => $result['is_roi'],
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('ROI verification from API failed', [
                'user_id'      => $userId,
                'vat_number'   => $vatNumber,
                'country_code' => $countryCode,
                'error'        => $e->getMessage(),
            ]);

            // Log failed query
            $this->logRoiQuery($userId, $vatNumber, $countryCode, RoiQuery::QUERY_TYPE_API, 'error', [
                'error'  => $e->getMessage(),
                'failed' => true,
            ]);

            $result = [
                'is_roi'       => false,
                'vat_number'   => $vatNumber,
                'country_code' => $countryCode,
                'error'        => 'API verification failed: '.$e->getMessage(),
                'cache_hit'    => false,
                'api_source'   => 'error',
            ];

            Log::info('ROI verification completed', [
                'user_id'    => $userId,
                'vat_number' => $vatNumber,
                'is_roi'     => $result['is_roi'],
            ]);

            return $result;
        }
    }

    /**
     * Get cached ROI verification.
     */
    private function getCachedRoiVerification(string $userId, string $vatNumber, string $countryCode): ?UserRoiVerification
    {
        return UserRoiVerification::findValidByUserAndVat($userId, $vatNumber, $countryCode);
    }

    /**
     * Cache ROI verification.
     */
    private function cacheRoiVerification(UserRoiVerification $verification): void
    {
        $cacheData = [
            'is_roi'          => $verification->is_roi,
            'vat_number'      => $verification->vat_number,
            'country_code'    => $verification->country_code,
            'company_name'    => $verification->company_name,
            'company_address' => $verification->company_address,
            'expired_at'      => $verification->expired_at,
            'last_check'      => $verification->last_check,
        ];

        $this->cacheService->storeRoiVerification(
            $verification->user_id,
            $verification->vat_number,
            $verification->country_code,
            $cacheData
        );
    }

    /**
     * Log ROI query for legal compliance.
     */
    private function logRoiQuery(string $userId, string $vatNumber, string $countryCode, string $queryType, string $apiSource, array $responseData): void
    {
        try {
            if ($queryType === RoiQuery::QUERY_TYPE_CACHE) {
                RoiQuery::createCacheQuery($userId, $vatNumber, $countryCode, $responseData);
            } else {
                RoiQuery::createApiQuery($userId, $vatNumber, $countryCode, $apiSource, $responseData);
            }
        } catch (\Exception $e) {
            Log::error('Failed to log ROI query', [
                'user_id'      => $userId,
                'vat_number'   => $vatNumber,
                'country_code' => $countryCode,
                'query_type'   => $queryType,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if cache is expired.
     */
    private function isCacheExpired(UserRoiVerification $verification): bool
    {
        return $verification->isExpired();
    }

    /**
     * Build cache key.
     */
    private function buildCacheKey(string $userId, string $vatNumber, string $countryCode): string
    {
        return "roi_verification:{$userId}:{$vatNumber}:{$countryCode}";
    }

    /**
     * Get ROI verification statistics for a user.
     */
    public function getRoiStatistics(string $userId, int $days = 30): array
    {
        $startDate = now()->subDays($days);
        $endDate   = now();

        return RoiQuery::getQueryStatistics($userId, $startDate, $endDate);
    }

    /**
     * Clean up expired ROI verifications.
     */
    public function cleanupExpiredVerifications(): int
    {
        $expiredVerifications = UserRoiVerification::expired()->get();
        $count                = 0;

        foreach ($expiredVerifications as $verification) {
            // Remove from cache
            $cacheKey = $this->buildCacheKey(
                $verification->user_id,
                $verification->vat_number,
                $verification->country_code
            );

            $this->cacheService->forget($cacheKey);
            $count++;
        }

        return $count;
    }

    /**
     * Clean up expired legal retention queries.
     */
    public function cleanupExpiredLegalRetention(): int
    {
        return RoiQuery::cleanupExpiredLegalRetention();
    }

    /**
     * Get all ROI verifications for a user.
     */
    public function getUserRoiVerifications(string $userId): \Illuminate\Database\Eloquent\Collection
    {
        return UserRoiVerification::byUser($userId)
            ->orderBy('last_check', 'desc')
            ->get();
    }

    /**
     * Get ROI verification history for a user.
     */
    public function getRoiVerificationHistory(string $userId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return UserRoiVerification::where('user_id', $userId)
            ->orderBy('last_check', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Force refresh ROI verification (bypass cache).
     */
    public function forceRefreshRoiVerification(string $userId, string $vatNumber, string $countryCode): array
    {
        // Remove from cache first
        $cacheKey = $this->buildCacheKey($userId, $vatNumber, $countryCode);
        $this->cacheService->forget($cacheKey);

        // Remove from database
        UserRoiVerification::where('user_id', $userId)
            ->where('vat_number', $vatNumber)
            ->where('country_code', $countryCode)
            ->delete();

        // Verify from API
        return $this->verifyFromApi($userId, $vatNumber, $countryCode);
    }

    /**
     * Get cache statistics.
     */
    public function getCacheStatistics(): array
    {
        return $this->cacheService->getStats();
    }

    /**
     * Check if cache is available.
     */
    public function isCacheAvailable(): bool
    {
        return $this->cacheService->isAvailable();
    }

    /**
     * Get ROI query statistics for a user.
     */
    public function getRoiQueryStatistics(string $userId): array
    {
        $total      = RoiQuery::where('user_id', $userId)->count();
        $apiQueries = RoiQuery::where('user_id', $userId)
            ->where('query_type', RoiQuery::QUERY_TYPE_API)
            ->count();
        $cacheQueries = RoiQuery::where('user_id', $userId)
            ->where('query_type', RoiQuery::QUERY_TYPE_CACHE)
            ->count();

        return [
            'total'           => $total,
            'api_queries'     => $apiQueries,
            'cache_queries'   => $cacheQueries,
            'cache_hit_ratio' => $total > 0 ? ($cacheQueries / $total) * 100 : 0,
        ];
    }

    /**
     * Cleanup expired legal retention queries.
     */
    public function cleanupExpiredLegalRetentionQueries(): int
    {
        $deleted = RoiQuery::where('legal_retention_until', '<', now())->delete();

        Log::info('Cleaned up expired legal retention queries', [
            'deleted_count' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Validate VAT number format.
     */
    public function isValidVatFormat(string $vatNumber, string $countryCode): bool
    {
        $countryCode = strtoupper($countryCode);

        // Check if country is supported
        $patterns = [
            'ES' => '/^ES[A-Z0-9]{8,9}$/',
            'FR' => '/^FR[A-Z0-9]{11}$/',
            'DE' => '/^DE[0-9]{9}$/',
            'IT' => '/^IT[0-9]{11}$/',
            'GB' => '/^GB[0-9]{9,12}$/',
        ];

        // If country not supported, return false
        if (! isset($patterns[$countryCode])) {
            return false;
        }

        // Check basic format (not empty, starts with country code)
        if (empty($vatNumber) || strtoupper(substr($vatNumber, 0, 2)) !== $countryCode) {
            return false;
        }

        return preg_match($patterns[$countryCode], $vatNumber) === 1;
    }

    /**
     * Extract country code from VAT number.
     */
    public function extractCountryCode(string $vatNumber): ?string
    {
        if (strlen($vatNumber) < 2) {
            return null;
        }

        $countryCode = strtoupper(substr($vatNumber, 0, 2));

        // Validate it's a valid country code
        $validCountries = ['ES', 'FR', 'DE', 'IT', 'GB', 'NL', 'BE', 'AT', 'PT', 'GR'];

        return in_array($countryCode, $validCountries) ? $countryCode : null;
    }

    /**
     * Get API rate limits configuration.
     */
    public function getApiRateLimits(): array
    {
        return config('larabill.roi_verification.api_rate_limits', []);
    }

    /**
     * Check API rate limit status.
     */
    public function checkApiRateLimit(string $apiSource): array
    {
        $rateLimits = $this->getApiRateLimits();
        $limit      = $rateLimits[$apiSource] ?? 1000;

        return [
            'remaining'  => $limit - rand(0, 100), // Mock implementation
            'reset_time' => now()->addHour(),
            'limit'      => $limit,
        ];
    }

    /**
     * Batch verify ROI status for multiple users.
     */
    public function batchVerifyRoiStatus(array $vatNumbers): array
    {
        $results = [];

        foreach ($vatNumbers as $vatData) {
            $userId      = $vatData['user_id'];
            $vatNumber   = $vatData['vat_number'];
            $countryCode = $this->extractCountryCode($vatNumber) ?? 'ES';

            $result    = $this->verifyRoiStatus($userId, $vatNumber, $countryCode);
            $results[] = array_merge($result, [
                'user_id'    => $userId,
                'vat_number' => $vatNumber,
            ]);
        }

        return $results;
    }

    /**
     * Get service configuration.
     */
    public function getConfiguration(): array
    {
        return config('larabill.roi_verification', []);
    }

    /**
     * Update service configuration.
     */
    public function updateConfiguration(array $config): void
    {
        foreach ($config as $key => $value) {
            config(["larabill.roi_verification.{$key}" => $value]);
        }
    }

    /**
     * Log service operations.
     */
    public function logServiceOperation(string $operation, array $context = []): void
    {
        Log::info("ROI verification {$operation}", $context);
    }
}
