<?php

/**
 * CacheHelper — Multi-tier cache manager (Redis with local in-memory/file fallback).
 *
 * Provides fast caching for read-heavy operations such as dashboard statistics,
 * pricing lookup tables, and service area configuration.
 */
class CacheHelper
{
    private static ?Redis $redis = null;
    private static bool $redisChecked = false;
    private static array $memoryCache = [];

    /**
     * Get or connect Redis client singleton.
     */
    private static function getRedis(): ?Redis
    {
        if (self::$redisChecked) {
            return self::$redis;
        }
        self::$redisChecked = true;

        $host = getenv('REDIS_HOST');
        if (!empty($host) && class_exists('Redis')) {
            try {
                $redis = new Redis();
                $port = (int)(getenv('REDIS_PORT') ?: 6379);
                $timeout = (float)(getenv('REDIS_TIMEOUT') ?: 0.2);
                if ($redis->connect($host, $port, $timeout)) {
                    $auth = getenv('REDIS_PASSWORD');
                    if (!empty($auth)) {
                        $redis->auth($auth);
                    }
                    self::$redis = $redis;
                }
            } catch (Throwable $e) {
                self::$redis = null;
            }
        }
        return self::$redis;
    }

    /**
     * Retrieve cached value by key.
     */
    public static function get(string $key): mixed
    {
        // 1. Check Redis
        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $val = $redis->get($key);
                if ($val !== false && $val !== null) {
                    return json_decode($val, true);
                }
            } catch (Throwable $e) {}
        }

        // 2. Check APCu cache
        if (function_exists('apcu_fetch')) {
            $success = false;
            $apcuVal = apcu_fetch($key, $success);
            if ($success && $apcuVal !== false) {
                return $apcuVal;
            }
        }

        // 3. Check local memory cache
        if (isset(self::$memoryCache[$key])) {
            [$val, $expiry] = self::$memoryCache[$key];
            if ($expiry > time()) {
                return $val;
            }
            unset(self::$memoryCache[$key]);
        }

        // 4. Check persistent cache storage
        $cacheDir = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../storage') . '/cache';
        $file = $cacheDir . '/' . md5($key) . '.json';
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) {
                $data = json_decode($raw, true);
                if (isset($data['expires_at'], $data['value']) && $data['expires_at'] > time()) {
                    self::$memoryCache[$key] = [$data['value'], $data['expires_at']];
                    return $data['value'];
                }
                @unlink($file);
            }
        }

        return null;
    }

    /**
     * Store value in cache with TTL (seconds).
     */
    public static function set(string $key, mixed $value, int $ttlSeconds = 60): bool
    {
        // 1. Redis
        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $redis->setEx($key, $ttlSeconds, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } catch (Throwable $e) {}
        }

        // 2. APCu
        if (function_exists('apcu_store')) {
            @apcu_store($key, $value, $ttlSeconds);
        }

        // 3. Memory & file cache
        $expiry = time() + $ttlSeconds;
        self::$memoryCache[$key] = [$value, $expiry];

        $cacheDir = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../storage') . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0700, true);
        }
        $file = $cacheDir . '/' . md5($key) . '.json';
        $written = @file_put_contents($file, json_encode(['expires_at' => $expiry, 'value' => $value]), LOCK_EX);
        if ($written === false) {
            return false;
        }
        @chmod($file, 0600);
        return true;
    }

    /**
     * Invalidate a cache key.
     */
    public static function delete(string $key): bool
    {
        unset(self::$memoryCache[$key]);

        if (function_exists('apcu_delete')) {
            @apcu_delete($key);
        }

        $redis = self::getRedis();
        if ($redis !== null) {
            try {
                $redis->del($key);
            } catch (Throwable $e) {}
        }
        $cacheDir = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../storage') . '/cache';
        $file = $cacheDir . '/' . md5($key) . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
        return true;
    }

    /**
     * High-Frequency GPS Location Cache Helpers
     *
     * Default TTL is 10 seconds (not 5 minutes) because live tracking
     * streams (SSE) and driver location updates happen every 2–15 seconds
     * in normal operations. A 300 s TTL made tracking feel "frozen"
     * after cold-cache was warmed. The previous 300 s default is kept
     * only for explicit callers of setDriverLocation with an override.
     */
    public static function setDriverLocation(int|string $deliveryId, array $data, int $ttlSeconds = 10): void
    {
        self::set("delivery:geo:{$deliveryId}", $data, $ttlSeconds);
    }

    public static function getDriverLocation(int|string $deliveryId): ?array
    {
        $res = self::get("delivery:geo:{$deliveryId}");
        return is_array($res) ? $res : null;
    }

    public static function setActiveAdminLocations(array $data, int $ttlSeconds = 10): void
    {
        self::set("admin:active_deliveries_geo", $data, $ttlSeconds);
    }

    public static function getActiveAdminLocations(): ?array
    {
        $res = self::get("admin:active_deliveries_geo");
        return is_array($res) ? $res : null;
    }

    /**
     * Remember helper: returns cached value or computes, caches, and returns it.
     */
    public static function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $fresh = $callback();
        self::set($key, $fresh, $ttlSeconds);
        return $fresh;
    }
}
