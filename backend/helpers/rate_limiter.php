<?php

require_once __DIR__ . '/client_ip.php';

/**
 * RateLimiter — fixed-window IP rate limiting for sensitive endpoints.
 *
 * Production uses Redis' atomic INCR + EXPIRE operation. Development may use
 * the database bucket fallback, which performs an atomic upsert against a
 * unique rate_key. There is deliberately no file fallback: read/modify/write
 * files are not safe across concurrent PHP workers or application instances.
 *
 * Usage:
 *   RateLimiter::check('login');           // 5 attempts per 15 min
 *   RateLimiter::check('register', $db);   // optional PDO for development
 */
class RateLimiter
{
    /**
     * Predefined limits per action.
     * 'max'    = maximum attempts allowed in the window
     * 'window' = time window in seconds
     */
    private static array $limits = [
        'login'    => ['max' => 5,  'window' => 900],   // 5 attempts per 15 min
        'register' => ['max' => 3,  'window' => 1800],  // 3 attempts per 30 min
        'refresh'  => ['max' => 30, 'window' => 900],   // rotation abuse guard
        'public_tracking' => ['max' => 30, 'window' => 300], // public lookup scraping guard
        'otp_confirmation' => ['max' => 5, 'window' => 900], // OTP brute-force guard per IP
    ];

    /**
     * Check whether the current IP is within the rate limit for the given action.
     * If the limit is exceeded, sends a 429 JSON response and exits.
     */
    public static function check(string $action, ?PDO $db = null): void
    {
        $ip = self::getClientIp();
        $limit = self::$limits[$action] ?? ['max' => 10, 'window' => 600];
        $rateKey = hash('sha256', $ip . ':' . $action);
        $isProduction = strtolower((string)getenv('APP_ENV')) === 'production';
        $allowDbFallback = filter_var(getenv('RATE_LIMIT_ALLOW_DB_FALLBACK') ?: 'false', FILTER_VALIDATE_BOOLEAN);
        $driver = strtolower(trim((string)(getenv('RATE_LIMIT_DRIVER') ?: ($isProduction && !$allowDbFallback ? 'redis' : 'database'))));

        // A production limiter defaults to redis. If Redis is unavailable or unconfigured,
        // it may fall back to atomic database buckets if RATE_LIMIT_ALLOW_DB_FALLBACK is enabled.
        if ($isProduction && $driver !== 'redis' && !$allowDbFallback) {
            self::serviceUnavailable();
        }

        if ($driver === 'redis') {
            try {
                [$count, $ttl] = self::incrementRedis($rateKey, $limit['window']);
                if ($count > $limit['max']) {
                    self::reject($ttl);
                }
                return;
            } catch (Throwable $exception) {
                if ($isProduction && !$allowDbFallback) {
                    self::serviceUnavailable();
                }
                // When allowed, fall through to the atomic database bucket implementation below
            }
        } elseif ($driver !== 'database' && !$allowDbFallback) {
            self::serviceUnavailable();
        }

        if ($db === null) {
            if (!class_exists('Database')) {
                self::serviceUnavailable();
            }
            $db = (new Database())->getConnection();
        }
        self::incrementDatabase($db, $rateKey, $limit['window'], $limit['max']);
    }

    /**
     * Redis fixed-window increment. A Lua script makes INCR and EXPIRE one
     * atomic operation so a process crash cannot create a permanent key.
     */
    private static function incrementRedis(string $rateKey, int $windowSeconds): array
    {
        if (!class_exists('Redis')) {
            throw new RuntimeException('PHP Redis extension is not installed.');
        }
        $host = trim((string)getenv('REDIS_HOST'));
        if ($host === '') {
            throw new RuntimeException('REDIS_HOST is not configured.');
        }

        $redis = new Redis();
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $timeout = (float)(getenv('REDIS_TIMEOUT') ?: 0.5);
        if (!$redis->connect($host, $port, $timeout)) {
            throw new RuntimeException('Unable to connect to Redis.');
        }
        try {
            $password = getenv('REDIS_PASSWORD');
            if ($password !== false && $password !== '' && !$redis->auth($password)) {
                throw new RuntimeException('Redis authentication failed.');
            }
            $database = getenv('REDIS_DATABASE');
            if ($database !== false && $database !== '' && !$redis->select((int)$database)) {
                throw new RuntimeException('Redis database selection failed.');
            }

            $prefix = (string)(getenv('REDIS_PREFIX') ?: 'til:');
            $result = $redis->eval(
                'local count = redis.call("INCR", KEYS[1]); '
                . 'if count == 1 then redis.call("EXPIRE", KEYS[1], ARGV[1]); end; '
                . 'return {count, redis.call("TTL", KEYS[1])};',
                [$prefix . 'rate-limit:' . $rateKey, (string)$windowSeconds],
                1
            );
            if (!is_array($result) || count($result) !== 2) {
                throw new RuntimeException('Redis returned an invalid rate-limit response.');
            }
            return [(int)$result[0], max(1, (int)$result[1])];
        } finally {
            $redis->close();
        }
    }

