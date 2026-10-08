<?php
declare(strict_types=1);
require_once __DIR__ . '/database_transaction.php';

final class TransactionalSchema
{
    public static function requireInnoDB(PDO $db, array $tables): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return; // SQLite test fixtures.
        $query = $db->prepare('SELECT TABLE_NAME, ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
        $query->execute($tables);
        $engines = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($tables as $table) {
            if (strcasecmp($engines[$table] ?? '', 'InnoDB') !== 0) throw new TransactionBusinessException('The required transactional schema is unavailable. Contact operations.', 503);
        }
    }
}
