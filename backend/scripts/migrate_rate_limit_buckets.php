<?php

declare(strict_types=1);

/**
 * Deployment-only migration for the atomic database rate-limit fallback.
 *
 * Usage: php scripts/migrate_rate_limit_buckets.php
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
$migration = '2026_09_01_rate_limit_buckets';

$db->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_schema_migrations_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$alreadyApplied = $db->prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
$alreadyApplied->execute([$migration]);
if ($alreadyApplied->fetchColumn()) {
    echo "Rate-limit bucket migration is already applied.\n";
    exit(0);
}

try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS rate_limit_buckets (
            rate_key CHAR(64) NOT NULL PRIMARY KEY,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            window_expires_at INT UNSIGNED NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_rate_limit_bucket_expiry (window_expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $record = $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
    $record->execute([$migration]);
    echo "Rate-limit bucket migration applied.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Rate-limit bucket migration failed: {$exception->getMessage()}\n");
    exit(1);
}