    /**
     * Development fallback with one unique row per rate key. INSERT ... ON
     * DUPLICATE KEY UPDATE acquires the InnoDB row lock, making reset/increment
     * atomic across concurrent PHP workers. The table is created by a deploy
     * migration; no request path performs DDL.
     */
    private static function incrementDatabase(PDO $db, string $rateKey, int $windowSeconds, int $maxAttempts): void
    {
        $now = time();
        $expiresAt = $now + $windowSeconds;
        try {
            $db->beginTransaction();
            $statement = $db->prepare(
                'INSERT INTO rate_limit_buckets (rate_key, attempts, window_expires_at)
                 VALUES (?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                    attempts = IF(window_expires_at <= ?, 1, attempts + 1),
                    window_expires_at = IF(window_expires_at <= ?, ?, window_expires_at),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $statement->execute([$rateKey, $expiresAt, $now, $now, $expiresAt]);

            // Hold the same row lock until this request has observed its own
            // increment. This prevents a fifth concurrent request seeing a
            // later sixth increment and being incorrectly rejected.
            $bucket = $db->prepare('SELECT attempts, window_expires_at FROM rate_limit_buckets WHERE rate_key = ? FOR UPDATE');
            $bucket->execute([$rateKey]);
            $row = $bucket->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Rate-limit bucket was not created.');
            }
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
        if ((int)$row['attempts'] > $maxAttempts) {
            self::reject(max(1, (int)$row['window_expires_at'] - $now));
        }
    }

    private static function reject(int $retryAfter): void
    {
        $minutes = (int)ceil($retryAfter / 60);
        header("Retry-After: {$retryAfter}");
        Response::error("Too many requests. Please try again in {$minutes} minute(s).", 429);
    }

    private static function serviceUnavailable(): void
    {
        header('Retry-After: 30');
        Response::error('Request protection is temporarily unavailable. Please try again shortly.', 503);
    }

    /**
     * Get the real client IP, only trusting forwarded headers when the immediate
     * connection (REMOTE_ADDR) originates from a known trusted reverse proxy or CDN.
     *
     * Trusted proxy subnets are loaded from the TRUSTED_PROXIES environment variable
     * (comma-separated CIDRs). Defaults to localhost and RFC-1918 ranges in
     * development only. Production trusts no forwarding header unless this is
     * explicitly configured with actual Cloudflare / load-balancer CIDRs.
     *
     * Cloudflare published IPv4 ranges: https://www.cloudflare.com/ips-v4
     */
    private static function getClientIp(): string
    {
        return ClientIp::resolve();
    }

    /**
     * Returns true if $remoteAddr falls within one of the configured trusted proxy
     * CIDR ranges. Production has no defaults; set a comma-separated list of
     * the real reverse proxy/CDN networks, for example:
     *   TRUSTED_PROXIES=103.21.244.0/22,203.0.113.8/32
     */
    private static function isFromTrustedProxy(string $remoteAddr): bool
    {
        return ClientIp::isTrustedProxy($remoteAddr);
    }

    /**
     * Tests whether a given $ip falls within a $cidr range.
     * Supports both IPv4 CIDR (e.g. 103.21.244.0/22) and exact IPs (no slash).
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        return ClientIp::ipInCidr($ip, $cidr);
    }

}
