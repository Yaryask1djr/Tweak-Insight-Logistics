<?php

declare(strict_types=1);

/** Additive deployment migration. Stop old workers first; use migration credentials. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
$migration = '2026_09_19_job_queue_reservations';
$lock = 'til_queue_migration_' . substr(hash('sha256', (string)$db->query('SELECT DATABASE()')->fetchColumn()), 0, 32);
$acquire = $db->prepare('SELECT GET_LOCK(?, 10)');
$acquire->execute([$lock]);
if ((int)$acquire->fetchColumn() !== 1) {
    fwrite(STDERR, "Another queue migration is running.\n");
    exit(1);
}
$exitCode = 0;
try {
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_schema_migrations_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // Use the canonical fresh-install table definition, including both indexes.
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false || !preg_match('/CREATE TABLE IF NOT EXISTS job_queue \([\s\S]*?\) ENGINE=InnoDB[^;]*;/', $schema, $match)) {
        throw new RuntimeException('Canonical job_queue schema could not be read.');
    }
    $db->exec($match[0]);
    $columns = $db->query('SHOW COLUMNS FROM job_queue')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reservation_token', $columns, true)) {
        $db->exec('ALTER TABLE job_queue ADD COLUMN reservation_token CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER reserved_at');
    }
    if (!in_array('reserved_until', $columns, true)) {
        $db->exec('ALTER TABLE job_queue ADD COLUMN reserved_until DATETIME(6) NULL AFTER reservation_token');
    }
    $indexes = $db->query('SHOW INDEX FROM job_queue')->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('idx_job_reservation_expiry', array_column($indexes, 'Key_name'), true)) {
        $db->exec('ALTER TABLE job_queue ADD INDEX idx_job_reservation_expiry (queue_name, status, reserved_until)');
    }
    // DDL is not transactional in MySQL. Every step is safe to rerun after interruption.
    $record = $db->prepare('INSERT INTO schema_migrations (name) VALUES (?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
    $record->execute([$migration]);
    echo "Queue reservation migration applied (or already present).\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Queue migration failed: {$e->getMessage()}\n");
    $exitCode = 1;
} finally {
    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lock]);
}
exit($exitCode);
