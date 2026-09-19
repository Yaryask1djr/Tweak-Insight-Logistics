<?php

/** Create or refresh the local operations administrator and demo client/driver accounts. */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$password = $argv[1] ?? '';
if (strlen($password) < 12) {
    fwrite(STDERR, "Pass a demo password of at least 12 characters.\n");
    exit(1);
}

$db = (new Database())->getConnection();
$accounts = [
    ['admin', 'Musbahu Abdullahi Iliyasu', 'yaryaskidjr@gmail.com', '+2348060431696', 'Nassarawa, Kano'],
    ['client', 'Kano Client', 'client@tweaklogistics.test', '+2349123053153', 'Hotoro, Kano'],
    ['delivery', 'Kano Driver', 'driver@tweaklogistics.test', '+2347043495744', 'Farawa, Kano'],
];

$db->beginTransaction();
try {
    $user = $db->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, address, is_approved, account_status) VALUES (?, ?, ?, ?, ?, ?, 1, 'active') ON DUPLICATE KEY UPDATE role = VALUES(role), full_name = VALUES(full_name), phone = VALUES(phone), password_hash = VALUES(password_hash), address = VALUES(address), is_approved = 1, account_status = 'active'");
    $ids = [];
    foreach ($accounts as [$role, $name, $email, $phone, $address]) {
        $user->execute([$role, $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $address]);
        $find = $db->prepare('SELECT id FROM users WHERE email = ?'); $find->execute([$email]);
        $ids[$role] = (int)$find->fetchColumn();
    }

    $db->prepare("INSERT INTO clients (user_id, client_type, kyc_status, kyc_reviewed_at) VALUES (?, 'individual', 'verified', NOW()) ON DUPLICATE KEY UPDATE client_type = 'individual', kyc_status = 'verified', kyc_rejection_reason = NULL, kyc_reviewed_at = NOW()")
        ->execute([$ids['client']]);
    $driver = $db->prepare("INSERT INTO drivers (user_id, kyc_status, vehicle_type, vehicle_registration, max_payload_kg, active_status) VALUES (?, 'verified', 'motorcycle', 'KANO-DEMO-01', 25, 'active') ON DUPLICATE KEY UPDATE kyc_status = 'verified', vehicle_type = 'motorcycle', vehicle_registration = 'KANO-DEMO-01', max_payload_kg = 25, active_status = 'active'");
    $driver->execute([$ids['delivery']]);
    $findDriver = $db->prepare('SELECT id FROM drivers WHERE user_id = ?'); $findDriver->execute([$ids['delivery']]); $driverId = (int)$findDriver->fetchColumn();
    $db->prepare("INSERT INTO driver_availability (driver_id, availability_status, available_since) VALUES (?, 'available', NOW()) ON DUPLICATE KEY UPDATE availability_status = 'available', available_since = NOW()")
        ->execute([$driverId]);
    $db->commit();
    echo "Operations admin plus demo client and driver accounts are ready.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Demo account setup failed: {$exception->getMessage()}\n");
    exit(1);
}
