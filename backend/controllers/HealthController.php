<?php
require_once __DIR__ . '/../helpers/job_queue.php';
require_once __DIR__ . '/../helpers/storage_adapter.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/delivery_payments.php';

final class HealthController
{
    public static function check(callable $connect): void
    {
        $expected = (string)getenv('HEALTH_CHECK_TOKEN');
        $provided = $_SERVER['HTTP_X_HEALTH_TOKEN'] ?? '';
        if (strlen($expected) < 32 || str_starts_with($expected, 'REPLACE_') || !is_string($provided) || !hash_equals($expected, $provided)) {
            Response::forbidden('Readiness access requires a configured health token.');
        }
        $checks = self::inspect($connect);
        $ready = !in_array(false, array_column($checks, 'ok'), true);
        Response::json(['ready' => $ready, 'checks' => $checks, 'external_notifications' => 'unconfigured'], 'Dependency readiness.', $ready ? 200 : 503);
    }

    public static function inspect(callable $connect): array
    {
        $checks = [];
        try {
            $db = $connect();
            $db->query('SELECT 1');
            $db->query('SELECT response_json FROM booking_requests LIMIT 0');
            $db->query('SELECT snapshot_hash FROM delivery_booking_snapshots LIMIT 0');
            $db->query('SELECT reservation_token FROM report_exports LIMIT 0');
            $triggers = (int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('booking_snapshot_no_update', 'booking_snapshot_no_delete')")->fetchColumn();
            if ($triggers !== 2) throw new RuntimeException('Booking snapshot protection is missing.');
            $checks['database'] = ['ok' => true];
            $checks['queue'] = JobQueue::readiness($db);
            $failed = (int)$db->query("SELECT COUNT(*) FROM booking_outbox WHERE status = 'failed'")->fetchColumn();
            $checks['booking_outbox'] = ['ok' => $failed === 0, 'failed' => $failed];
            DeliveryPayments::assertSchema($db);
            $db->query('SELECT input_hash FROM delivery_fare_approvals LIMIT 0');
            $db->query('SELECT evidence_hash FROM delivery_payment_receipts LIMIT 0');
            $protected = (int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('payment_fare_no_update','payment_fare_no_delete','payment_receipt_no_update','payment_receipt_no_delete')")->fetchColumn();
            $checks['payment_immutability'] = ['ok' => $protected === 4];
            $failed = (int)$db->query("SELECT COUNT(*) FROM payment_webhook_events WHERE status = 'failed'")->fetchColumn();
            $stale = (int)$db->query("SELECT COUNT(*) FROM payment_webhook_events WHERE status IN ('pending','processing') AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 MINUTE)")->fetchColumn();
            $checks['payment_inbox'] = ['ok' => $failed === 0 && $stale === 0, 'failed' => $failed, 'stale' => $stale];
        } catch (Throwable $error) {
            $checks['database_or_queue'] = ['ok' => false];
            Logger::warning('Readiness database/queue probe failed', ['error' => $error->getMessage()]);
        }
        // Production rate limiting needs Redis even with a MySQL queue.
        try {
            if (JobQueue::driver() !== 'redis' && (getenv('REDIS_HOST') || strtolower((string)getenv('APP_ENV')) === 'production')) {
                $checks['redis'] = JobQueue::redisReadiness();
            }
        } catch (Throwable $e) { $checks['redis'] = ['ok' => false]; }
        try { new PaystackGateway(); $checks['payments_configured'] = ['ok' => function_exists('curl_init')]; }
        catch (PaymentConfigurationException $e) { $checks['payments_configured'] = ['ok' => false]; }
        $storage = defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../storage';
        try {
            if (!is_dir($storage) || !is_writable($storage)) throw new RuntimeException('Storage unavailable.');
            $probe = tempnam($storage, 'readiness-');
            if ($probe === false) throw new RuntimeException('Cannot create storage probe.');
            try {
                if (file_put_contents($probe, 'probe', LOCK_EX) !== 5 || file_get_contents($probe) !== 'probe') throw new RuntimeException('Storage round trip failed.');
            } finally { unlink($probe); }
            if ((getenv('STORAGE_DRIVER') ?: 'local') === 's3') {
                $key = (string)getenv('HEALTH_STORAGE_PROBE_KEY');
                if ($key === '' || !Storage::adapter()->exists($key)) throw new RuntimeException('Object storage probe unavailable.');
            }
            $checks['storage'] = ['ok' => true];
        } catch (Throwable $e) { $checks['storage'] = ['ok' => false]; }
        $file = $storage . '/logs/worker_heartbeat.json';
        $raw = is_readable($file) ? file_get_contents($file) : '';
        $checks['worker'] = self::workerStatus(json_decode($raw ?: '{}', true) ?: [], time());
        return $checks;
    }

    public static function workerStatus(array $heartbeat, int $now): array
    {
        $age = $now - (int)($heartbeat['updated_at_unix'] ?? 0);
        return ['ok' => $age >= 0 && $age <= 120 && ($heartbeat['queue'] ?? '') === 'default'
            && in_array($heartbeat['status'] ?? '', ['running', 'idle', 'processing'], true), 'age_seconds' => $age];
    }
}
