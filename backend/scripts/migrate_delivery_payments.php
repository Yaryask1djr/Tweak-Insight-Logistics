<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/delivery_payments.php';
$db = (new Database())->getConnection();
$lock = 'til_payments_' . substr(hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
$acquire = $db->prepare('SELECT GET_LOCK(?, 10)'); $acquire->execute([$lock]);
if ((int)$acquire->fetchColumn() !== 1) { fwrite(STDERR, "Another payment migration is running.\n"); exit(1); }
$exitCode = 0;
try {
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    foreach (['delivery_fare_approvals','delivery_payment_attempts','delivery_payment_receipts','payment_webhook_events'] as $table) {
        if ($schema === false || !preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \([\s\S]*?\) ENGINE=InnoDB[^;]*;/', $schema, $match)) throw new RuntimeException('Payment schema missing: ' . $table);
        $db->exec($match[0]);
    }
    DeliveryPayments::assertSchema($db);
    foreach (['fare' => 'delivery_fare_approvals', 'receipt' => 'delivery_payment_receipts'] as $kind => $table) {
        foreach (['UPDATE','DELETE'] as $action) {
            $name = 'payment_' . $kind . '_no_' . strtolower($action);
            $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?'); $exists->execute([$name]);
            if (!(int)$exists->fetchColumn()) $db->exec("CREATE TRIGGER {$name} BEFORE {$action} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved fares and payment receipts are immutable'");
        }
    }
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL UNIQUE, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $db->exec("INSERT INTO schema_migrations (name) VALUES ('2026_09_20_fares_and_payments') ON DUPLICATE KEY UPDATE name = VALUES(name)");
    echo "Payment schema and immutable record protection are ready. Existing paid flags have NOT been converted into receipts.\n";
} catch (Throwable $e) { fwrite(STDERR, 'Payment migration failed: ' . $e->getMessage() . "\n"); $exitCode = 1; }
finally { $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
exit($exitCode);
