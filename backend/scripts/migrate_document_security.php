<?php

declare(strict_types=1);

/**
 * Adds retention metadata to KYC document tables and backfills the date from
 * the approved deployment policy. Run once before deploying secure uploads.
 *
 * Usage: php scripts/migrate_document_security.php
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/upload_security.php';

$db = (new Database())->getConnection();
$migration = '2026_09_02_document_security_lifecycle';
$retentionDays = UploadSecurity::documentRetentionDays();

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
    echo "Document-security migration is already applied.\n";
    exit(0);
}

try {
    foreach ([
        'client_kyc_documents' => 'idx_client_kyc_documents_retention',
        'driver_documents' => 'idx_driver_documents_retention',
    ] as $table => $index) {
        $column = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = \'retention_until\''
        );
        $column->execute([$table]);
        if (!(int)$column->fetchColumn()) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN retention_until DATETIME NULL AFTER updated_at");
        }

        $indexExists = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $indexExists->execute([$table, $index]);
        if (!(int)$indexExists->fetchColumn()) {
            $db->exec("ALTER TABLE {$table} ADD INDEX {$index} (retention_until)");
        }

        $backfill = $db->prepare("UPDATE {$table} SET retention_until = DATE_ADD(created_at, INTERVAL {$retentionDays} DAY) WHERE retention_until IS NULL");
        $backfill->execute();
    }

    $record = $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
    $record->execute([$migration]);
    echo "Document-security migration applied with a {$retentionDays}-day retention policy.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Document-security migration failed: {$exception->getMessage()}\n");
    exit(1);
}
