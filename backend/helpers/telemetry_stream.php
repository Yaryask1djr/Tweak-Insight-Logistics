<?php
declare(strict_types=1);

require_once __DIR__ . '/cache_helper.php';
if (file_exists(__DIR__ . '/logger.php')) {
    require_once __DIR__ . '/logger.php';
}

/**
 * Lightweight RESP2 socket client for Redis streaming when phpredis extension is absent.
 */
class TelemetryRedisSocketClient
{
    /** @var resource|null */
    private $socket = null;
    private string $host;
    private int $port;
    private float $timeout;

    public function __construct(string $host, int $port, float $timeout = 0.5)
    {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
        $this->connect();
    }

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout);
        if ($this->socket) {
            stream_set_timeout($this->socket, (int)ceil($this->timeout));
        }
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket);
    }

    public function command(string ...$arguments): mixed
    {
        if (!is_resource($this->socket)) {
            $this->connect();
            if (!is_resource($this->socket)) {
                return null;
            }
        }

        $request = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $request .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        $length = strlen($request);
        $written = 0;
        while ($written < $length) {
            $bytes = @fwrite($this->socket, substr($request, $written));
            if ($bytes === false || $bytes === 0) {
                $this->close();
                return null;
            }
            $written += $bytes;
        }

        return $this->readResponse();
    }

    public function lPush(string $key, string $value): ?int
    {
        $res = $this->command('LPUSH', $key, $value);
        return is_int($res) ? $res : null;
    }

    public function rPop(string $key): ?string
    {
        $res = $this->command('RPOP', $key);
        return is_string($res) ? $res : null;
    }

    public function lLen(string $key): int
    {
        $res = $this->command('LLEN', $key);
        return is_int($res) ? $res : 0;
    }

    public function setEx(string $key, int $ttl, string $value): bool
    {
        $res = $this->command('SETEX', $key, (string)$ttl, $value);
        return $res === 'OK';
    }

    public function get(string $key): ?string
    {
        $res = $this->command('GET', $key);
        return is_string($res) ? $res : null;
    }

    public function del(string $key): int
    {
        $res = $this->command('DEL', $key);
        return is_int($res) ? $res : 0;
    }

    public function geoAdd(string $key, float $longitude, float $latitude, string $member): bool
    {
        $res = $this->command('GEOADD', $key, (string)$longitude, (string)$latitude, $member);
        return is_int($res) && $res >= 0;
    }

    public function ping(): bool
    {
        $res = $this->command('PING');
        return $res === 'PONG';
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function readResponse(): mixed
    {
        if (!is_resource($this->socket)) {
            return null;
        }

        $line = @fgets($this->socket);
        if ($line === false) {
            $this->close();
            return null;
        }

        $type = $line[0];
        $payload = substr($line, 1, -2);

        switch ($type) {
            case '+': // Simple string
                return $payload;
            case ':': // Integer
                return (int)$payload;
            case '-': // Error
                return null;
            case '$': // Bulk string
                $length = (int)$payload;
                if ($length === -1) {
                    return null;
                }
                $data = '';
                while (strlen($data) < $length + 2) {
                    $chunk = @fread($this->socket, $length + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        $this->close();
                        return null;
                    }
                    $data .= $chunk;
                }
                return substr($data, 0, $length);
            case '*': // Array
                $count = (int)$payload;
                if ($count === -1) {
                    return null;
                }
                $list = [];
                for ($i = 0; $i < $count; $i++) {
                    $list[] = $this->readResponse();
                }
                return $list;
            default:
                return null;
        }
    }
}

/**
 * TelemetryStream
 *
 * Buffers active driver GPS telemetry events in Redis (via phpredis or pure PHP socket)
 * to prevent database row-lock contention and high-frequency I/O churn during peak metropolitan fleet hours.
 * Buffered records are batch-flushed to MySQL in bulk transactions by background daemons or on buffer overflow.
 */
class TelemetryStream
{
    public const BUFFER_KEY = 'telemetry:buffer:events';
    public const LIVE_DELIVERY_PREFIX = 'telemetry:live:delivery:';
    public const LIVE_DRIVER_PREFIX = 'telemetry:live:driver:';
    public const GEO_KEY = 'geo:drivers';
    public const DEFAULT_LIVE_TTL = 60; // seconds

    private static ?object $client = null;
    private static bool $connectionAttempted = false;
    private static bool $clientMocked = false;

    /**
     * Override connection for tests.
     */
    public static function setClientForTesting(?object $client): void
    {
        self::$client = $client;
        self::$clientMocked = true;
        self::$connectionAttempted = true;
    }

