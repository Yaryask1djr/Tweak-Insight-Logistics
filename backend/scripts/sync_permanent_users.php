<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $db = $database->connect();
    echo "Database connection successful!\n";
    echo "Database name: " . (getenv('DB_NAME') ?: 'tweak_insight_logistics_new') . "\n";

    $users = [
        [
            'role' => 'admin',
            'full_name' => 'Musbahu Abdullahi Iliyasu',
            'email' => 'yaryaskidjr@gmail.com',
            'phone' => '+2348000000001',
            'password' => 'Password123!',
            'status' => 'active',
            'is_approved' => 1,
            'kyc_status' => 'verified'
        ],
        [
            'role' => 'client',
            'full_name' => 'Musbahu Iliyasu Abdullahi',
            'email' => 'yaryaskidjr1@gmail.com',
            'phone' => '+2348000000002',
            'password' => 'Password123!',
            'status' => 'active',
            'is_approved' => 1,
            'kyc_status' => 'verified'
        ],
        [
            'role' => 'client',
            'full_name' => 'Kano Client',
            'email' => 'client@tweaklogistics.test',
            'phone' => '+2348000000003',
            'password' => 'Password123!',
            'status' => 'active',
            'is_approved' => 1,
            'kyc_status' => 'verified'
        ],
        [
            'role' => 'delivery',
            'full_name' => 'Kano Driver',
            'email' => 'driver@tweaklogistics.test',
            'phone' => '+2348000000004',
            'password' => 'Password123!',
            'status' => 'active',
            'is_approved' => 1,
            'kyc_status' => 'verified'
        ],
    ];

    foreach ($users as $u) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$u['email']]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $hash = password_hash($u['password'], PASSWORD_DEFAULT);

        if ($existing) {
            $update = $db->prepare("UPDATE users SET full_name = ?, role = ?, phone = ?, password_hash = ?, is_approved = ?, account_status = ? WHERE id = ?");
            $update->execute([$u['full_name'], $u['role'], $u['phone'], $hash, $u['is_approved'], $u['status'], $existing['id']]);
            $userId = (int)$existing['id'];
            echo "Updated user: {$u['email']} (ID: $userId)\n";
        } else {
            $insert = $db->prepare("INSERT INTO users (full_name, email, phone, password_hash, role, is_approved, account_status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insert->execute([$u['full_name'], $u['email'], $u['phone'], $hash, $u['role'], $u['is_approved'], $u['status']]);
            $userId = (int)$db->lastInsertId();
            echo "Created user: {$u['email']} (ID: $userId)\n";
        }

        // If client, ensure clients record with verified KYC exists
        if ($u['role'] === 'client') {
            $clientStmt = $db->prepare("SELECT id FROM clients WHERE user_id = ?");
            $clientStmt->execute([$userId]);
            if ($clientRow = $clientStmt->fetch(PDO::FETCH_ASSOC)) {
                $db->prepare("UPDATE clients SET kyc_status = 'verified' WHERE id = ?")->execute([$clientRow['id']]);
            } else {
                $db->prepare("INSERT INTO clients (user_id, client_type, kyc_status) VALUES (?, 'individual', 'verified')")->execute([$userId]);
            }
        }

        // If driver, ensure drivers record with verified KYC exists
        if ($u['role'] === 'delivery') {
            $driverStmt = $db->prepare("SELECT id FROM drivers WHERE user_id = ?");
            $driverStmt->execute([$userId]);
            if ($driverRow = $driverStmt->fetch(PDO::FETCH_ASSOC)) {
                $db->prepare("UPDATE drivers SET kyc_status = 'verified', active_status = 'active', vehicle_type = 'Motorcycle' WHERE id = ?")->execute([$driverRow['id']]);
                $driverId = $driverRow['id'];
            } else {
                $db->prepare("INSERT INTO drivers (user_id, kyc_status, active_status, vehicle_type, vehicle_registration) VALUES (?, 'verified', 'active', 'Motorcycle', 'KNO-7788-DISPATCH')")->execute([$userId]);
                $driverId = (int)$db->lastInsertId();
            }

            // Also ensure availability entry exists
            $availStmt = $db->prepare("SELECT driver_id FROM driver_availability WHERE driver_id = ?");
            $availStmt->execute([$driverId]);
            if (!$availStmt->fetch()) {
                $db->prepare("INSERT INTO driver_availability (driver_id, availability_status) VALUES (?, 'available')")->execute([$driverId]);
            } else {
                $db->prepare("UPDATE driver_availability SET availability_status = 'available' WHERE driver_id = ?")->execute([$driverId]);
            }
        }
    }

    echo "\n=== CONFIRMED DATABASE USERS & CREDENTIALS ===\n";
    $verifyStmt = $db->query("
        SELECT 
            u.id, 
            u.role, 
            u.full_name, 
            u.email, 
            u.account_status, 
            u.is_approved,
            COALESCE(c.kyc_status, d.kyc_status, 'cleared') AS kyc_status
        FROM users u
        LEFT JOIN clients c ON c.user_id = u.id
        LEFT JOIN drivers d ON d.user_id = u.id
        WHERE u.email IN ('yaryaskidjr@gmail.com', 'yaryaskidjr1@gmail.com', 'client@tweaklogistics.test', 'driver@tweaklogistics.test')
        ORDER BY u.id ASC
    ");
    $rows = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        echo sprintf("ID: %-3d | Role: %-10s | Email: %-28s | Name: %-26s | Status: %-7s | Approved: %d | KYC: %s\n",
            $row['id'], $row['role'], $row['email'], $row['full_name'], $row['account_status'], $row['is_approved'], $row['kyc_status']
        );
    }

    // Verify Password Login test for each user
    echo "\n=== AUTHENTICATION PASSWORD CHECK ===\n";
    foreach ($users as $u) {
        $checkStmt = $db->prepare("SELECT password_hash FROM users WHERE email = ?");
        $checkStmt->execute([$u['email']]);
        $row = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $matches = password_verify($u['password'], $row['password_hash']);
        echo sprintf("User: %-28s | Password Match ('%s'): %s\n", $u['email'], $u['password'], $matches ? 'SUCCESS' : 'FAILED');
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
