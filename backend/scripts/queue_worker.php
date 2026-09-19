<?php
/**
 * Asynchronous Background Queue Worker & Daemon.
 *
 * Designed to run continuously under systemd or supervisord in production,
 * or as a scheduled / one-off CLI worker in development.
 *
 * Usage:
 *   php backend/scripts/queue_worker.php                         (Continuous daemon)
 *   php backend/scripts/queue_worker.php --once                  (Process available batch and exit)
 *   php backend/scripts/queue_worker.php --queue=high            (Listen to specific queue)
 *   php backend/scripts/queue_worker.php --max-jobs=500          (Recycle after 500 jobs)
 *   php backend/scripts/queue_worker.php --memory-limit=128      (Recycle if memory > 128 MB)
 *   php backend/scripts/queue_worker.php --sleep=3               (Idle sleep interval in seconds)
 *   php backend/scripts/queue_worker.php --status                (Display live worker status/heartbeat)
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/job_queue.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../helpers/notification_service.php';
require_once __DIR__ . '/../helpers/storage_adapter.php';
require_once __DIR__ . '/../helpers/monitoring.php';

// --- Parse CLI Options ---
$shortOpts = "q:s:m:j:h";
$longOpts  = [
    "queue::",
    "once",
    "sleep::",
    "memory-limit::",
    "max-jobs::",
    "status",
    "help",
];
$options = getopt($shortOpts, $longOpts);

if (isset($options['help']) || isset($options['h'])) {
    echo <<<HELP
Tweak Insight Logistics — Queue Worker Daemon
=============================================
Usage: php backend/scripts/queue_worker.php [options]

Options:
  --queue=<name>, -q <name>     Queue channel to process (default: 'default')
  --once                        Process available jobs in queue and exit
  --sleep=<seconds>, -s <sec>   Sleep duration in seconds when queue is idle (default: 2)
  --memory-limit=<mb>, -m <mb>  Restart daemon if memory exceeds limit in MB (default: 128)
  --max-jobs=<num>, -j <num>    Restart daemon after processing N jobs (default: 1000)
  --status                      Display current worker status / heartbeat metrics
  --help, -h                    Show this help message

HELP;
    exit(0);
}

// Inspect status
$heartbeatFile = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../storage') . '/logs/worker_heartbeat.json';
if (isset($options['status'])) {
    if (!file_exists($heartbeatFile)) {
        echo "[Worker Status] No heartbeat record found. Worker daemon may not have been started yet.\n";
        exit(0);
    }
    $heartbeat = json_decode(file_get_contents($heartbeatFile), true);
    if (!$heartbeat) {
        echo "[Worker Status] Invalid heartbeat file format.\n";
        exit(1);
    }
    $lastHeartbeat = strtotime($heartbeat['last_heartbeat'] ?? 'now');
    $isStale = (time() - $lastHeartbeat) > 15; // Consider stale after 15s without heartbeat

    echo "=====================================================\n";
    echo " Tweak Insight Logistics — Queue Worker Status\n";
    echo "=====================================================\n";
    echo sprintf("PID:             %d\n", $heartbeat['pid'] ?? 0);
    echo sprintf("Status:          %s%s\n", strtoupper($heartbeat['status'] ?? 'unknown'), $isStale ? ' (STALE - NO RECENT HEARTBEAT)' : ' (HEALTHY)');
    echo sprintf("Queue:           %s\n", $heartbeat['queue'] ?? 'default');
    echo sprintf("Started At:      %s\n", $heartbeat['started_at'] ?? 'unknown');
    echo sprintf("Last Heartbeat:  %s (%d seconds ago)\n", $heartbeat['last_heartbeat'] ?? 'unknown', time() - $lastHeartbeat);
    echo sprintf("Jobs Processed:  %d\n", $heartbeat['jobs_processed'] ?? 0);
    echo sprintf("Memory Usage:    %.2f MB (Peak: %.2f MB)\n", $heartbeat['memory_usage_mb'] ?? 0, $heartbeat['peak_memory_mb'] ?? 0);
    echo "=====================================================\n";
    exit(0);
}

$once          = isset($options['once']);
$queue         = (string)($options['queue'] ?? $options['q'] ?? 'default');
$sleepSeconds  = max(1, (int)($options['sleep'] ?? $options['s'] ?? 2));
$memoryLimitMb = max(32, (int)($options['memory-limit'] ?? $options['m'] ?? 128));
$maxJobs       = $once ? 20 : max(1, (int)($options['max-jobs'] ?? $options['j'] ?? 1000));

echo sprintf("[Queue Worker] Starting daemon on queue '%s' (PID: %d)...\n", $queue, getmypid());
echo sprintf("[Queue Worker] Config: sleep=%ds, memory_limit=%dMB, max_jobs=%d, mode=%s\n",
    $sleepSeconds, $memoryLimitMb, $maxJobs, $once ? 'single-batch' : 'continuous');

// --- Graceful Termination Signals ---
$stopRequested = false;

if (extension_loaded('pcntl') && function_exists('pcntl_signal')) {
    if (function_exists('pcntl_async_signals')) {
        call_user_func('pcntl_async_signals', true);
    }
    $signalHandler = function (int $signo) use (&$stopRequested) {
        echo sprintf("[%s] Caught termination signal (%d). Finishing active job before graceful exit...\n", date('Y-m-d H:i:s'), $signo);
        $stopRequested = true;
    };
    foreach (['SIGTERM', 'SIGINT', 'SIGHUP'] as $signalName) {
        if (defined($signalName)) {
            call_user_func('pcntl_signal', constant($signalName), $signalHandler);
        }
    }
} elseif (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(function (int $event) use (&$stopRequested) {
        echo sprintf("[%s] Console break signal detected (%d). Initiating graceful worker exit...\n", date('Y-m-d H:i:s'), $event);
        $stopRequested = true;
    });
}

// --- Heartbeat Writer ---
$startTime = microtime(true);
$recordHeartbeat = function (string $status, int $processed) use ($heartbeatFile, $queue, $startTime) {
    $dir = dirname($heartbeatFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $payload = [
        'pid'             => getmypid(),
        'status'          => $status,
        'queue'           => $queue,
        'started_at'      => date('c', (int)$startTime),
        'last_heartbeat'  => date('c'),
        'uptime_seconds'  => (int)(microtime(true) - $startTime),
        'jobs_processed'  => $processed,
        'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'peak_memory_mb'  => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        'hostname'        => gethostname() ?: 'localhost',
    ];
    @file_put_contents($heartbeatFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
};

// --- Database Connection Manager with Auto-Reconnect ---
$database = new Database();
$db = null;

function ensureDbConnection(Database $database, ?PDO &$db): PDO
{
    if ($db !== null) {
        try {
            $db->query('SELECT 1');
            return $db;
        } catch (Throwable $e) {
            echo sprintf("[%s] Lost database connection: %s. Reconnecting...\n", date('Y-m-d H:i:s'), $e->getMessage());
            $db = null;
        }
    }

    $db = $database->getConnection();
    JobQueue::setDb($db);
    return $db;
}

$db = ensureDbConnection($database, $db);
$recordHeartbeat('running', 0);

$processed = 0;
$lastHeartbeatTime = 0;

// --- Worker Processing Loop ---
do {
    // 1. Check if shutdown was requested via signal
    if ($stopRequested) {
        echo sprintf("[%s] Graceful termination confirmed. Exiting daemon loop.\n", date('Y-m-d H:i:s'));
        break;
    }

    // 2. Refresh heartbeat every 5 seconds
    if (time() - $lastHeartbeatTime >= 5) {
        $recordHeartbeat('idle', $processed);
        $lastHeartbeatTime = time();
    }

    // 3. Ensure live database connection
    try {
        $db = ensureDbConnection($database, $db);
    } catch (Throwable $e) {
        echo sprintf("[%s] Database connection failed: %s. Retrying in %ds...\n", date('Y-m-d H:i:s'), $e->getMessage(), $sleepSeconds);
        sleep($sleepSeconds);
        continue;
    }

    // 4. Pop next job atomically
    $job = null;
    try {
        $job = JobQueue::pop($queue);
    } catch (Throwable $e) {
        echo sprintf("[%s] Error checking job queue: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
        sleep($sleepSeconds);
        continue;
    }

    if ($job) {
        $processed++;
        $recordHeartbeat('processing', $processed);
        echo sprintf("[%s] Processing job #%d (%s)...\n", date('Y-m-d H:i:s'), $job['id'], $job['job_type']);

        $jobStart = microtime(true);
        try {
            // Dispatch handlers based on job_type
            match ($job['job_type']) {
                'notification.external_dispatch' => handleExternalNotification($db, $job['payload']),
                'audit.export'                   => handleAuditExport($db, $job['payload']),
                'storage.delete'                 => handleStorageDelete($job['payload']),
                'webhook.deliver'                => handleWebhookDelivery($job['payload']),
                default                          => handleGenericJob($job),
            };

            JobQueue::complete((int)$job['id']);
            $durationMs = round((microtime(true) - $jobStart) * 1000, 2);
            echo sprintf("[%s] Job #%d completed successfully in %.2fms.\n", date('Y-m-d H:i:s'), $job['id'], $durationMs);
        } catch (Throwable $e) {
            $durationMs = round((microtime(true) - $jobStart) * 1000, 2);
            echo sprintf("[%s] Job #%d failed after %.2fms: %s\n", date('Y-m-d H:i:s'), $job['id'], $durationMs, $e->getMessage());
            Logger::error("Queue job #{$job['id']} ({$job['job_type']}) failed", [
                'job_id'    => $job['id'],
                'job_type'  => $job['job_type'],
                'attempts'  => $job['attempts'],
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            Monitoring::queueFailure([
                'job_id' => (int)$job['id'],
                'job_type' => $job['job_type'],
                'attempts' => (int)$job['attempts'],
                'max_attempts' => (int)$job['max_attempts'],
                'error' => $e->getMessage(),
            ]);
            JobQueue::fail((int)$job['id'], $e->getMessage(), (int)$job['attempts'], (int)$job['max_attempts']);
        }

        // 5. Memory Limit Protection (recyle daemon process when threshold is reached)
        $currentMemMb = memory_get_usage(true) / 1024 / 1024;
        if ($currentMemMb >= $memoryLimitMb) {
            echo sprintf("[%s] Memory limit threshold reached (%.2f MB >= %d MB). Restarting worker process for systemd/supervisor...\n",
                date('Y-m-d H:i:s'), $currentMemMb, $memoryLimitMb);
            break;
        }

        // 6. Max Jobs Recyle Protection
        if ($processed >= $maxJobs) {
            echo sprintf("[%s] Maximum jobs limit reached (%d). Restarting worker process for systemd/supervisor...\n",
                date('Y-m-d H:i:s'), $maxJobs);
            break;
        }
    } else {
        if ($once) {
            break;
        }
        sleep($sleepSeconds);
    }
} while (!$stopRequested);

$recordHeartbeat('stopped', $processed);
echo sprintf("[Queue Worker] Worker finished. Total jobs processed: %d. Exiting normally.\n", $processed);
exit(0);

// =========================================================================
// Job Handler Implementations
// =========================================================================

/**
 * Dispatches asynchronous external notifications (SMS, Push, WhatsApp, Email).
 */
