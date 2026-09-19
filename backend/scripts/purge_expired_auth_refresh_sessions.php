<?php

declare(strict_types=1);

/**
 * Scheduled maintenance for expired/revoked refresh-session records.
 *
 * Usage: php scripts/purge_expired_auth_refresh_sessions.php
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
$statement = $db->prepare(
    'DELETE FROM auth_refresh_sessions
     WHERE expires_at < UTC_TIMESTAMP()
        OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY))'
);
$statement->execute();
echo "Purged {$statement->rowCount()} expired or stale refresh sessions.\n";
