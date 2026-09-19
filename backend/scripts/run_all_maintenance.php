<?php

declare(strict_types=1);

/**
 * Master orchestrator for every nighty / systemd timer scheduled maintenance jobs.
 *
 *   php scripts/run_all_maintenance.php
 *
 * This script is safe to re-entrantly run (re-runs are idempotent).
 *
 * ---------- ORDER MATTERS ----------
 * 1. DB purges of session/rate-limit data (lowest risk, usually)
 * 2. KYC document purge (storage + DB)
 * 3. Location events purge (high-volume, biggest table)
 * 4. Optional log/cache/export cleanup (storage only)
 */

require_once __DIR__ . '/../bootstrap.php';

$jobs = [
    'purge_expired_auth_refresh_sessions.php' => 'Expired refresh sessions',
    'purge_expired_rate_limit_buckets.php'   => 'Expired rate-limit buckets',
    'purge_expired_kyc_documents.php'       => 'Stale KYC uploads',
    'purge_expired_location_events.php'        => 'Old GPS location events',
];

$start = microtime(true);
$totalPurged = 0;

echo '======================================================================' . PHP_EOL;
echo ' TIL Maintenance run started at ' . gmdate('c') . ' UTC' . PHP_EOL;
echo '======================================================================' . PHP_EOL;

foreach ($jobs as $script => $label) {
    $path = __DIR__ . '/' . $script;
    if (!file_exists($path)) {
        echo ' [SKIP] ' . $label . ' — script missing: ' . $script . PHP_EOL;
        continue;
    }
    echo PHP_EOL . ' [RUN ] ' . $label . '...' . PHP_EOL;

    require $path;
    flush();
}

// --------------------------------------------------------------------------
// Additional storage-level purges not covered by the individual scripts above
// --------------------------------------------------------------------------
echo PHP_EOL . ' [RUN ] Storage cleanup — old exports, stale file cache...' . PHP_EOL;

$exportsDir = STORAGE_PATH . '/exports';
if (is_dir($exportsDir)) {
    $cutoff = time() - (30 * 24 * 60 * 60); // 30 days
    $culled = 0;
    foreach (glob($exportsDir . '/*.csv') as $csv) {
        if (filemtime($csv) < $cutoff && unlink($csv)) {
            $culled++;
        }
    }
    echo '         Deleted ' . $culled . ' CSV export(s) older than 30 days.' . PHP_EOL;
}

$cacheDir = STORAGE_PATH . '/cache';
if (is_dir($cacheDir)) {
    $cutoff = time() - (7 * 24 * 60 * 60); // 7 days
    $culled = 0;
    foreach (glob($cacheDir . '/*.json') as $cacheFile) {
        if (filemtime($cacheFile) < $cutoff && unlink($cacheFile)) {
            $culled++;
        }
    }
    echo '         Deleted ' . $culled . ' stale file-cache JSON entries older than 7 days.' . PHP_EOL;
}

$elapsed = number_format(microtime(true) - $start, 3);
echo PHP_EOL . '======================================================================' . PHP_EOL;
echo ' Maintenance complete — elapsed: ' . $elapsed . 's' . PHP_EOL;
echo '======================================================================' . PHP_EOL;
