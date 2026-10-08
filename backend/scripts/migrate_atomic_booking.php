<?php

declare(strict_types=1);

/** Run once per environment, including fresh installs, using migration credentials. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/delivery_booking.php';

$db = (new Database())->getConnection();
$lock = 'til_booking_' . substr(hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
$acquire = $db->prepare('SELECT GET_LOCK(?, 10)');
$acquire->execute([$lock]);
if ((int)$acquire->fetchColumn() !== 1) { fwrite(STDERR, "Another booking migration is running.\n"); exit(1); }
$exitCode = 0;
try {
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    foreach (['booking_requests', 'delivery_booking_snapshots', 'booking_outbox'] as $table) {
        if ($schema === false || !preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \([\s\S]*?\) ENGINE=InnoDB[^;]*;/', $schema, $match)) {
            throw new RuntimeException('Booking schema missing: ' . $table);
        }
        $db->exec($match[0]);
    }
    DeliveryBooking::assertTransactionalSchema($db);
    // A single-statement trigger needs no client-specific DELIMITER syntax.
    foreach (['UPDATE', 'DELETE'] as $action) {
        $name = 'booking_snapshot_no_' . strtolower($action);
        $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
        $exists->execute([$name]);
        if (!(int)$exists->fetchColumn()) {
            $db->exec("CREATE TRIGGER {$name} BEFORE {$action} ON delivery_booking_snapshots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Original booking snapshots are immutable'");
        }
    }
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL UNIQUE, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $db->exec("INSERT INTO schema_migrations (name) VALUES ('2026_09_20_atomic_booking') ON DUPLICATE KEY UPDATE name = VALUES(name)");
    echo "Atomic booking tables and snapshot protection are ready.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Booking migration failed: {$error->getMessage()}\n");
    $exitCode = 1;
} finally {
    $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
}
exit($exitCode);
