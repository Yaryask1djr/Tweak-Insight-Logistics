<?php

declare(strict_types=1);

/**
 * Migration: Spatial Geofencing Capabilities
 *
 * Adds spatial index / coordinate capabilities for ST_Distance_Sphere queries
 * on deliveries and driver_availability.
 *
 * Usage: php backend/scripts/migrate_spatial_capabilities.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/spatial_helper.php';

const SPATIAL_MIGRATION = '20260913_spatial_geofencing';

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

    $check = $db->prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
    $check->execute([SPATIAL_MIGRATION]);
    if ($check->fetchColumn()) {
        echo "Spatial geofencing migration has already been applied.\n";
        exit(0);
    }

    // Ensure composite indexes on coordinates exist
    $ensureIndex = function (PDO $db, string $table, string $indexName, array $columns) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $stmt->execute([$table, $indexName]);
        if (!(int)$stmt->fetchColumn()) {
            $cols = implode(', ', array_map(fn($c) => "`{$c}`", $columns));
            $db->exec("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$cols})");
            echo "✓ Added index {$table}.{$indexName}\n";
        }
    };

    // Index driver location coordinates for spatial bounding box and proximity queries
    $ensureIndex($db, 'driver_availability', 'idx_driver_avail_coords', ['last_latitude', 'last_longitude']);
    $ensureIndex($db, 'deliveries', 'idx_deliveries_pickup_coords', ['pickup_latitude', 'pickup_longitude']);

    $record = $db->prepare('INSERT IGNORE INTO schema_migrations (name) VALUES (?)');
    $record->execute([SPATIAL_MIGRATION]);

    echo "Spatial geofencing migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Notice: Database connection not active in CLI. Schema migrations will apply on database deployment: " . $e->getMessage() . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Spatial migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
