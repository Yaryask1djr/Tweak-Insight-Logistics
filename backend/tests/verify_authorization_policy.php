<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/authorization_policy.php';

$cases = [
    ['admin', 'operations.drivers.manage', true],
    ['client', 'operations.drivers.read', false],
    ['delivery', 'operations.clients.read', false],
    ['client', 'delivery.request.create', true],
    ['delivery', 'delivery.request.create', false],
    ['delivery', 'driver.assignment.update_status', true],
    ['client', 'driver.assignment.update_status', false],
    ['delivery', 'driver.availability.manage_own', true],
    ['client', 'driver.availability.manage_own', false],
    ['delivery', 'driver.documents.manage_own', true],
    ['client', 'driver.documents.manage_own', false],
    ['client', 'notifications.read_own', true],
    ['delivery', 'notifications.read_own', true],
    ['client', 'notifications.read_other_user', false],
];

foreach ($cases as [$role, $permission, $expected]) {
    $actual = AuthorizationPolicy::can($role, $permission);
    if ($actual !== $expected) {
        fwrite(STDERR, "Authorization policy check failed for {$role}: {$permission}\n");
        exit(1);
    }
}

echo "Authorization policy checks passed.\n";
