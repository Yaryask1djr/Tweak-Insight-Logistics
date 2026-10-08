<?php

/**
 * At-least-once queue with expiring, fenced reservations.
 * QUEUE_DRIVER selects one backend; outages never switch storage.
 * Handlers must be idempotent: a crash after a side effect can cause a retry.
 */
final class JobQueue
{
    private static ?PDO $db = null;
    private static ?PDO $validatedDb = null;
    private static $redisConnectionFactory = null;

    public static function setDb(PDO $db): void { self::$db = $db; }

    /** CLI tests may supply an adapter; production uses phpredis. */
    public static function setRedisConnectionFactoryForTesting(?callable $factory): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new LogicException('Redis connection factories are restricted to CLI tests.');
        }
        self::$redisConnectionFactory = $factory;
    }

    public static function visibilityTimeout(): int
    {
        $value = getenv('QUEUE_VISIBILITY_TIMEOUT');
        $seconds = ($value === false || $value === '') ? 300 : filter_var($value, FILTER_VALIDATE_INT);
        if ($seconds === false || $seconds < 30 || $seconds > 86400) {
            throw new InvalidArgumentException('QUEUE_VISIBILITY_TIMEOUT must be 30–86400 seconds.');
        }
        return $seconds;
    }

    public static function driver(): string
    {
        $driver = strtolower(trim(getenv('QUEUE_DRIVER') ?: 'mysql'));
        if (!in_array($driver, ['mysql', 'redis'], true)) {
            throw new InvalidArgumentException('QUEUE_DRIVER must be mysql or redis.');
        }
        return $driver;
    }

    private static function validateQueue(string $queue): void
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,60}\z/D', $queue)) {
            throw new InvalidArgumentException('Queue names must contain 1–60 letters, digits, underscores or hyphens.');
        }
    }

    /** Probe the selected backend directly; cache fallbacks are not queue health. */
    public static function readiness(PDO $db, string $queue = 'default'): array
    {
        self::validateQueue($queue);
        if (self::driver() === 'mysql') {
            self::requireSchema($db);
            $stmt = $db->prepare("SELECT status, COUNT(*) AS count FROM job_queue WHERE queue_name = ? GROUP BY status");
            $stmt->execute([$queue]);
            return ['ok' => true, 'driver' => 'mysql', 'counts' => $stmt->fetchAll(PDO::FETCH_KEY_PAIR)];
        }
        return self::redisReadiness($queue);
    }

    public static function redisReadiness(string $queue = 'default'): array
    {
        self::validateQueue($queue);
        $redis = self::redisConnection();
        try {
            if (!$redis->ping()) throw new RuntimeException('Redis ping failed.');
            $probe = 'til:readiness:' . bin2hex(random_bytes(12));
            if (!$redis->setEx($probe, 5, '1') || $redis->get($probe) !== '1') throw new RuntimeException('Redis write/read probe failed.');
            $redis->del($probe);
            return ['ok' => true, 'driver' => 'redis', 'counts' => [
                'pending' => $redis->lLen('queue:' . $queue),
                'delayed' => $redis->zCard('queue:delayed:' . $queue),
                'processing' => $redis->zCard('queue:processing:' . $queue),
                'failed' => $redis->lLen('queue:failed:' . $queue),
            ]];
        } finally { $redis->close(); }
    }

    public static function push(string $jobType, array $payload, string $queueName = 'default', int $delaySeconds = 0): bool
    {
        try {
            self::validateQueue($queueName);
            if (!preg_match('/\A[A-Za-z0-9_.:-]{1,100}\z/D', $jobType)) {
                throw new InvalidArgumentException('Invalid queue job type.');
            }
            if ($delaySeconds < 0 || $delaySeconds > 31536000) {
                throw new InvalidArgumentException('Queue delay must be between zero and one year.');
            }
            if (self::driver() === 'redis') {
                $raw = json_encode([
                    'id' => bin2hex(random_bytes(12)), 'job_type' => $jobType,
                    // Lua must not re-encode payload numbers (64-bit IDs) or empty arrays.
                    'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'queue' => $queueName, 'attempts' => 0,
                    'max_attempts' => 3, 'created_at' => time(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                self::redisScript('push', $queueName, [$raw, $delaySeconds]);
            } else {
                $db = self::getDb();
                self::requireSchema($db);
                $stmt = $db->prepare("INSERT INTO job_queue
                    (queue_name, job_type, payload, status, attempts, max_attempts, available_at)
                    VALUES (?, ?, ?, 'pending', 0, 3, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))");
                $stmt->execute([$queueName, $jobType, json_encode($payload, JSON_THROW_ON_ERROR), $delaySeconds]);
            }
            return true;
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Failed to enqueue job; no alternate backend was used', [
                    'job_type' => $jobType, 'error' => $e->getMessage(),
                ]);
            }
            return false;
        }
    }

    /** Infrastructure errors throw; null means no job is ready. */
    public static function pop(string $queueName = 'default'): ?array
    {
        self::validateQueue($queueName);
        $token = bin2hex(random_bytes(24));
        $lease = self::visibilityTimeout();
        if (self::driver() === 'mysql') {
            return self::popDatabase($queueName, $token, $lease);
        }
        $raw = self::redisScript('reserve', $queueName, [$queueName, $token, $lease, 100]);
        if ($raw === '') return null;
        if (!is_string($raw)) throw new RuntimeException('Redis returned an invalid reservation response.');
        $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (isset($job['payload_json'])) {
            $job['payload'] = json_decode($job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        } else {
            $job['payload'] = json_decode($job['original_body'], true, 512, JSON_THROW_ON_ERROR)['payload'];
        }
        unset($job['payload_json'], $job['original_body']);
        $job['queue_name'] = $queueName;
        $job['_queue_driver'] = 'redis';
        // Exact bytes identify this reservation, including its unpredictable token.
        $job['_queue_redis_raw'] = $raw;
        return $job;
    }

    /** False means this reservation expired or was already resolved. */
    public static function complete(array $job): bool
    {
        self::validateReservation($job);
        if ($job['_queue_driver'] === 'redis') {
            return self::redisScript('complete', $job['queue_name'], [$job['_queue_redis_raw']]) === 1;
        }
        $stmt = self::getDb()->prepare("UPDATE job_queue
            SET status = 'completed', completed_at = UTC_TIMESTAMP(),
                reservation_token = NULL, reserved_until = NULL, reserved_at = NULL
            WHERE id = ? AND queue_name = ? AND status = 'processing'
              AND reservation_token = ? AND reserved_until > UTC_TIMESTAMP(6)");
        $stmt->execute([$job['id'], $job['queue_name'], $job['reservation_token']]);
        return $stmt->rowCount() === 1;
    }

    /** Retry with 30s/120s backoff, then retain the exhausted job for inspection. */
    public static function fail(array $job, string $errorMessage): bool
    {
        self::validateReservation($job);
        $error = json_decode(json_encode(substr($errorMessage, 0, 4000), JSON_INVALID_UTF8_SUBSTITUTE), true);
        if ($job['_queue_driver'] === 'redis') {
            return self::redisScript('fail', $job['queue_name'], [$job['_queue_redis_raw'], $error]) === 1;
        }
        // Decisions use persisted attempts, not caller-supplied counters.
        $stmt = self::getDb()->prepare("UPDATE job_queue SET
                status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'pending' END,
                available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL LEAST(3600, 30 * POW(4, LEAST(GREATEST(attempts - 1, 0), 4))) SECOND),
                failed_at = CASE WHEN attempts >= max_attempts THEN UTC_TIMESTAMP() ELSE NULL END,
                error_message = ?, reservation_token = NULL, reserved_until = NULL, reserved_at = NULL
            WHERE id = ? AND queue_name = ? AND status = 'processing'
              AND reservation_token = ? AND reserved_until > UTC_TIMESTAMP(6)");
        $stmt->execute([$error, $job['id'], $job['queue_name'], $job['reservation_token']]);
        return $stmt->rowCount() === 1;
    }

    /** Long handlers must renew before expiry and stop when ownership is lost. */
    public static function renew(array $job): bool
    {
        self::validateReservation($job);
        $lease = self::visibilityTimeout();
        if ($job['_queue_driver'] === 'redis') {
            return self::redisScript('renew', $job['queue_name'], [$job['_queue_redis_raw'], $lease]) === 1;
        }
        $stmt = self::getDb()->prepare("UPDATE job_queue
            SET reserved_until = GREATEST(DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND),
                                         DATE_ADD(reserved_until, INTERVAL 1 MICROSECOND))
            WHERE id = ? AND queue_name = ? AND status = 'processing'
              AND reservation_token = ? AND reserved_until > UTC_TIMESTAMP(6)");
        $stmt->execute([$lease, $job['id'], $job['queue_name'], $job['reservation_token']]);
        return $stmt->rowCount() === 1;
    }

    private static function validateReservation(array $job): void
    {
        if (!in_array($job['_queue_driver'] ?? null, ['mysql', 'redis'], true)
            || !is_string($job['queue_name'] ?? null) || !is_string($job['reservation_token'] ?? null)
            || !preg_match('/\A[a-f0-9]{48}\z/D', $job['reservation_token']) || !isset($job['id'])) {
            throw new InvalidArgumentException('A complete reserved job is required to acknowledge or retry work.');
        }
        self::validateQueue($job['queue_name']);
        if ($job['_queue_driver'] === 'redis' && !is_string($job['_queue_redis_raw'] ?? null)) {
            throw new InvalidArgumentException('Redis reservation data is missing.');
        }
        if ($job['_queue_driver'] === 'mysql' && !ctype_digit((string)$job['id'])) {
            throw new InvalidArgumentException('Invalid MySQL queue job ID.');
        }
        if ($job['_queue_driver'] === 'redis') {
            $stored = json_decode($job['_queue_redis_raw'], true, 512, JSON_THROW_ON_ERROR);
            if (($stored['id'] ?? null) !== $job['id'] || ($stored['queue'] ?? null) !== $job['queue_name']
                || ($stored['reservation_token'] ?? null) !== $job['reservation_token']) {
                throw new InvalidArgumentException('Redis reservation context does not match the reserved job.');
            }
        }
    }

    private static function popDatabase(string $queue, string $token, int $lease): ?array
    {
        $db = self::getDb();
        self::requireSchema($db);
        if ($db->inTransaction()) throw new LogicException('Queue reservations require their own transaction.');
        $db->beginTransaction();
        try {
            $reclaim = $db->prepare("UPDATE job_queue SET
                    status = CASE WHEN attempts >= max_attempts THEN 'failed' ELSE 'pending' END,
                    failed_at = CASE WHEN attempts >= max_attempts THEN UTC_TIMESTAMP() ELSE NULL END,
                    error_message = 'Worker reservation expired.', available_at = UTC_TIMESTAMP(),
                    reservation_token = NULL, reserved_until = NULL, reserved_at = NULL
                WHERE queue_name = ? AND status = 'processing'
                  AND (reserved_until <= UTC_TIMESTAMP(6)
                    OR (reserved_until IS NULL AND (reserved_at IS NULL
                        OR reserved_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 300 SECOND))))
                LIMIT 100");
            $reclaim->execute([$queue]);
            $stmt = $db->prepare("SELECT id, queue_name, job_type, payload, attempts, max_attempts
                FROM job_queue WHERE queue_name = ? AND status = 'pending' AND available_at <= UTC_TIMESTAMP()
                ORDER BY available_at, id LIMIT 1 FOR UPDATE");
            // Quarantine malformed legacy jobs instead of looping forever on one row.
            for ($count = 0; $count < 100; $count++) {
                $stmt->execute([$queue]);
                $job = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$job) { $db->commit(); return null; }
                $payload = json_decode($job['payload'], true);
                if (!is_array($payload) || !preg_match('/\A[A-Za-z0-9_.:-]{1,100}\z/D', $job['job_type'])
                    || (int)$job['max_attempts'] < 1 || (int)$job['max_attempts'] > 100
                    || (int)$job['attempts'] >= (int)$job['max_attempts']) {
                    $reject = $db->prepare("UPDATE job_queue SET status = 'failed', failed_at = UTC_TIMESTAMP(),
                        error_message = 'Invalid payload or retry limit exhausted.' WHERE id = ?");
                    $reject->execute([$job['id']]);
                    continue;
                }
                $update = $db->prepare("UPDATE job_queue SET status = 'processing', attempts = attempts + 1,
                    reserved_at = UTC_TIMESTAMP(), reservation_token = ?,
                    reserved_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND) WHERE id = ?");
                $update->execute([$token, $lease, $job['id']]);
                $db->commit();
                $job['payload'] = $payload;
                $job['attempts'] = (int)$job['attempts'] + 1;
                $job['max_attempts'] = (int)$job['max_attempts'];
                $job['reservation_token'] = $token;
                $job['_queue_driver'] = 'mysql';
                return $job;
            }
            $db->commit();
            return null;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function getDb(): PDO
    {
        if (self::$db === null) {
            if (!class_exists('Database')) throw new RuntimeException('The MySQL queue connection is not configured.');
            self::$db = (new Database())->getConnection();
        }
        return self::$db;
    }

    /** Read-only check: schema changes belong to deployment migrations. */
    private static function requireSchema(PDO $db): void
    {
        if (self::$validatedDb === $db) return;
        try {
            $db->query('SELECT reservation_token, reserved_until FROM job_queue LIMIT 0');
        } catch (Throwable $e) {
            throw new RuntimeException('Queue schema unavailable. Run scripts/migrate_job_queue_reservations.php with migration credentials.', 0, $e);
        }
        self::$validatedDb = $db;
    }

    private static function redisScript(string $name, string $queue, array $arguments): mixed
    {
        static $scripts = [];
        if (!isset($scripts[$name])) {
            $dir = __DIR__ . '/../queue/redis/';
            $common = file_get_contents($dir . 'common.lua');
            $script = file_get_contents($dir . $name . '.lua');
            if ($common === false || $script === false) throw new RuntimeException('Redis queue scripts are missing.');
            $scripts[$name] = $common . "\n" . $script;
        }
        $redis = self::redisConnection();
        try {
            $keys = ['queue:' . $queue, 'queue:delayed:' . $queue, 'queue:processing:' . $queue, 'queue:failed:' . $queue];
            $result = $redis->eval($scripts[$name], array_merge($keys, $arguments), count($keys));
            if ($result === false) throw new RuntimeException('Redis queue operation failed.');
            return $result;
        } finally {
            try { $redis->close(); } catch (Throwable $e) { /* The operation already finished. */ }
        }
    }

    public static function redisConnection(): object
    {
        if (self::$redisConnectionFactory !== null) {
            $client = (self::$redisConnectionFactory)();
            if (!is_object($client)) throw new RuntimeException('Redis test factory did not return a client.');
            return $client;
        }
        $host = getenv('REDIS_HOST');
        if (!$host || !class_exists('Redis')) throw new RuntimeException('Redis queue requires REDIS_HOST and phpredis.');
        $redis = new Redis();
        try {
            $scheme = getenv('REDIS_SCHEME') ?: 'tcp';
            if (!in_array($scheme, ['tcp', 'tls'], true)) throw new RuntimeException('REDIS_SCHEME must be tcp or tls.');
            $address = ($scheme === 'tls' ? 'tls://' : '') . $host;
            if (!$redis->connect($address, (int)(getenv('REDIS_PORT') ?: 6379), (float)(getenv('REDIS_TIMEOUT') ?: 2))) {
                throw new RuntimeException('Unable to connect to the Redis queue.');
            }
            $password = getenv('REDIS_PASSWORD') ?: getenv('REDIS_AUTH');
            if ($password && !$redis->auth($password)) throw new RuntimeException('Redis queue authentication failed.');
            $database = getenv('REDIS_DB_INDEX');
            if ($database === false || $database === '') $database = getenv('REDIS_DB') ?: getenv('REDIS_DATABASE') ?: '0';
            if (!ctype_digit($database) || !$redis->select((int)$database)) throw new RuntimeException('Invalid Redis queue database.');
            $redis->setOption(Redis::OPT_READ_TIMEOUT, max(1, (float)(getenv('REDIS_TIMEOUT') ?: 2)));
            return $redis;
        } catch (Throwable $e) {
            try { $redis->close(); } catch (Throwable $ignored) {}
            throw $e;
        }
    }
}