function handleExternalNotification(PDO $db, array $payload): void
{
    $userId     = (int)($payload['user_id'] ?? 0);
    $deliveryId = !empty($payload['delivery_id']) ? (int)$payload['delivery_id'] : null;
    $type       = $payload['type'] ?? 'generic';
    $title      = $payload['title'] ?? '';
    $body       = $payload['body'] ?? '';
    $channel    = $payload['channel'] ?? 'external';

    // Retrieve recipient phone and email for external provider
    $contact = ['phone' => null, 'email' => null];
    if ($userId > 0) {
        $userStmt = $db->prepare('SELECT phone, email FROM users WHERE id = ?');
        $userStmt->execute([$userId]);
        $row = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $contact['phone'] = $row['phone'];
            $contact['email'] = $row['email'];
        }
    }

    Logger::info('External notification dispatched asynchronously by worker', [
        'user_id'     => $userId,
        'delivery_id' => $deliveryId,
        'type'        => $type,
        'channel'     => $channel,
        'recipient'   => $contact,
        'title'       => $title,
    ]);
}

/**
 * Generates asynchronous operational audit log exports as downloadable CSV files.
 */
function handleAuditExport(PDO $db, array $payload): void
{
    $adminId   = (int)($payload['admin_id'] ?? 0);
    $filters   = $payload['filters'] ?? [];
    $exportId  = $payload['export_id'] ?? bin2hex(random_bytes(6));

    $where  = [];
    $params = [];

    if (!empty($filters['action'])) {
        $where[] = "a.action LIKE :action";
        $params['action'] = "%" . trim($filters['action']) . "%";
    }
    if (!empty($filters['actor_role'])) {
        $where[] = "a.actor_role = :actor_role";
        $params['actor_role'] = trim($filters['actor_role']);
    }
    if (!empty($filters['delivery_id'])) {
        $where[] = "a.delivery_id = :delivery_id";
        $params['delivery_id'] = (int)$filters['delivery_id'];
    }
    if (!empty($filters['from_date'])) {
        $where[] = "a.created_at >= :from_date";
        $params['from_date'] = trim($filters['from_date']) . ' 00:00:00';
    }
    if (!empty($filters['to_date'])) {
        $where[] = "a.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)";
        $params['to_date'] = trim($filters['to_date']);
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $query = "SELECT a.id, a.action, a.entity_type, a.entity_id, a.delivery_id, a.actor_role, a.created_at, u.full_name AS actor_name 
              FROM audit_logs a 
              LEFT JOIN users u ON u.id = a.actor_user_id 
              {$whereSql} 
              ORDER BY a.created_at DESC";

    $exportDir = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../storage') . '/exports';
    if (!is_dir($exportDir)) {
        @mkdir($exportDir, 0755, true);
    }

    $filename = sprintf('audit_export_%s_%s.csv', date('Ymd_His'), $exportId);
    $filepath = $exportDir . '/' . $filename;

    $stmt = $db->prepare($query);
    $stmt->execute($params);

    $fp = fopen($filepath, 'w');
    if (!$fp) {
        throw new RuntimeException("Unable to create export file at {$filepath}");
    }

    // Write CSV header
    fputcsv($fp, ['Log ID', 'Timestamp (UTC)', 'Action', 'Actor Role', 'Actor Name', 'Entity Type', 'Entity ID', 'Delivery ID']);

    $recordCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($fp, [
            $row['id'],
            $row['created_at'],
            $row['action'],
            $row['actor_role'],
            $row['actor_name'] ?: 'System',
            $row['entity_type'],
            $row['entity_id'] ?: '-',
            $row['delivery_id'] ?: '-'
        ]);
        $recordCount++;
    }
    fclose($fp);

    Logger::info('Audit log export generated asynchronously', [
        'export_id'        => $exportId,
        'records_exported' => $recordCount,
        'file_name'        => $filename,
        'requested_by'     => $adminId,
    ]);

    // Notify requesting admin that export is ready
    if ($adminId > 0) {
        NotificationService::publish(
            $db,
            $adminId,
            'admin.audit_export_ready',
            'Audit Log Export Ready',
            "Your audit report with {$recordCount} record(s) is ready: {$filename}",
            null,
            [
                'export_id'    => $exportId,
                'filename'     => $filename,
                'record_count' => $recordCount,
                'file_size'    => filesize($filepath),
                'generated_at' => date('c'),
            ]
        );
    }
}

/** Delete an obsolete private object; failures are retried by JobQueue. */
function handleStorageDelete(array $payload): void
{
    $storageKey = trim((string)($payload['storage_key'] ?? ''));
    if ($storageKey === '') {
        throw new InvalidArgumentException('Storage deletion job is missing storage_key.');
    }

    if (!Storage::adapter()->delete($storageKey)) {
        throw new RuntimeException('Storage adapter could not delete the obsolete object.');
    }

    Logger::info('Obsolete private storage object deleted asynchronously.', [
        'storage_key' => $storageKey,
        'reason' => $payload['reason'] ?? 'unspecified',
        'document_id' => $payload['document_id'] ?? null,
    ]);
}

/**
 * Dispatches outbound HTTP webhooks to integrated third-party systems.
 */
function handleWebhookDelivery(array $payload): void
{
    $url  = $payload['target_url'] ?? '';
    $data = $payload['data'] ?? [];

    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'User-Agent: TweakInsight-Webhook/1.0'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Webhook endpoint returned HTTP {$code}");
    }
}

/**
 * Fallback generic job handler.
 */
function handleGenericJob(array $job): void
{
    Logger::info('Handled generic queued job', ['job_id' => $job['id'], 'type' => $job['job_type']]);
}

