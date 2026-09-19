<?php
/**
 * Add the workflow-driven operations data model to an existing installation.
 *
 * This migration is additive: it does not delete or rewrite the current users,
 * deliveries, delivery_earnings, or GPS data. Run it once the configured MySQL
 * service is available:
 *
 *   php scripts/migrate_operational_architecture.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';

const MIGRATION_NAME = '20260824_operational_architecture';

function tableExists(PDO $db, string $table): bool
{
    $statement = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);
    return (bool)$statement->fetchColumn();
}

function columnExists(PDO $db, string $table, string $column): bool
{
    $statement = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $statement->execute([$table, $column]);
    return (bool)$statement->fetchColumn();
}

/** Return a validated integer type matching an existing primary key exactly. */
function referenceType(PDO $db, string $table, string $column): string
{
    $statement = $db->prepare('SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $statement->execute([$table, $column]);
    $type = strtolower((string)$statement->fetchColumn());

    if (!preg_match('/^(tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?(?: unsigned)?$/', $type)) {
        throw new RuntimeException("{$table}.{$column} must be an integer key; found '{$type}'.");
    }

    return strtoupper($type);
}

function execute(PDO $db, string $label, string $sql): void
{
    $db->exec($sql);
    echo "✓ {$label}\n";
}

function addColumnIfMissing(PDO $db, string $table, string $column, string $definition): void
{
    if (!columnExists($db, $table, $column)) {
        execute($db, "Added {$table}.{$column}", "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function indexExists(PDO $db, string $table, string $index): bool
{
    $statement = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $statement->execute([$table, $index]);
    return (bool)$statement->fetchColumn();
}

function addIndexIfMissing(PDO $db, string $table, string $index, string $definition): void
{
    if (!indexExists($db, $table, $index)) {
        execute($db, "Added {$table}.{$index}", "ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$definition})");
    }
}

function migrationApplied(PDO $db): bool
{
    $statement = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE name = ?');
    $statement->execute([MIGRATION_NAME]);
    return (bool)$statement->fetchColumn();
}

try {
    $db = (new Database())->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    execute($db, 'Ensured schema migration ledger', "
        CREATE TABLE IF NOT EXISTS schema_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(191) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_schema_migrations_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (migrationApplied($db)) {
        echo "Operational architecture migration has already been applied.\n";
        exit(0);
    }

    if (!tableExists($db, 'users') || !tableExists($db, 'deliveries')) {
        throw new RuntimeException('The existing users and deliveries tables are required before this operational migration can run.');
    }

    $userId = referenceType($db, 'users', 'id');
    $deliveryId = referenceType($db, 'deliveries', 'id');

    // Existing application fields are extended only where the new workflow
    // needs a stable reference. No legacy address or financial values change.
    addColumnIfMissing($db, 'users', 'account_status', "ENUM('active', 'suspended', 'disabled') NOT NULL DEFAULT 'active'");
    addColumnIfMissing($db, 'users', 'last_login_at', 'DATETIME NULL');
    addIndexIfMissing($db, 'users', 'idx_users_account_status', '`account_status`');

    execute($db, 'Ensured Kano service zones', "
        CREATE TABLE IF NOT EXISTS service_zones (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            city VARCHAR(80) NOT NULL DEFAULT 'Kano',
            state VARCHAR(80) NOT NULL DEFAULT 'Kano State',
            polygon_geojson JSON NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_service_zones_name_city (name, city),
            INDEX idx_service_zones_active (is_active, city)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured operational hubs', "
        CREATE TABLE IF NOT EXISTS hubs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_zone_id INT UNSIGNED NULL,
            name VARCHAR(120) NOT NULL,
            address TEXT NOT NULL,
            city VARCHAR(80) NOT NULL DEFAULT 'Kano',
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_hubs_service_zone FOREIGN KEY (service_zone_id) REFERENCES service_zones(id) ON DELETE SET NULL,
            INDEX idx_hubs_active_city (is_active, city)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured client profiles', "
        CREATE TABLE IF NOT EXISTS clients (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id {$userId} NOT NULL,
            client_type ENUM('individual', 'business') NOT NULL DEFAULT 'individual',
            preferred_contact_channel ENUM('phone', 'email', 'whatsapp') NOT NULL DEFAULT 'phone',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_clients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uq_clients_user (user_id),
            INDEX idx_clients_type (client_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured business accounts', "
        CREATE TABLE IF NOT EXISTS business_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_client_id INT UNSIGNED NOT NULL,
            legal_name VARCHAR(180) NOT NULL,
            trading_name VARCHAR(180) NULL,
            contact_email VARCHAR(191) NULL,
            contact_phone VARCHAR(32) NULL,
            billing_address TEXT NULL,
            account_status ENUM('pending', 'active', 'suspended', 'closed') NOT NULL DEFAULT 'pending',
            credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_business_accounts_owner FOREIGN KEY (owner_client_id) REFERENCES clients(id) ON DELETE RESTRICT,
            INDEX idx_business_accounts_status (account_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured client addresses', "
        CREATE TABLE IF NOT EXISTS client_addresses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id INT UNSIGNED NOT NULL,
            label VARCHAR(80) NULL,
            contact_name VARCHAR(150) NOT NULL,
            contact_phone VARCHAR(32) NOT NULL,
            address_line TEXT NOT NULL,
            locality VARCHAR(120) NULL,
            city VARCHAR(80) NOT NULL DEFAULT 'Kano',
            state VARCHAR(80) NOT NULL DEFAULT 'Kano State',
            service_zone_id INT UNSIGNED NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            is_default_pickup TINYINT(1) NOT NULL DEFAULT 0,
            is_default_delivery TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_client_addresses_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
            CONSTRAINT fk_client_addresses_zone FOREIGN KEY (service_zone_id) REFERENCES service_zones(id) ON DELETE SET NULL,
            INDEX idx_client_addresses_client (client_id),
            INDEX idx_client_addresses_service_area (city, state, service_zone_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured driver profiles', "
        CREATE TABLE IF NOT EXISTS drivers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id {$userId} NOT NULL,
            kyc_status ENUM('not_submitted', 'submitted', 'under_review', 'verified', 'rejected', 'expired') NOT NULL DEFAULT 'not_submitted',
            kyc_reviewed_by {$userId} NULL,
            kyc_reviewed_at DATETIME NULL,
            kyc_rejection_reason VARCHAR(500) NULL,
            vehicle_type VARCHAR(80) NULL,
            vehicle_registration VARCHAR(80) NULL,
            max_payload_kg DECIMAL(9,2) NULL,
            base_service_zone_id INT UNSIGNED NULL,
            active_status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'inactive',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_drivers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_drivers_reviewer FOREIGN KEY (kyc_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_drivers_zone FOREIGN KEY (base_service_zone_id) REFERENCES service_zones(id) ON DELETE SET NULL,
            UNIQUE KEY uq_drivers_user (user_id),
            UNIQUE KEY uq_drivers_vehicle_registration (vehicle_registration),
            INDEX idx_drivers_kyc_status (kyc_status, active_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured driver documents', "
        CREATE TABLE IF NOT EXISTS driver_documents (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            driver_id INT UNSIGNED NOT NULL,
            document_type ENUM('government_id', 'drivers_license', 'vehicle_registration', 'proof_of_address', 'profile_photo', 'other') NOT NULL,
            document_number VARCHAR(160) NULL,
            storage_key VARCHAR(500) NOT NULL,
            expires_at DATE NULL,
            verification_status ENUM('pending', 'verified', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
            reviewed_by {$userId} NULL,
            reviewed_at DATETIME NULL,
            rejection_reason VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            retention_until DATETIME NULL,
            CONSTRAINT fk_driver_documents_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
            CONSTRAINT fk_driver_documents_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_driver_documents_review (driver_id, verification_status),
            INDEX idx_driver_documents_expiry (expires_at),
            INDEX idx_driver_documents_retention (retention_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured driver availability', "
        CREATE TABLE IF NOT EXISTS driver_availability (
            driver_id INT UNSIGNED PRIMARY KEY,
            availability_status ENUM('offline', 'available', 'busy', 'paused') NOT NULL DEFAULT 'offline',
            service_zone_id INT UNSIGNED NULL,
            last_latitude DECIMAL(10,7) NULL,
            last_longitude DECIMAL(10,7) NULL,
            last_location_at DATETIME NULL,
            available_since DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_driver_availability_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
            CONSTRAINT fk_driver_availability_zone FOREIGN KEY (service_zone_id) REFERENCES service_zones(id) ON DELETE SET NULL,
            INDEX idx_driver_availability_dispatch (availability_status, service_zone_id, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured rate cards', "
        CREATE TABLE IF NOT EXISTS rate_cards (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            city VARCHAR(80) NOT NULL DEFAULT 'Kano',
            service_type ENUM('same_day', 'scheduled', 'business') NOT NULL,
            business_account_id INT UNSIGNED NULL,
            currency CHAR(3) NOT NULL DEFAULT 'NGN',
            effective_from DATETIME NOT NULL,
            effective_to DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by {$userId} NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_rate_cards_business FOREIGN KEY (business_account_id) REFERENCES business_accounts(id) ON DELETE CASCADE,
            CONSTRAINT fk_rate_cards_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_rate_cards_lookup (city, service_type, is_active, effective_from),
            INDEX idx_rate_cards_business (business_account_id, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured rate rules', "
        CREATE TABLE IF NOT EXISTS rate_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rate_card_id INT UNSIGNED NOT NULL,
            rule_code ENUM('base_fare', 'included_distance_km', 'distance_per_km', 'distance_over_threshold_per_km', 'included_weight_kg', 'weight_per_kg', 'same_day_surcharge', 'scheduled_surcharge', 'fragile_surcharge', 'perishable_surcharge') NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            threshold_value DECIMAL(12,2) NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_rate_rules_card FOREIGN KEY (rate_card_id) REFERENCES rate_cards(id) ON DELETE CASCADE,
            UNIQUE KEY uq_rate_rules_card_code (rate_card_id, rule_code),
            INDEX idx_rate_rules_card_order (rate_card_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    addColumnIfMissing($db, 'deliveries', 'business_account_id', 'INT UNSIGNED NULL');
    addColumnIfMissing($db, 'deliveries', 'pickup_hub_id', 'INT UNSIGNED NULL');
    addColumnIfMissing($db, 'deliveries', 'delivery_hub_id', 'INT UNSIGNED NULL');
    addColumnIfMissing($db, 'deliveries', 'pickup_latitude', 'DECIMAL(10,7) NULL');
    addColumnIfMissing($db, 'deliveries', 'pickup_longitude', 'DECIMAL(10,7) NULL');
    addColumnIfMissing($db, 'deliveries', 'delivery_latitude', 'DECIMAL(10,7) NULL');
    addColumnIfMissing($db, 'deliveries', 'delivery_longitude', 'DECIMAL(10,7) NULL');
    addColumnIfMissing($db, 'deliveries', 'service_type', "ENUM('same_day', 'scheduled', 'business') NOT NULL DEFAULT 'same_day'");
    addColumnIfMissing($db, 'deliveries', 'public_tracking_token', "CHAR(32) NULL UNIQUE COMMENT 'High-entropy random token for public unauthenticated tracking. Never sequential.'");
    addColumnIfMissing($db, 'deliveries', 'otp_failed_attempts', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
    addColumnIfMissing($db, 'deliveries', 'otp_locked_until', 'DATETIME NULL');

    // Back-fill public_tracking_token for any existing deliveries that predate this migration
    $unfilled = $db->query("SELECT COUNT(*) FROM deliveries WHERE public_tracking_token IS NULL")->fetchColumn();
    if ($unfilled > 0) {
        $rows = $db->query("SELECT id FROM deliveries WHERE public_tracking_token IS NULL")->fetchAll(PDO::FETCH_COLUMN);
        $upd  = $db->prepare("UPDATE deliveries SET public_tracking_token = ? WHERE id = ?");
        foreach ($rows as $rowId) {
            $upd->execute([bin2hex(random_bytes(16)), $rowId]);
        }
        execute($db, "Back-filled public_tracking_token for {$unfilled} existing deliveries", "SELECT 1");
    }
    addIndexIfMissing($db, 'deliveries', 'idx_deliveries_service_type', '`service_type`, `status`');
    addIndexIfMissing($db, 'deliveries', 'idx_deliveries_business', '`business_account_id`, `status`');

    execute($db, 'Ensured delivery items', "
        CREATE TABLE IF NOT EXISTS delivery_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id {$deliveryId} NOT NULL,
            description VARCHAR(500) NOT NULL,
            category VARCHAR(100) NOT NULL,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            weight_kg DECIMAL(9,2) NOT NULL,
            dimensions VARCHAR(150) NULL,
            declared_value DECIMAL(12,2) NULL,
            is_fragile TINYINT(1) NOT NULL DEFAULT 0,
            is_perishable TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_delivery_items_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
            INDEX idx_delivery_items_delivery (delivery_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured delivery price quotes', "
        CREATE TABLE IF NOT EXISTS delivery_price_quotes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id {$deliveryId} NULL,
            rate_card_id INT UNSIGNED NULL,
            quote_status ENUM('estimated', 'accepted', 'superseded', 'cancelled') NOT NULL DEFAULT 'estimated',
            currency CHAR(3) NOT NULL DEFAULT 'NGN',
            distance_km DECIMAL(10,2) NOT NULL,
            weight_kg DECIMAL(9,2) NOT NULL,
            breakdown JSON NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL,
            calculated_by {$userId} NULL,
            calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            CONSTRAINT fk_delivery_price_quotes_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
            CONSTRAINT fk_delivery_price_quotes_rate FOREIGN KEY (rate_card_id) REFERENCES rate_cards(id) ON DELETE SET NULL,
            CONSTRAINT fk_delivery_price_quotes_user FOREIGN KEY (calculated_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_delivery_price_quotes_delivery_status (delivery_id, quote_status),
            INDEX idx_delivery_price_quotes_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured driver delivery offers', "
        CREATE TABLE IF NOT EXISTS delivery_driver_offers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id {$deliveryId} NOT NULL,
            driver_id INT UNSIGNED NOT NULL,
            offered_by {$userId} NULL,
            offer_status ENUM('offered', 'accepted', 'declined', 'expired', 'withdrawn') NOT NULL DEFAULT 'offered',
            offered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at DATETIME NULL,
            expires_at DATETIME NULL,
            response_reason VARCHAR(500) NULL,
            CONSTRAINT fk_driver_offers_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
            CONSTRAINT fk_driver_offers_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
            CONSTRAINT fk_driver_offers_creator FOREIGN KEY (offered_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY uq_driver_offers_delivery_driver (delivery_id, driver_id),
            INDEX idx_driver_offers_inbox (driver_id, offer_status, expires_at),
            INDEX idx_driver_offers_delivery (delivery_id, offer_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured delivery assignments', "
        CREATE TABLE IF NOT EXISTS delivery_assignments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id {$deliveryId} NOT NULL,
            driver_id INT UNSIGNED NOT NULL,
            accepted_offer_id BIGINT UNSIGNED NULL,
            assigned_by {$userId} NULL,
            assignment_sequence SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            assignment_status ENUM('locked', 'released', 'cancelled') NOT NULL DEFAULT 'locked',
            is_current TINYINT(1) NOT NULL DEFAULT 1,
            assignment_method ENUM('broadcast', 'manual') NOT NULL DEFAULT 'broadcast',
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            accepted_at DATETIME NULL,
            released_at DATETIME NULL,
            release_reason VARCHAR(500) NULL,
            CONSTRAINT fk_assignments_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
            CONSTRAINT fk_assignments_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE RESTRICT,
            CONSTRAINT fk_assignments_offer FOREIGN KEY (accepted_offer_id) REFERENCES delivery_driver_offers(id) ON DELETE SET NULL,
            CONSTRAINT fk_assignments_creator FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY uq_assignments_delivery_sequence (delivery_id, assignment_sequence),
            INDEX idx_assignments_delivery_current (delivery_id, is_current),
            INDEX idx_assignments_driver_status (driver_id, assignment_status, assigned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured delivery status history', "
        CREATE TABLE IF NOT EXISTS delivery_status_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id {$deliveryId} NOT NULL,
            from_status VARCHAR(40) NULL,
            to_status VARCHAR(40) NOT NULL,
            changed_by_user_id {$userId} NULL,
            changed_by_role ENUM('client', 'delivery', 'admin', 'system') NOT NULL,
            reason VARCHAR(500) NULL,
            metadata JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_status_history_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
            CONSTRAINT fk_status_history_actor FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_status_history_delivery_time (delivery_id, created_at),
            INDEX idx_status_history_status (to_status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured delivery proof records', "
        CREATE TABLE IF NOT EXISTS delivery_proofs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id {$deliveryId} NOT NULL,
            captured_by_driver_id INT UNSIGNED NOT NULL,
            proof_type ENUM('otp', 'recipient_name', 'signature', 'photo', 'note') NOT NULL,
            storage_key VARCHAR(500) NULL,
            recipient_name VARCHAR(150) NULL,
            verification_status ENUM('captured', 'verified', 'rejected') NOT NULL DEFAULT 'captured',
            metadata JSON NULL,
            captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_by {$userId} NULL,
            verified_at DATETIME NULL,
            CONSTRAINT fk_delivery_proofs_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
            CONSTRAINT fk_delivery_proofs_driver FOREIGN KEY (captured_by_driver_id) REFERENCES drivers(id) ON DELETE RESTRICT,
            CONSTRAINT fk_delivery_proofs_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_delivery_proofs_delivery (delivery_id, proof_type),
            INDEX idx_delivery_proofs_verification (verification_status, captured_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured delivery location events', "
        CREATE TABLE IF NOT EXISTS delivery_location_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            delivery_id {$deliveryId} NOT NULL,
            delivery_person_id {$userId} NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            accuracy_m DECIMAL(8,2) NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_location_events_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
            CONSTRAINT fk_location_events_driver FOREIGN KEY (delivery_person_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_location_events_delivery_time (delivery_id, recorded_at),
            INDEX idx_location_events_driver_time (delivery_person_id, recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured notifications', "
        CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id {$userId} NOT NULL,
            delivery_id {$deliveryId} NULL,
            channel ENUM('in_app', 'email', 'sms', 'whatsapp') NOT NULL DEFAULT 'in_app',
            notification_type VARCHAR(100) NOT NULL,
            title VARCHAR(180) NOT NULL,
            body TEXT NOT NULL,
            payload JSON NULL,
            delivery_status ENUM('queued', 'sent', 'failed', 'read') NOT NULL DEFAULT 'queued',
            sent_at DATETIME NULL,
            read_at DATETIME NULL,
            failure_reason VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_notifications_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
            INDEX idx_notifications_inbox (user_id, delivery_status, created_at),
            INDEX idx_notifications_delivery (delivery_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    execute($db, 'Ensured operations audit log', "
        CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_user_id {$userId} NULL,
            actor_role ENUM('client', 'delivery', 'admin', 'system') NOT NULL,
            action VARCHAR(120) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            delivery_id {$deliveryId} NULL,
            before_state JSON NULL,
            after_state JSON NULL,
            metadata JSON NULL,
            request_id CHAR(36) NULL,
            ip_address VARBINARY(16) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_audit_logs_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE SET NULL,
            INDEX idx_audit_logs_entity (entity_type, entity_id, created_at),
            INDEX idx_audit_logs_delivery (delivery_id, created_at),
            INDEX idx_audit_logs_actor (actor_user_id, created_at),
            INDEX idx_audit_logs_action_time (action, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Existing accounts become profiles; no KYC state is inferred from a legacy
    // is_approved flag because that flag has no supporting document evidence.
    execute($db, 'Backfilled client profiles', "
        INSERT IGNORE INTO clients (user_id, client_type)
        SELECT id, 'individual' FROM users WHERE role = 'client'
    ");
    execute($db, 'Backfilled driver profiles', "
        INSERT IGNORE INTO drivers (user_id, kyc_status, active_status)
        SELECT id, 'not_submitted', 'inactive' FROM users WHERE role = 'delivery'
    ");
    execute($db, 'Backfilled driver availability', "
        INSERT IGNORE INTO driver_availability (driver_id, availability_status)
        SELECT id, 'offline' FROM drivers
    ");
    execute($db, 'Backfilled existing delivery assignments', "
        INSERT INTO delivery_assignments
        (delivery_id, driver_id, assignment_sequence, assignment_status, is_current, assignment_method, assigned_at, accepted_at, released_at)
        SELECT d.id,
               dr.id,
               1,
               CASE WHEN d.status IN ('completed', 'cancelled', 'rejected', 'failed') THEN 'released' ELSE 'locked' END,
               CASE WHEN d.status IN ('completed', 'cancelled', 'rejected', 'failed') THEN 0 ELSE 1 END,
               'manual',
               COALESCE(d.request_time, NOW()),
               COALESCE(d.request_time, NOW()),
               CASE WHEN d.status IN ('completed', 'cancelled', 'rejected', 'failed') THEN COALESCE(d.delivery_time, NOW()) ELSE NULL END
        FROM deliveries d
        INNER JOIN drivers dr ON dr.user_id = d.delivery_person_id
        WHERE d.delivery_person_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM delivery_assignments a WHERE a.delivery_id = d.id)
    ");

    if (columnExists($db, 'deliveries', 'item_description') && columnExists($db, 'deliveries', 'item_category') && columnExists($db, 'deliveries', 'item_weight')) {
        execute($db, 'Backfilled legacy delivery items', "
            INSERT INTO delivery_items (delivery_id, description, category, quantity, weight_kg, dimensions, is_fragile, is_perishable)
            SELECT d.id, LEFT(d.item_description, 500), d.item_category, COALESCE(d.item_quantity, 1), d.item_weight,
                   d.item_dimensions, COALESCE(d.is_fragile, 0), COALESCE(d.is_perishable, 0)
            FROM deliveries d
            WHERE NOT EXISTS (SELECT 1 FROM delivery_items i WHERE i.delivery_id = d.id)
        ");
    }

    if (columnExists($db, 'deliveries', 'status')) {
        execute($db, 'Backfilled delivery status snapshots', "
            INSERT INTO delivery_status_history (delivery_id, from_status, to_status, changed_by_role, reason)
            SELECT d.id, NULL, d.status, 'system', 'Status recorded when operational history was introduced'
            FROM deliveries d
            WHERE NOT EXISTS (SELECT 1 FROM delivery_status_history h WHERE h.delivery_id = d.id)
        ");
    }

    // Seed only the existing configured general price settings. Service-specific
    // or business modifiers remain absent until operations approves them.
    $rateCard = $db->prepare("SELECT id FROM rate_cards WHERE name = 'Default Kano standard rate' AND city = 'Kano' AND service_type = 'same_day' AND business_account_id IS NULL LIMIT 1");
    $rateCard->execute();
    $rateCardId = (int)$rateCard->fetchColumn();
    if (!$rateCardId) {
        $insertCard = $db->prepare("INSERT INTO rate_cards (name, city, service_type, currency, effective_from, is_active) VALUES ('Default Kano standard rate', 'Kano', 'same_day', 'NGN', NOW(), 1)");
        $insertCard->execute();
        $rateCardId = (int)$db->lastInsertId();
    }

    $config = require __DIR__ . '/../config/config.php';
    $pricing = $config['pricing'] ?? [];
    $seedRules = [
        ['base_fare', (float)($pricing['base_fare'] ?? 0), null, 10],
        ['included_distance_km', 0, (float)($pricing['base_km'] ?? 0), 20],
        ['distance_per_km', (float)($pricing['per_km_rate'] ?? 0), null, 30],
        ['included_weight_kg', 0, (float)($pricing['base_weight_kg'] ?? 0), 40],
        ['weight_per_kg', (float)($pricing['per_kg_rate'] ?? 0), null, 50],
        ['fragile_surcharge', (float)($pricing['fragile_surcharge'] ?? 0), null, 60],
        ['perishable_surcharge', (float)($pricing['perishable_surcharge'] ?? 0), null, 70],
    ];
    $insertRule = $db->prepare('INSERT IGNORE INTO rate_rules (rate_card_id, rule_code, amount, threshold_value, sort_order) VALUES (?, ?, ?, ?, ?)');
    foreach ($seedRules as [$code, $amount, $threshold, $order]) {
        $insertRule->execute([$rateCardId, $code, $amount, $threshold, $order]);
    }
    echo "✓ Ensured default Kano rate card rules from config\n";

    $record = $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)');
    $record->execute([MIGRATION_NAME]);
    echo "Operational architecture migration complete.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Operational architecture migration failed: {$exception->getMessage()}\n");
    exit(1);
}
