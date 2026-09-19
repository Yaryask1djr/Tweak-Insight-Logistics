<?php

/**
 * JobQueue — Lightweight, resilient job queue for asynchronous background processing.
 *
 * Dispatches heavy, I/O-bound tasks (SMS gateways, WhatsApp Business API,
 * Firebase Cloud Messaging push notifications, and external client webhooks)
 * outside of the user-facing HTTP request thread.
 *
 * Storage Drivers:
 *   - Redis (if REDIS_HOST is configured)
 *   - MySQL job_queue table (default, zero extra infrastructure required)
 */
final class JobQueue
{
    private const REDIS_READY_PREFIX = 'queue:';
    private const REDIS_DELAYED_PREFIX = 'queue:delayed:';
    private const REDIS_PROCESSING_PREFIX = 'queue:processing:';
    private const REDIS_FAILED_PREFIX = 'queue:failed:';
    private const REDIS_BATCH_SIZE = 100;
    private const REDIS_DRIVER_MARKER = '_queue_driver';
    private const REDIS_RAW_MARKER = '_queue_redis_raw';
    private const REDIS_QUEUE_MARKER = '_queue_redis_queue';

    private static ?PDO $db = null;

    /** @var null|callable Test-only Redis connection factory. */
    private static $redisConnectionFactory = null;

    public static function setDb(PDO $db): void
    {
        self::$db = $db;
    }

