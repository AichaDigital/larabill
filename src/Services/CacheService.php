<?php

declare(strict_types=1);

namespace AichaDigital\Larabill\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cache Service
 *
 * Provides cache functionality that is agnostic to the underlying driver.
 * Supports both file and Redis cache drivers with automatic fallback.
 */
class CacheService
{
    /**
     * Cache driver configuration.
     */
    private string $driver;

    private string $prefix;

    private array $ttl;

    private array $tags;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->driver = config('larabill.cache.driver', 'file');
        $this->prefix = config('larabill.cache.prefix', 'larabill');
        $this->ttl = config('larabill.cache.ttl', []);
        $this->tags = config('larabill.cache.tags', []);
    }

    /**
     * Get a value from cache.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->buildCacheKey($key);

        try {
            if ($this->supportsTags()) {
                $tag = $this->getTagForKey($key);

                return Cache::tags($tag)->get($cacheKey, $default);
            }

            return Cache::get($cacheKey, $default);
        } catch (\Exception $e) {
            Log::warning('Cache get failed, falling back to default', [
                'key' => $cacheKey,
                'driver' => $this->driver,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * Store a value in cache.
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->buildCacheKey($key);
        $ttl = $ttl ?? $this->getTtlForKey($key);

        try {
            if ($this->supportsTags()) {
                $tag = $this->getTagForKey($key);
                Cache::tags($tag)->put($cacheKey, $value, $ttl);
            } else {
                Cache::put($cacheKey, $value, $ttl);
            }

            return true;
        } catch (\Exception $e) {
            Log::warning('Cache put failed', [
                'key' => $cacheKey,
                'driver' => $this->driver,
                'ttl' => $ttl,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Store a value in cache forever.
     */
    public function forever(string $key, mixed $value): bool
    {
        $cacheKey = $this->buildCacheKey($key);

        try {
            if ($this->supportsTags()) {
                $tag = $this->getTagForKey($key);
                Cache::tags($tag)->forever($cacheKey, $value);
            } else {
                Cache::forever($cacheKey, $value);
            }

            return true;
        } catch (\Exception $e) {
            Log::warning('Cache forever failed', [
                'key' => $cacheKey,
                'driver' => $this->driver,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Remove a value from cache.
     */
    public function forget(string $key): bool
    {
        $cacheKey = $this->buildCacheKey($key);

        try {
            if ($this->supportsTags()) {
                $tag = $this->getTagForKey($key);
                Cache::tags($tag)->forget($cacheKey);
            } else {
                Cache::forget($cacheKey);
            }

            return true;
        } catch (\Exception $e) {
            Log::warning('Cache forget failed', [
                'key' => $cacheKey,
                'driver' => $this->driver,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if a key exists in cache.
     */
    public function has(string $key): bool
    {
        $cacheKey = $this->buildCacheKey($key);

        try {
            if ($this->supportsTags()) {
                $tag = $this->getTagForKey($key);

                return Cache::tags($tag)->has($cacheKey);
            }

            return Cache::has($cacheKey);
        } catch (\Exception $e) {
            Log::warning('Cache has failed', [
                'key' => $cacheKey,
                'driver' => $this->driver,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get or store a value in cache.
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->buildCacheKey($key);
        $ttl = $ttl ?? $this->getTtlForKey($key);

        try {
            if ($this->supportsTags()) {
                $tag = $this->getTagForKey($key);

                return Cache::tags($tag)->remember($cacheKey, $ttl, $callback);
            }

            return Cache::remember($cacheKey, $ttl, $callback);
        } catch (\Exception $e) {
            Log::warning('Cache remember failed, executing callback directly', [
                'key' => $cacheKey,
                'driver' => $this->driver,
                'ttl' => $ttl,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * Get or store a value in cache forever.
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        $cacheKey = $this->buildCacheKey($key);

        try {
            if ($this->supportsTags()) {
                $tag = $this->getTagForKey($key);

                return Cache::tags($tag)->rememberForever($cacheKey, $callback);
            }

            return Cache::rememberForever($cacheKey, $callback);
        } catch (\Exception $e) {
            Log::warning('Cache rememberForever failed, executing callback directly', [
                'key' => $cacheKey,
                'driver' => $this->driver,
                'error' => $e->getMessage(),
            ]);

            return $callback();
        }
    }

    /**
     * Clear cache by tag.
     */
    public function clearByTag(string $tag): bool
    {
        try {
            if ($this->supportsTags()) {
                Cache::tags($tag)->flush();

                return true;
            }

            Log::info('Cache driver does not support tags, skipping tag-based clear', [
                'tag' => $tag,
                'driver' => $this->driver,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::warning('Cache clear by tag failed', [
                'tag' => $tag,
                'driver' => $this->driver,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear all cache.
     */
    public function clear(): bool
    {
        try {
            Cache::flush();

            return true;
        } catch (\Exception $e) {
            Log::warning('Cache clear failed', [
                'driver' => $this->driver,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get cache statistics.
     */
    public function getStats(): array
    {
        return [
            'driver' => $this->driver,
            'prefix' => $this->prefix,
            'supports_tags' => $this->supportsTags(),
            'ttl_config' => $this->ttl,
            'tags_config' => $this->tags,
        ];
    }

    /**
     * Build cache key with prefix.
     */
    private function buildCacheKey(string $key): string
    {
        return $this->prefix.':'.$key;
    }

    /**
     * Get TTL for a specific key type.
     */
    private function getTtlForKey(string $key): int
    {
        // Extract key type from key (e.g., 'roi_verification:123' -> 'roi_verification')
        $keyParts = explode(':', $key);
        $keyType = $keyParts[0] ?? 'default';

        return $this->ttl[$keyType] ?? 3600; // Default 1 hour
    }

    /**
     * Get tag for a specific key type.
     */
    private function getTagForKey(string $key): string
    {
        // Extract key type from key (e.g., 'roi_verification:123' -> 'roi_verification')
        $keyParts = explode(':', $key);
        $keyType = $keyParts[0] ?? 'default';

        return $this->tags[$keyType] ?? 'default';
    }

    /**
     * Check if current driver supports tags.
     */
    private function supportsTags(): bool
    {
        return in_array($this->driver, ['redis', 'memcached']);
    }

    /**
     * Get cache driver name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Check if cache is available.
     */
    public function isAvailable(): bool
    {
        try {
            $testKey = 'larabill:test:'.uniqid();
            $this->put($testKey, 'test', 60);
            $result = $this->get($testKey);
            $this->forget($testKey);

            return $result === 'test';
        } catch (\Exception $e) {
            Log::warning('Cache availability check failed', [
                'driver' => $this->driver,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get cache key patterns for debugging.
     */
    public function getKeyPatterns(): array
    {
        return [
            'roi_verification' => $this->prefix.':roi_verification:*',
            'vat_rates' => $this->prefix.':vat_rates:*',
            'company_config' => $this->prefix.':company_config:*',
        ];
    }

    /**
     * Warm up cache with common data.
     */
    public function warmUp(): array
    {
        $results = [];

        try {
            // Warm up VAT rates
            $results['vat_rates'] = $this->warmUpVatRates();

            // Warm up company configs
            $results['company_configs'] = $this->warmUpCompanyConfigs();

            Log::info('Cache warm-up completed', $results);
        } catch (\Exception $e) {
            Log::warning('Cache warm-up failed', [
                'error' => $e->getMessage(),
            ]);

            $results['error'] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Warm up VAT rates cache.
     */
    private function warmUpVatRates(): array
    {
        // This would be implemented to load common VAT rates
        return ['status' => 'skipped', 'reason' => 'not_implemented'];
    }

    /**
     * Warm up company configs cache.
     */
    private function warmUpCompanyConfigs(): array
    {
        // This would be implemented to load common company configs
        return ['status' => 'skipped', 'reason' => 'not_implemented'];
    }
}