    public static function resetClientForTesting(): void
    {
        self::$client = null;
        self::$clientMocked = false;
        self::$connectionAttempted = false;
    }

    /**
     * Get or initialize a Redis client connection.
     */
    public static function getClient(): ?object
    {
        if (self::$clientMocked) {
            return self::$client;
        }
        if (self::$client !== null) {
            return self::$client;
        }
        if (self::$connectionAttempted) {
            return null;
        }
        self::$connectionAttempted = true;

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 16379);
        $timeout = (float)(getenv('REDIS_TIMEOUT') ?: 0.5);

        // 1. If phpredis extension is installed
        if (class_exists('Redis')) {
            try {
                $redis = new Redis();
                if ($redis->connect($host, $port, $timeout)) {
                    $password = getenv('REDIS_PASSWORD') ?: getenv('REDIS_AUTH');
                    if (!empty($password)) {
                        $redis->auth($password);
                    }
                    self::$client = $redis;
                    return self::$client;
                }
            } catch (Throwable $e) {
                // fall through to socket client
            }
        }

        // 2. Pure RESP2 socket fallback
        try {
            $socketClient = new TelemetryRedisSocketClient($host, $port, $timeout);
            if ($socketClient->isConnected() && $socketClient->ping()) {
                $password = getenv('REDIS_PASSWORD') ?: getenv('REDIS_AUTH');
                if (!empty($password)) {
                    $socketClient->command('AUTH', $password);
                }
                self::$client = $socketClient;
                return self::$client;
            }
        } catch (Throwable $e) {
            // Redis unavailable
        }

