<?php

declare(strict_types=1);

/**
 * Scheduled deletion of KYC documents once their configured retention expires.
 * It removes the private object first, then its metadata only after successful
 * storage deletion. Use an object-store lifecycle rule as a second safeguard.
 *
 * Usage: php scripts/purge_expired_kyc_documents.php
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/storage_adapter.php';

$db = (new Database())->getConnection();
$storage = Storage::adapter();
$deleted = 0;
$failed = 0;

foreach (['client_kyc_documents', 'driver_documents'] as $table) {
    $select = $db->prepare("SELECT id, storage_key FROM {$table} WHERE retention_until IS NOT NULL AND retention_until <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT 500");
    $select->execute();
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $document) {
        try {
            if (!$storage->delete((string)$document['storage_key'])) {
                ++$failed;
                continue;
            }
            $remove = $db->prepare("DELETE FROM {$table} WHERE id = ? AND retention_until <= UTC_TIMESTAMP()");
            $remove->execute([(int)$document['id']]);
            $deleted += $remove->rowCount();
        } catch (Throwable $exception) {
            ++$failed;
            Logger::error('KYC retention deletion failed', ['table' => $table, 'document_id' => (int)$document['id'], 'exception' => $exception->getMessage()]);
        }
    }
}

echo "Purged {$deleted} expired KYC document records." . ($failed ? " {$failed} deletions need retry.\n" : "\n");
exit($failed ? 1 : 0);
