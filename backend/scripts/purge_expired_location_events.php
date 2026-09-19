<?php
/** Retain GPS samples for 90 days; delivery-level final coordinates remain intact. */
require_once __DIR__ . '/../bootstrap.php'; require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$removed = $db->exec("DELETE FROM delivery_location_events WHERE recorded_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)");
echo "Purged {$removed} location events older than 90 days.\n";