        return null;
    }

    /**
     * Buffer a driver's GPS location event.
     * Writes to Redis buffer + ephemeral live cache keys.
     * Falls back to immediate MySQL insert if Redis is down.
     */
    public static function recordLocation(
        PDO $db,
        int $deliveryId,
        int $driverUserId,
        float $latitude,
        float $longitude,
        ?float $accuracy = null,
        ?float $heading = null,
        ?string $recordedAt = null
    ): array {
        $timestamp = $recordedAt ?: date('Y-m-d H:i:s');
        $eventData = [
            'delivery_id'        => $deliveryId,
            'delivery_person_id' => $driverUserId,
            'latitude'           => $latitude,
            'longitude'          => $longitude,
            'accuracy_m'         => $accuracy,
            'heading_degrees'    => $heading,
            'recorded_at'        => $timestamp,
        ];

        $client = self::getClient();
        $bufferedInRedis = false;

        if ($client !== null) {
            try {
                $json = json_encode($eventData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                
                // 1. Push to batch buffer
                if (method_exists($client, 'lPush')) {
                    $client->lPush(self::BUFFER_KEY, $json);
                }

                // 2. Update live key for delivery
                $deliveryKey = self::LIVE_DELIVERY_PREFIX . $deliveryId;
                if (method_exists($client, 'setEx')) {
                    $client->setEx($deliveryKey, self::DEFAULT_LIVE_TTL, $json);
                }

                // 3. Update live key for driver
                $driverKey = self::LIVE_DRIVER_PREFIX . $driverUserId;
                if (method_exists($client, 'setEx')) {
                    $client->setEx($driverKey, self::DEFAULT_LIVE_TTL, $json);
                }

                // 4. Update geospatial coordinate if supported
                if (method_exists($client, 'geoAdd')) {
                    try {
                        $client->geoAdd(self::GEO_KEY, $longitude, $latitude, (string)$driverUserId);
                    } catch (Throwable $e) {
                        // ignore spatial command errors
                    }
                }

                $bufferedInRedis = true;
            } catch (Throwable $e) {
                if (class_exists('Logger')) {
                    Logger::notice('Redis telemetry buffer write failed, falling back to direct MySQL insert', [
                        'error' => $e->getMessage(),
                        'delivery_id' => $deliveryId,
                    ]);
                }
                $bufferedInRedis = false;
            }
        }

        // Resilient Fallback: If Redis is unavailable or errored, write immediately to MySQL
        if (!$bufferedInRedis) {
            $stmt = $db->prepare('
                INSERT INTO delivery_location_events 
                    (delivery_id, delivery_person_id, latitude, longitude, accuracy_m, heading_degrees, recorded_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $deliveryId,
                $driverUserId,
                $latitude,
                $longitude,
                $accuracy,
                $heading,
                $timestamp,
            ]);
        }

        // Update local CacheHelper for backward-compatibility with other dashboard views
        CacheHelper::setDriverLocation($deliveryId, [
            'latitude'        => $latitude,
            'longitude'       => $longitude,
            'accuracy_m'      => $accuracy,
            'heading_degrees' => $heading,
            'updated_at'      => $timestamp,
        ], self::DEFAULT_LIVE_TTL);
        CacheHelper::delete("admin:active_deliveries_geo");

        return [
            'buffered'        => $bufferedInRedis,
            'fallback_mysql'  => !$bufferedInRedis,
            'event'           => $eventData,
        ];
    }

    /**
     * Retrieve latest live location for a delivery from fast Redis ephemeral store.
     */
    public static function getLiveDeliveryLocation(int $deliveryId): ?array
    {
        $client = self::getClient();
        if ($client !== null) {
            try {
                $key = self::LIVE_DELIVERY_PREFIX . $deliveryId;
                $raw = method_exists($client, 'get') ? $client->get($key) : null;
                if (!empty($raw)) {
                    $data = json_decode($raw, true);
                    if (is_array($data)) {
                        return $data;
                    }
                }
            } catch (Throwable $e) {
                // fall through
            }
        }

        // Secondary cache check
        return CacheHelper::getDriverLocation($deliveryId);
    }

    /**
     * Retrieve queue depth of pending unpersisted telemetry events.
     */
    public static function getBufferLength(): int
    {
        $client = self::getClient();
        if ($client === null) {
            return 0;
        }

        try {
            if (method_exists($client, 'lLen')) {
                return (int)$client->lLen(self::BUFFER_KEY);
            }
        } catch (Throwable $e) {
            return 0;
        }

        return 0;
    }

    /**
     * Batch-flush pending buffered GPS events from Redis to MySQL table `delivery_location_events`.
     *
     * Extracts up to $limit events from the buffer and writes them in a single multi-row
     * INSERT transaction to maximize MySQL throughput and minimize lock contention.
     *
     * @return int Number of events flushed
     */
    public static function flushBatch(PDO $db, int $limit = 250): int
    {
        $client = self::getClient();
        if ($client === null) {
            return 0;
        }

        $eventsToFlush = [];

        try {
            // Pop events from Redis buffer up to limit
            for ($i = 0; $i < $limit; $i++) {
                $raw = method_exists($client, 'rPop') ? $client->rPop(self::BUFFER_KEY) : null;
                if ($raw === null || $raw === false || $raw === '') {
                    break;
                }
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded) && isset($decoded['delivery_id'], $decoded['latitude'], $decoded['longitude'])) {
                    $eventsToFlush[] = $decoded;
                }
            }
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Failed to pop telemetry buffer from Redis', ['error' => $e->getMessage()]);
            }
        }

        if (empty($eventsToFlush)) {
            return 0;
        }

        // Multi-row bulk insert into MySQL
        $count = count($eventsToFlush);
        $rowPlaceholders = [];
        $values = [];

        foreach ($eventsToFlush as $ev) {
            $rowPlaceholders[] = '(?, ?, ?, ?, ?, ?, ?)';
            $values[] = (int)$ev['delivery_id'];
            $values[] = (int)($ev['delivery_person_id'] ?? 0);
            $values[] = (float)$ev['latitude'];
            $values[] = (float)$ev['longitude'];
            $values[] = isset($ev['accuracy_m']) && $ev['accuracy_m'] !== null ? (float)$ev['accuracy_m'] : null;
            $values[] = isset($ev['heading_degrees']) && $ev['heading_degrees'] !== null ? (float)$ev['heading_degrees'] : null;
            $values[] = $ev['recorded_at'] ?? date('Y-m-d H:i:s');
        }

        $sql = 'INSERT INTO delivery_location_events 
                (delivery_id, delivery_person_id, latitude, longitude, accuracy_m, heading_degrees, recorded_at) 
                VALUES ' . implode(', ', $rowPlaceholders);

        try {
            $db->beginTransaction();
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
            $db->commit();
            return $count;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // Safety recovery: re-enqueue unwritten events to prevent data loss
            try {
                foreach (array_reverse($eventsToFlush) as $ev) {
                    $json = json_encode($ev, JSON_UNESCAPED_SLASHES);
                    if (method_exists($client, 'lPush')) {
                        $client->lPush(self::BUFFER_KEY, $json);
                    }
                }
            } catch (Throwable $requeueErr) {
                // best effort
            }

            if (class_exists('Logger')) {
                Logger::critical('Failed to flush telemetry batch to database; re-buffered events', [
                    'count' => $count,
                    'error' => $e->getMessage(),
                ]);
            }
            throw $e;
        }
    }
}