    /** Inject an in-memory Redis client for CLI verification without a Redis service. */
    public static function setRedisConnectionFactoryForTesting(?callable $factory): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new LogicException('Redis connection factories may only be configured from CLI tests.');
        }

        self::$redisConnectionFactory = $factory;
    }

    private static function getDb(): ?PDO
    {
        if (self::$db !== null) {
            return self::$db;
        }
        if (class_exists('Database')) {
            try {
                $database = new Database();
                self::$db = $database->getConnection();
            } catch (Throwable $e) {
                self::$db = null;
            }
        }
        return self::$db;
    }

    /**
     * Push a new job onto the asynchronous queue.
     *
     * @param string $jobType      Job identifier, e.g. 'notification.external_dispatch' or 'webhook.deliver'
     * @param array  $payload      Job payload data
     * @param string $queueName    Queue name (default: 'default')
     * @param int    $delaySeconds Delay execution by N seconds
     * @return bool True if queued successfully
     */
    public static function push(
        string $jobType,
        array $payload,
        string $queueName = 'default',
        int $delaySeconds = 0
    ): bool {
        $delaySeconds = max(0, $delaySeconds);

        // 1. Try Redis first. The worker reserves from the same ready list.
        $redis = self::redisConnection();
        if ($redis !== null) {
            try {
                $jobData = self::encodeRedisJob([
                    'id'           => bin2hex(random_bytes(12)),
                    'job_type'     => $jobType,
                    'payload'      => $payload,
                    'queue'        => $queueName,
                    'attempts'     => 0,
                    'max_attempts' => 3,
                    'created_at'   => time(),
                ]);

                if ($jobData !== null) {
                    $result = $delaySeconds > 0
                        ? $redis->zAdd(self::redisDelayedKey($queueName), time() + $delaySeconds, $jobData)
                        : $redis->rPush(self::redisReadyKey($queueName), $jobData);

                    if ($result !== false) {
                        return true;
                    }
                }
            } catch (Throwable $e) {
                // Failover to MySQL database queue
            } finally {
                self::closeRedisConnection($redis);
            }
        }

        // 2. MySQL database queue driver
        $db = self::getDb();
        if ($db === null) {
            return false;
        }

        try {
            self::ensureTable($db);
            if ($delaySeconds > 0) {
                $stmt = $db->prepare(
                    'INSERT INTO job_queue (queue_name, job_type, payload, status, attempts, max_attempts, available_at)
                     VALUES (?, ?, ?, \'pending\', 0, 3, DATE_ADD(NOW(), INTERVAL ? SECOND))'
                );
                $stmt->execute([
                    $queueName,
                    $jobType,
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $delaySeconds,
                ]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO job_queue (queue_name, job_type, payload, status, attempts, max_attempts, available_at)
                     VALUES (?, ?, ?, \'pending\', 0, 3, NOW())'
                );
                $stmt->execute([
                    $queueName,
                    $jobType,
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }
            return true;
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Failed to push job onto queue', [
                    'job_type' => $jobType,
                    'error'    => $e->getMessage(),
                ]);
            }
            return false;
        }
    }

    /**
     * Reserve and fetch the next available job for processing (atomic pop).
     */
    public static function pop(string $queueName = 'default'): ?array
    {
        $redis = self::redisConnection();
        if ($redis !== null) {
            try {
                $job = self::popRedis($redis, $queueName);
                if ($job !== null) {
                    return $job;
                }
            } catch (Throwable $e) {
                // Preserve the database fallback if Redis is unavailable mid-pop.
            } finally {
                self::closeRedisConnection($redis);
            }
        }

        return self::popDatabase($queueName);
    }

    /** Reserve a MySQL fallback job with a row lock. */
    private static function popDatabase(string $queueName): ?array
    {
        $db = self::getDb();
        if ($db === null) {
            return null;
        }

        try {
            self::ensureTable($db);
            $db->beginTransaction();

            // A crashed worker can leave a job reserved forever. Return stale
            // reservations to the queue before selecting the next job.
            $reclaim = $db->prepare(
                "UPDATE job_queue
                 SET status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'pending' END,
                     reserved_at = NULL,
                     available_at = UTC_TIMESTAMP(),
                     failed_at = CASE WHEN attempts >= max_attempts THEN UTC_TIMESTAMP() ELSE failed_at END,
                     error_message = CASE WHEN attempts >= max_attempts THEN 'Worker reservation expired after maximum attempts.' ELSE error_message END
                 WHERE queue_name = ?
                   AND status = 'processing'
                   AND reserved_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)"
            );
            $reclaim->execute([$queueName]);

            $stmt = $db->prepare(
                'SELECT id, queue_name, job_type, payload, attempts, max_attempts
                 FROM job_queue
                 WHERE queue_name = ?
                   AND status = \'pending\'
                   AND available_at <= NOW()
                 ORDER BY available_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([$queueName]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$job) {
                $db->commit();
                return null;
            }

            // Mark as processing
            $update = $db->prepare(
                'UPDATE job_queue
                 SET status = \'processing\',
                     attempts = attempts + 1,
                     reserved_at = NOW()
                 WHERE id = ?'
            );
            $update->execute([$job['id']]);
            $db->commit();

            $job['payload'] = json_decode($job['payload'], true) ?: [];
            $job['attempts'] = (int)$job['attempts'] + 1;
            return $job;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return null;
        }
    }

    /**
     * Mark a job as completed.
     */
    public static function complete(int|string $jobId): void
    {
        $db = self::getDb();
        if ($db === null) return;

        try {
            $stmt = $db->prepare('UPDATE job_queue SET status = \'completed\', completed_at = NOW() WHERE id = ?');
            $stmt->execute([$jobId]);
        } catch (Throwable $e) {
            // Log if needed
        }
    }

    /**
     * Mark a job as failed, with exponential backoff retry if attempts remaining.
     */
    public static function fail(int|string $jobId, string $errorMessage, int $currentAttempts, int $maxAttempts = 3): void
    {
        $db = self::getDb();
        if ($db === null) return;

        try {
            if ($currentAttempts < $maxAttempts) {
                // Exponential backoff: 30s, 120s, 480s
                $retryDelay = (int)pow(4, $currentAttempts) * 10;
                $stmt = $db->prepare(
                    'UPDATE job_queue
                     SET status = \'pending\',
                         available_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                         error_message = ?
                     WHERE id = ?'
                );
                $stmt->execute([$retryDelay, $errorMessage, $jobId]);
            } else {
                $stmt = $db->prepare(
                    'UPDATE job_queue
                     SET status = \'failed\',
                         failed_at = NOW(),
                         error_message = ?
                     WHERE id = ?'
                );
                $stmt->execute([$errorMessage, $jobId]);
            }
        } catch (Throwable $e) {
            // Log if needed
        }
    }

    /**
     * Ensure the job_queue database table exists.
     */
    private static function ensureTable(PDO $db): void
    {
        static $ensured = false;
        if ($ensured) return;

        $db->exec("CREATE TABLE IF NOT EXISTS job_queue (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            queue_name VARCHAR(60) NOT NULL DEFAULT 'default',
            job_type VARCHAR(100) NOT NULL,
            payload JSON NOT NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
            available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reserved_at DATETIME NULL,
            completed_at DATETIME NULL,
            failed_at DATETIME NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_job_status_available (queue_name, status, available_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $ensured = true;
    }

    /**
     * Establish or retrieve a Redis connection if configured.
     *
     * @return object|null
     */
    private static function redisConnection(): ?object
    {
        if (self::$redisConnectionFactory !== null) {
            try {
                $client = (self::$redisConnectionFactory)();
                return is_object($client) ? $client : null;
            } catch (Throwable $e) {
                return null;
            }
        }

        $host = getenv('REDIS_HOST');
        if (empty($host) || !class_exists('Redis')) {
            return null;
        }

        try {
            $redis = new Redis();
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            $timeout = (float)(getenv('REDIS_TIMEOUT') ?: 0.5);
            if (!$redis->connect($host, $port, $timeout)) {
                return null;
            }
            $auth = getenv('REDIS_PASSWORD') ?: getenv('REDIS_AUTH') ?: null;
            if (!empty($auth)) {
                $redis->auth($auth);
            }
            $database = getenv('REDIS_DB');
            if ($database !== false && $database !== null && $database !== '') {
                $redis->select((int)$database);
            }
            return $redis;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Safely close a Redis connection.
     *
     * @param mixed $redis
     */
    private static function closeRedisConnection($redis): void
    {
        if (is_object($redis) && method_exists($redis, 'close')) {
            try {
                $redis->close();
            } catch (Throwable $e) {
                // Ignore disconnect errors
            }
        }
    }

    /**
     * Encode a job payload array for Redis storage.
     */
    private static function encodeRedisJob(array $job): ?string
    {
        $encoded = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : null;
    }

    /**
     * Generate the Redis key for ready (immediately consumable) jobs.
     */
    private static function redisReadyKey(string $queueName): string
    {
        return self::REDIS_READY_PREFIX . $queueName;
    }

    /**
     * Generate the Redis key for delayed jobs sorted set.
     */
    private static function redisDelayedKey(string $queueName): string
    {
        return self::REDIS_DELAYED_PREFIX . $queueName;
    }

    /**
     * Generate the Redis key for processing jobs.
     */
    private static function redisProcessingKey(string $queueName): string
    {
        return self::REDIS_PROCESSING_PREFIX . $queueName;
    }

    /**
     * Generate the Redis key for failed dead-letter jobs.
     */
    private static function redisFailedKey(string $queueName): string
    {
        return self::REDIS_FAILED_PREFIX . $queueName;
    }

    /**
     * Atomically pop a job from Redis, migrating due delayed jobs first.
     */
    private static function popRedis(object $redis, string $queueName): ?array
    {
        $delayedKey = self::redisDelayedKey($queueName);
        $readyKey   = self::redisReadyKey($queueName);

        // 1. Move any matured delayed jobs into the ready queue
        if (method_exists($redis, 'zRangeByScore') && method_exists($redis, 'zRem') && method_exists($redis, 'rPush')) {
            try {
                $now = time();
                $dueJobs = $redis->zRangeByScore($delayedKey, '-inf', (string)$now, ['limit' => [0, self::REDIS_BATCH_SIZE]]);
                if (!empty($dueJobs) && is_array($dueJobs)) {
                    foreach ($dueJobs as $delayedJob) {
                        if ($redis->zRem($delayedKey, $delayedJob) > 0) {
                            $redis->rPush($readyKey, $delayedJob);
                        }
                    }
                }
            } catch (Throwable $e) {
                // Ignore delayed migration errors and proceed to check ready queue
            }
        }

        // 2. Pop from ready queue (FIFO: rPush -> lPop)
        if (!method_exists($redis, 'lPop')) {
            return null;
        }

        $raw = $redis->lPop($readyKey);
        if (!$raw || !is_string($raw)) {
            return null;
        }

        $job = json_decode($raw, true);
        if (!is_array($job)) {
            return null;
        }

        $job['queue_name'] = $job['queue'] ?? $queueName;
        $job['attempts'] = ((int)($job['attempts'] ?? 0)) + 1;
        $job['max_attempts'] = (int)($job['max_attempts'] ?? 3);
        $job['payload'] = isset($job['payload']) && is_array($job['payload'])
            ? $job['payload']
            : (json_decode($job['payload'] ?? '{}', true) ?: []);

        $job[self::REDIS_DRIVER_MARKER] = 'redis';
        $job[self::REDIS_RAW_MARKER] = $raw;
        $job[self::REDIS_QUEUE_MARKER] = $queueName;

        return $job;
    }
}
