<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../helpers/maintenance_runner.php';

$jobs = [
    'purge_expired_auth_refresh_sessions.php',
    'purge_expired_rate_limit_buckets.php',
    'purge_expired_kyc_documents.php',
    'purge_expired_location_events.php',
    'expire_report_exports.php',
    'purge_stale_file_cache.php',
];
echo 'Maintenance started at ' . gmdate('c') . PHP_EOL;
$result = MaintenanceRunner::run($jobs,
    static fn(string $script): int => MaintenanceRunner::process(__DIR__ . '/' . $script),
    static function (string $script, int $code): void {
        echo ($code === 0 ? '[PASS] ' : '[FAIL] ') . $script . ' (exit ' . $code . ')' . PHP_EOL;
    }
);
echo 'Maintenance finished at ' . gmdate('c') . PHP_EOL;
exit($result);
