<?php

/** Shared helpers for the additive operations tables. */
final class OperationsSchema
{
    /** @var array<int, array<string, bool>> */
    private static array $dbTables = [];

    public static function hasTable(PDO $db, string $table): bool
    {
        $dbId = spl_object_id($db);
        $normalized = strtolower($table);

        if (!isset(self::$dbTables[$dbId])) {
            try {
                $statement = $db->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()');
                $existing = $statement ? $statement->fetchAll(PDO::FETCH_COLUMN) : [];
                self::$dbTables[$dbId] = array_fill_keys(array_map('strtolower', $existing), true);
            } catch (Throwable $_) {
                self::$dbTables[$dbId] = [];
            }
        }

        if (array_key_exists($normalized, self::$dbTables[$dbId])) {
            return self::$dbTables[$dbId][$normalized];
        }

        // Fallback check if table was created dynamically during a test or migration
        try {
            $statement = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $statement->execute([$table]);
            $exists = (bool)$statement->fetchColumn();
            self::$dbTables[$dbId][$normalized] = $exists;
            return $exists;
        } catch (Throwable $_) {
            return false;
        }
    }

    /** @param string[] $tables */
    public static function requireTables(PDO $db, array $tables): void
    {
        foreach ($tables as $table) {
            if (!self::hasTable($db, $table)) {
                Response::error('Operations data is unavailable until the operational database migration is applied.', 503);
            }
        }
    }

    public static function ensureDriverProfile(PDO $db, int $userId): ?int
    {
        if (!self::hasTable($db, 'drivers')) return null;
        $statement = $db->prepare('INSERT IGNORE INTO drivers (user_id) VALUES (?)');
        $statement->execute([$userId]);
        $driver = $db->prepare('SELECT id FROM drivers WHERE user_id = ? LIMIT 1');
        $driver->execute([$userId]);
        $driverId = (int)$driver->fetchColumn();
        if ($driverId && self::hasTable($db, 'driver_availability')) {
            $db->prepare("INSERT IGNORE INTO driver_availability (driver_id, availability_status) VALUES (?, 'offline')")->execute([$driverId]);
        }
        return $driverId ?: null;
    }

    public static function ensureClientProfile(PDO $db, int $userId): ?int
    {
        if (!self::hasTable($db, 'clients')) return null;
        $db->prepare("INSERT IGNORE INTO clients (user_id, client_type) VALUES (?, 'individual')")->execute([$userId]);
        $statement = $db->prepare('SELECT id FROM clients WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);
        $id = (int)$statement->fetchColumn();
        return $id ?: null;
    }

    public static function driverIdForUser(PDO $db, int $userId): ?int
    {
        if (!self::hasTable($db, 'drivers')) return null;
        $statement = $db->prepare('SELECT id FROM drivers WHERE user_id = ? LIMIT 1');
        $statement->execute([$userId]);
        $id = (int)$statement->fetchColumn();
        return $id ?: null;
    }
}
