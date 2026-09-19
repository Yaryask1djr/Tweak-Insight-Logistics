<?php

declare(strict_types=1);

/**
 * Lightweight watchdog for the queue worker daemon.
 *
 *  - If the worker heartbeat JSON (storage/logs/worker_heartbeat.json) is older
 *    than 120 seconds, this script attempts to restart the worker via systemctl
 *    or supervisorctl, depending on deploy mode.
 *  - When running in environments WITHOUT systemd/supervisor (e.g. Windows dev / shared
 *    server) this is called by the above crontab every 5 min.
 *
 * php scripts/ensure_worker_running.php
 */

require_once __DIR__ . '/../bootstrap.php';

$heartbeatPath = STORAGE_PATH . '/logs/worker_heartbeat.json';
$maxStaleness = 120;  // seconds
$healthy = false;

if (file_exists($heartbeatPath)) {
    $json = @json_decode(file_get_contents($heartbeatPath), true);
    if (is_array($json) && isset($json['updated_at_unix'])) {
        $age = time() - (int)$json['updated_at_unix'];
        $healthy = $age <= $maxStaleness;
    }
}

if ($healthy) {
    echo '[OK] Queue worker heartbeat is fresh.' . PHP_EOL;
    exit(0);
}

echo '[WARN] Queue worker heartbeat missing or stale — attempting restart...' . PHP_EOL;

// Prefer systemd
if (command_exists('systemctl')) {
    @exec('systemctl is-active --quiet til-queue-worker.service 2>/dev/null', $out, $code);
    if ($code !== 0) {
        @exec('sudo -n systemctl restart til-queue-worker.service 2>&1', $restartOut, $restartCode);
        echo 'systemctl restart exited with code ' . $restartCode . PHP_EOL;
        echo implode(PHP_EOL, $restartOut) . PHP_EOL;
        exit(0);
    }
}

// Supervisor fallback
if (command_exists('supervisorctl')) {
    @exec('supervisorctl status til-queue-worker 2>/dev/null', $status, $code);
    @exec('supervisorctl restart til-queue-worker:* 2>&1', $restartOut, $restartCode);
    echo 'supervisorctl restart exited with code ' . $restartCode . PHP_EOL;
    echo implode(PHP_EOL, $restartOut) . PHP_EOL;
    exit(0);
}

// Worst case: just start a new background worker (no daemon supervisor)
$php = PHP_BINARY;
$script = escapeshellarg(__DIR__ . '/queue_worker.php');
$logFile = STORAGE_PATH . '/logs/worker-respawn.log';
$cmd = sprintf('nohup %s %s >> %s 2>&1 &', $php, $script, escapeshellarg($logFile));
@exec($cmd);
echo 'Started detached worker via nohup: ' . $cmd . PHP_EOL;
exit(0);

function command_exists(string $cmd): bool {
    $result = shell_exec('command -v ' . escapeshellarg($cmd) . ' 2>/dev/null');
    return !empty(trim((string)$result));
}
