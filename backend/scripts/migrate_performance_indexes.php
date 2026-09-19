<?php
/**
 * Adds only query-serving indexes to an existing Tweak Insight Logistics DB.
 *
 * Safe to run repeatedly. It never deletes data, rebuilds tables, or changes
 * a primary/unique key. The target indexes mirror real endpoint predicates and
 * ordering: client history, driver assignments/offers, admin operations,
 * public tracking, and notification inbox reads.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

const PERFORMANCE_INDEX_MIGRATION = '20260825_performance_indexes';

function tablePresent(PDO $db, string $table): bool
{
    $statement = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);
    return (bool)$statement->fetchColumn();
}

/** @param string[] $columns */
function indexWithColumnsExists(PDO $db, string $table, array $columns): bool
{
    $statement = $db->prepare(
        'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ",") AS index_columns
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         GROUP BY INDEX_NAME'
    );
    $statement->execute([$table]);
    $expected = implode(',', $columns);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
        if (($index['index_columns'] ?? '') === $expected) {
            return true;
        }
    }
    return false;
}

/** @param string[] $columns */
function ensureIndex(PDO $db, string $table, string $name, array $columns): void
{
    if (!tablePresent($db, $table) || indexWithColumnsExists($db, $table, $columns)) {
        return;
    }
    $quotedColumns = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $columns));
    $db->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$quotedColumns})");
    echo "✓ Added {$table}.{$name} (" . implode(', ', $columns) . ")\n";
}

try {
    $db = (new Database())->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_schema_migrations_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    ensureIndex($db, 'users', 'idx_users_role_approved', ['role', 'is_approved']);
    ensureIndex($db, 'users', 'idx_users_role_approved_id', ['role', 'is_approved', 'id']);
    ensureIndex($db, 'users', 'idx_users_role_created', ['role', 'created_at']);

    ensureIndex($db, 'deliveries', 'idx_deliveries_tracking_number', ['tracking_number']);
    ensureIndex($db, 'deliveries', 'idx_deliveries_client_time', ['client_id', 'request_time']);
    ensureIndex($db, 'deliveries', 'idx_deliveries_client_status_time', ['client_id', 'status', 'request_time']);
    ensureIndex($db, 'deliveries', 'idx_deliveries_client_status_id', ['client_id', 'status', 'id']);
    ensureIndex($db, 'deliveries', 'idx_deliveries_driver_time', ['delivery_person_id', 'request_time']);
    ensureIndex($db, 'deliveries', 'idx_deliveries_driver_status_time', ['delivery_person_id', 'status', 'request_time']);
    ensureIndex($db, 'deliveries', 'idx_deliveries_driver_status_id', ['delivery_person_id', 'status', 'id']);
    ensureIndex($db, 'deliveries', 'idx_deliveries_status_time', ['status', 'request_time']);
    ensureIndex($db, 'deliveries', 'idx_deliveries_status_id', ['status', 'id']);
    ensureIndex($db, 'deliveries', 'idx_deliveries_offer_lookup', ['status', 'delivery_person_id', 'pickup_city', 'delivery_city', 'request_time']);

    ensureIndex($db, 'notifications', 'idx_notifications_inbox', ['user_id', 'delivery_status', 'created_at']);
    ensureIndex($db, 'notifications', 'idx_notifications_delivery', ['delivery_id', 'created_at']);
    ensureIndex($db, 'delivery_location_events', 'idx_location_events_delivery_time', ['delivery_id', 'recorded_at']);
    ensureIndex($db, 'delivery_location_events', 'idx_location_events_delivery_id', ['delivery_id', 'id']);

    $record = $db->prepare('INSERT IGNORE INTO schema_migrations (name) VALUES (?)');
    $record->execute([PERFORMANCE_INDEX_MIGRATION]);
    echo "Performance index migration complete.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Performance index migration failed: {$exception->getMessage()}\n");
    exit(1);
}
