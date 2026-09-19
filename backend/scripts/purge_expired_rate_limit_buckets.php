<?php

declare(strict_types=1);

/**
 * Remove expired development database rate-limit buckets outside request paths.
 *
 * Run daily from the deployment scheduler when RATE_LIMIT_DRIVER=database.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$db = (new Database())->getConnection();
$statement = $db->prepare('DELETE FROM rate_limit_buckets WHERE window_expires_at < ?');
$statement->execute([time()]);

echo "Purged {$statement->rowCount()} expired rate-limit bucket(s).\n";
