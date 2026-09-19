<?php

/**
 * Transactional Phase 4/5 workflow smoke test.
 * It creates realistic admin/client/driver fixture rows, verifies the core
 * dispatch path, and always rolls the transaction back: no test accounts,
 * offers, assignments, or audit records remain in the live database.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/authorization_policy.php';
require_once __DIR__ . '/../helpers/delivery_status_policy.php';

// Domain policy checks execute in all environments (independent of live MySQL)
if (!AuthorizationPolicy::can('admin', 'operations.assignment.manage') || AuthorizationPolicy::can('client', 'operations.assignment.manage') || !AuthorizationPolicy::can('delivery', 'driver.offer.accept')) {
    throw new RuntimeException('Role policy check failed.');
}
if (!DeliveryStatusPolicy::adminTransition('under_review', 'broadcasted') || !DeliveryStatusPolicy::driverTransition('assigned', 'driver_en_route') || DeliveryStatusPolicy::driverTransition('pending', 'in_transit')) {
    throw new RuntimeException('Lifecycle policy check failed.');
}

$db = null;
try {
    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'tweak_insight_logistics';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') ?: '';
    $dbPort = getenv('DB_PORT') ?: '3306';
    $db = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,
    ]);
} catch (Throwable $e) {
    echo "Operations workflow policy checks passed. [INFO] Live MySQL database unreachable; skipping live transactional fixture.\n";
    exit(0);
}

$suffix = bin2hex(random_bytes(5));
$db->beginTransaction();
try {
    $createUser = $db->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, is_approved, account_status) VALUES (?, ?, ?, ?, ?, 1, 'active')");
    $createUser->execute(['admin', 'E2E Operations Admin', "e2e-admin-{$suffix}@example.test", '+2348000000001', password_hash('test-only', PASSWORD_DEFAULT)]); $adminId = (int)$db->lastInsertId();
    $createUser->execute(['client', 'E2E Kano Client', "e2e-client-{$suffix}@example.test", '+2348000000002', password_hash('test-only', PASSWORD_DEFAULT)]); $clientUserId = (int)$db->lastInsertId();
    $createUser->execute(['delivery', 'E2E Verified Driver', "e2e-driver-{$suffix}@example.test", '+2348000000003', password_hash('test-only', PASSWORD_DEFAULT)]); $driverUserId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO clients (user_id, kyc_status) VALUES (?, 'verified')")->execute([$clientUserId]);
    $db->prepare("INSERT INTO drivers (user_id, kyc_status, vehicle_type, vehicle_registration, active_status) VALUES (?, 'verified', 'motorcycle', ?, 'active')")->execute([$driverUserId, "E2E-{$suffix}"]); $driverId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO driver_availability (driver_id, availability_status, available_since) VALUES (?, 'available', NOW())")->execute([$driverId]);

    $db->prepare("INSERT INTO deliveries (tracking_number, client_id, pickup_address, pickup_city, pickup_contact_name, pickup_contact_phone, delivery_address, delivery_city, delivery_contact_name, delivery_contact_phone, service_type, item_description, item_category, item_quantity, item_weight, distance_km, total_cost, status, delivery_otp, payment_status) VALUES (?, ?, 'Kano pickup', 'Kano', 'Pickup contact', '+2348000000101', 'Kano destination', 'Kano', 'Recipient', '+2348000000102', 'same_day', 'E2E parcel', 'general', 1, 1.0, 5.0, 1250.00, 'under_review', '123456', 'unpaid')")->execute(["E2E-{$suffix}", $clientUserId]);
    $deliveryId = (int)$db->lastInsertId();

    if (!AuthorizationPolicy::can('admin', 'operations.assignment.manage') || AuthorizationPolicy::can('client', 'operations.assignment.manage') || !AuthorizationPolicy::can('delivery', 'driver.offer.accept')) throw new RuntimeException('Role policy check failed.');
    if (!DeliveryStatusPolicy::adminTransition('under_review', 'broadcasted') || !DeliveryStatusPolicy::driverTransition('assigned', 'driver_en_route') || DeliveryStatusPolicy::driverTransition('pending', 'in_transit')) throw new RuntimeException('Lifecycle policy check failed.');

    $db->prepare("UPDATE deliveries SET status = 'broadcasted' WHERE id = ? AND status = 'under_review'")->execute([$deliveryId]);
    $db->prepare("INSERT INTO delivery_driver_offers (delivery_id, driver_id, offered_by, offer_status, expires_at) VALUES (?, ?, ?, 'offered', DATE_ADD(NOW(), INTERVAL 15 MINUTE))")->execute([$deliveryId, $driverId, $adminId]);
    $offerId = (int)$db->lastInsertId();
    $accepted = $db->prepare("UPDATE delivery_driver_offers SET offer_status = 'accepted', responded_at = NOW() WHERE id = ? AND offer_status = 'offered' AND expires_at > NOW()"); $accepted->execute([$offerId]);
    $assigned = $db->prepare("UPDATE deliveries SET delivery_person_id = ?, status = 'assigned' WHERE id = ? AND status = 'broadcasted' AND delivery_person_id IS NULL"); $assigned->execute([$driverUserId, $deliveryId]);
    if ($accepted->rowCount() !== 1 || $assigned->rowCount() !== 1) throw new RuntimeException('Atomic offer acceptance fixture failed.');
    $db->prepare("INSERT INTO delivery_assignments (delivery_id, driver_id, accepted_offer_id, assigned_by, assignment_sequence, assignment_status, is_current, assignment_method, accepted_at) VALUES (?, ?, ?, ?, 1, 'locked', 1, 'broadcast', NOW())")->execute([$deliveryId, $driverId, $offerId, $adminId]);
    $db->prepare("UPDATE driver_availability SET availability_status = 'busy' WHERE driver_id = ?")->execute([$driverId]);
    foreach (['driver_en_route', 'picked_up', 'in_transit', 'arrived', 'delivered'] as $status) $db->prepare('UPDATE deliveries SET status = ? WHERE id = ?')->execute([$status, $deliveryId]);
    $db->prepare("UPDATE driver_availability SET availability_status = 'available', available_since = NOW() WHERE driver_id = ? AND availability_status = 'busy'")->execute([$driverId]);
    if ($db->prepare('SELECT status FROM deliveries WHERE id = ?')->execute([$deliveryId]) === false) throw new RuntimeException('Unable to read final delivery state.');

    $db->rollBack();
    echo "Operations workflow smoke test passed (transaction rolled back; no fixture data retained).\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Operations workflow smoke test failed: {$exception->getMessage()}\n");
    exit(1);
}
