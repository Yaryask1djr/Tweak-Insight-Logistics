<?php

require_once __DIR__ . '/../helpers/notification_service.php';

$catalog = NotificationService::eventCatalog();
$required = [
    'client.request_submitted',
    'client.request_reviewed',
    'client.driver_assigned',
    'client.pickup_completed',
    'client.delivery_in_progress',
    'client.delivery_completed',
    'client.request_cancelled',
    'driver.new_delivery_offer',
    'driver.assignment_confirmed',
    'driver.delivery_change',
    'driver.document_issue',
    'admin.new_delivery_request',
    'admin.driver_accepted',
    'admin.delivery_completed',
    'admin.driver_document_issue',
];

foreach ($required as $event) {
    if (!isset($catalog[$event])) {
        fwrite(STDERR, "Missing notification event: {$event}\n");
        exit(1);
    }
}

echo "Notification catalog checks passed.\n";
