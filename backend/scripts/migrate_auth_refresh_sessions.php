<?php

declare(strict_types=1);

/**
 * Deployment-only migration for opaque, rotating HttpOnly refresh sessions.
 *
 * Usage: php scripts/migrate_auth_refresh_sessions.php
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
$migration = '2026_09_01_auth_refresh_sessions';

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
    echo "Refresh-session migration is already applied.\n";
    exit(0);
}

try {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS auth_refresh_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            family_id CHAR(32) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            token_version INT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            revoked_reason VARCHAR(64) NULL,
            created_ip VARBINARY(16) NULL,
            user_agent_hash CHAR(64) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_auth_refresh_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uq_auth_refresh_sessions_token_hash (token_hash),
            INDEX idx_auth_refresh_sessions_user_active (user_id, revoked_at, expires_at),
            INDEX idx_auth_refresh_sessions_expiry (expires_at),
            INDEX idx_auth_refresh_sessions_family (family_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $record = $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
    $record->execute([$migration]);
    echo "Refresh-session migration applied.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Refresh-session migration failed: {$exception->getMessage()}\n");
    exit(1);
}
