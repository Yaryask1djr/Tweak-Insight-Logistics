<?php

require_once __DIR__ . '/../helpers/cache_helper.php';
require_once __DIR__ . '/../helpers/response.php';

/**
 * Operational health endpoint. Returns HTTP 200 only when every downstream dependency
 * that the platform needs to accept bookings is available. Non-200 is treated as "unhealthy"
 * by load balancers, uptime monitors, and k8s liveness/readiness probes.
 *
 * Two modes:
 *   ?mode=liveness    — fast, app-server-only probe (DB + basic PHP runtime).
 *   ?mode=readiness   — full dependency probe, includes cache + queue + worker liveness.
 *   default /health              = both.
 */
final class HealthController
{
    public static function check(PDO $db): void
    {
        $mode = strtolower(trim((string)($_GET['mode'] ?? 'full')));
        $start = microtime(true);

        $checks = [];
        $checks['php_version'] = [
            'ok'      => true,
            'version' => PHP_VERSION,
            'sapi'    => PHP_SAPI,
        ];

        // --------------------------------------------------------------
        // 1) Database connectivity + simple query round-trip
        // --------------------------------------------------------------
        $dbStart = microtime(true);
        try {
            $stmt = $db->query('SELECT 1 AS ping, NOW(6) AS db_time');
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            $dbOk = $row !== false && (int)($row['ping'] ?? 0) === 1;
            $checks['database'] = [
                'ok'           => $dbOk,
                'latency_ms'   => (int)round((microtime(true) - $dbStart) * 1000),
                'server_time'  => $row['db_time'] ?? null,
            ];
        } catch (Throwable $e) {
            $checks['database'] = [
                'ok'           => false,
                'latency_ms'   => (int)round((microtime(true) - $dbStart) * 1000),
                'error'        => $e->getMessage(),
            ];
        }

        $healthy = ($checks['database']['ok'] ?? false) === true;

        if ($mode !== 'liveness') {
            // ------------------------------------------------------------------
            // 2) Redis / Cache tier connectivity (used for rate-limit, cache, queue)
            // ------------------------------------------------------------------
            $redisStart = microtime(true);
            $redisAvailable = false;
            $redisInfo      = [];
            try {
                $probeKey = 'health:probe:' . bin2hex(random_bytes(4));
                CacheHelper::set($probeKey, '1', 5);
                $back = CacheHelper::get($probeKey);
                if ($back === '1') {
                    $redisAvailable = true;
                    $redisInfo['roundtrip_ok'] = true;
                } else {
                    $redisInfo['roundtrip_ok'] = false;
                    $redisInfo['error'] = 'Cache round-trip returned unexpected value';
                }
                // Try a direct Redis ping to confirm the primary tier if available
                $redis = self::peekRedisInstance();
                if ($redis !== null) {
                    try {
                        $redisInfo['redis_ping'] = $redis->ping() ? 'PONG' : 'FAIL';
                        $redisInfo['redis_primary'] = true;
                    } catch (Throwable $e) {
                        $redisInfo['redis_primary'] = false;
                        $redisInfo['redis_error']   = $e->getMessage();
                    }
                } else {
                    $redisInfo['redis_primary'] = false;
                    $redisInfo['note'] = 'Redis not configured; using file/apcu fallback cache tier.';
                }
            } catch (Throwable $e) {
                $redisInfo['error'] = $e->getMessage();
            }
            $checks['cache'] = array_merge([
                'ok'         => $redisAvailable,
                'latency_ms' => (int)round((microtime(true) - $redisStart) * 1000),
            ], $redisInfo);
            $healthy = $healthy && $redisAvailable;

            // ------------------------------------------------------------------
            // 3) Queue depth + worker heartbeat (freshness)
            // ------------------------------------------------------------------
            $worker = self::probeWorker($db);
            $checks['queue_worker'] = $worker;
            $healthy = $healthy && ($worker['ok'] ?? false);

            // ------------------------------------------------------------------
            // 4) Pending queue backlog (how deep is the queue? alert if >50
            // ------------------------------------------------------------------
            $backlog = self::probeQueueBacklog($db);
            $checks['queue_backlog'] = $backlog;
            $backlogOk = ($backlog['depth'] ?? 0) <= 500;
            $healthy = $healthy && $backlogOk;
        }

        $totalMs = (int)round((microtime(true) - $start) * 1000);
        $statusCode = $healthy ? 200 : 503;

        $payload = [
            'service'     => 'Tweak Insight Logistics API',
            'version'     => '2.0.0',
            'status'      => $healthy ? 'healthy' : 'unhealthy',
            'mode'        => $mode,
            'server_time' => gmdate('c'),
            'uptime_s'    => self::uptimeSeconds(),
            'latency_ms'  => $totalMs,
            'checks'      => $checks,
        ];

        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Health-Check: v1');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** Expose whether the Redis primary tier is available. */
    private static function peekRedisInstance(): ?Redis {
        if (!method_exists(CacheHelper::class, 'getRedis')) {
            return null;
        }
        // Access the private getRedis by invoking a harmless public method that
        // warms the singleton, then try to instantiate a same-config copy.
        try {
            if (!class_exists('Redis')) return null;
            $host = getenv('REDIS_HOST');
            if (empty($host)) return null;
            $redis = new Redis();
            $port  = (int)(getenv('REDIS_PORT') ?: 6379);
            if (!$redis->connect($host, $port, 0.25)) return null;
            $auth = getenv('REDIS_PASSWORD');
            if (!empty($auth)) $redis->auth($auth);
            return $redis;
        } catch (Throwable) {
            return null;
        }
    }

    /** Read the queue-worker daemon heartbeat file written by queue_worker.php */
    private static function probeWorker(PDO $db): array {
        $heartbeatFile = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../storage') . '/logs/worker_heartbeat.json';
        $staleThreshold = 120; // 2 minutes max age before marking unhealthy
        $data = null;
        $fileOk = false;
        if (is_file($heartbeatFile) && is_readable($heartbeatFile)) {
            $raw  = @file_get_contents($heartbeatFile);
            $data = @json_decode($raw, true);
            $fileOk = is_array($data);
        }

        $lastUpdate = $fileOk && isset($data['updated_at_unix']) ? (int)$data['updated_at_unix'] : null;
        $age = $lastUpdate ? (time() - $lastUpdate) : null;
        $ok  = $fileOk && $age !== null && $age <= $staleThreshold;

        $out = [
            'ok'                => $ok,
            'heartbeat_file_ok'  => $fileOk,
            'heartbeat_age_s'   => $age,
            'stale_threshold_s' => $staleThreshold,
            'jobs_processed_lifetime' => $fileOk ? ($data['jobs_processed'] ?? null) : null,
            'last_job_class'     => $fileOk ? ($data['last_job_class'] ?? null) : null,
            'worker_pid'       => $fileOk ? ($data['pid'] ?? null) : null,
        ];

        // Fallback probe: ensure job_queue table newest row freshness
        if (!$ok) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM job_queue WHERE status = 'processing' AND reserved_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");
                $processing = (int)$stmt->fetchColumn();
                $out['fallback_db_processing_recent'] = $processing;
                if ($processing > 0) {
                    $out['ok']              = true;
                    $out['fallback_reason'] = 'Heartbeat file stale, active job proven by DB rows.';
                }
            } catch (Throwable) {}
        }
        return $out;
    }

    /** Count how many jobs are still queued (pending + delayed + failed). */
    private static function probeQueueBacklog(PDO $db): array {
        $pending = 0;
        $delayed = 0;
        $failed  = 0;
        try {
            $stmt = $db->prepare(
                "SELECT status, COUNT(*) AS c FROM job_queue WHERE status IN ('pending','delayed','failed') GROUP BY status"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $status => $count) {
                if ($status === 'pending')   $pending = (int)$count;
                elseif ($status === 'delayed') $delayed = (int)$count;
                elseif ($status === 'failed')  $failed  = (int)$count;
            }
        } catch (Throwable) {}

        // Best-effort Redis queue sizes for driver-scheduled jobs that were pushed
        $redisQueued = null;
        try {
            $r = self::peekRedisInstance();
            if ($r !== null) {
                $redisQueued = [
                    'queue_default_pending' => (int)$r->lLen('queue:default'),
                    'queue_scheduled_zcard' => (int)$r->zCard('queue:scheduled'),
                    'queue_failed_pending'  => (int)$r->lLen('queue:failed'),
                ];
            }
        } catch (Throwable) {}

        $depth = $pending + $delayed;
        return [
            'ok'              => true,
            'depth'           => $depth,
            'pending'         => $pending,
            'delayed'         => $delayed,
            'failed'          => $failed,
            'warn_at_depth_gt' => 500,
            'redis'           => $redisQueued,
        ];
    }

    /** Approximate seconds elapsed since bootstrap (true OS uptime not available in PHP.) */
    private static function uptimeSeconds(): ?float {
        if (!empty($_SERVER['REQUEST_TIME_FLOAT'])) {
            return round(microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT'], 3);
        }
        return null;
    }
}
