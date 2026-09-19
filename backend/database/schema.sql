-- Tweak Insight Logistics: baseline MySQL 8 schema
--
-- This is the clean-install schema. It models one Kano-only operations
-- workflow: client request -> operations review -> driver offer -> assignment
-- -> pickup -> live tracking -> proof -> operational completion.
--
-- It assumes the configured database already exists and is selected.
-- For an existing installation, run scripts/migrate_operational_architecture.php
-- instead of recreating tables from this file.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role ENUM('client', 'delivery', 'admin') NOT NULL DEFAULT 'client',
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    phone VARCHAR(32) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    address TEXT NULL,
    is_approved TINYINT(1) NOT NULL DEFAULT 0,
    account_status ENUM('active', 'suspended', 'disabled') NOT NULL DEFAULT 'active',
    token_version INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Incremented on logout / password change to revoke all issued JWTs',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_approved (role, is_approved),
    INDEX idx_users_role_approved_id (role, is_approved, id),
    INDEX idx_users_account_status (account_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Long-lived sessions are opaque random values held only in HttpOnly cookies.
-- The application stores a SHA-256 hash, allowing rotation/reuse detection
-- without persisting a bearer credential in the database.
CREATE TABLE IF NOT EXISTS auth_refresh_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    family_id CHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_version INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoked_reason VARCHAR(64) NULL,
    created_ip VARBINARY(16) NULL,
    user_agent_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auth_refresh_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_auth_refresh_sessions_token_hash (token_hash),
    INDEX idx_auth_refresh_sessions_user_active (user_id, revoked_at, expires_at),
    INDEX idx_auth_refresh_sessions_expiry (expires_at),
    INDEX idx_auth_refresh_sessions_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Zones and hubs define the Kano-only operating geography. Addresses retain
-- coordinates, while delivery records store immutable address snapshots.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A client is a user profile. It exists because client-specific fields and
-- business account membership should not be overloaded onto users.
CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    client_type ENUM('individual', 'business') NOT NULL DEFAULT 'individual',
    preferred_contact_channel ENUM('phone', 'email', 'whatsapp') NOT NULL DEFAULT 'phone',
    notes TEXT NULL,
    kyc_status ENUM('not_submitted', 'submitted', 'under_review', 'verified', 'rejected') NOT NULL DEFAULT 'not_submitted',
    kyc_rejection_reason VARCHAR(500) NULL,
    kyc_reviewed_by INT UNSIGNED NULL,
    kyc_reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_clients_kyc_reviewer FOREIGN KEY (kyc_reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_clients_user (user_id),
    INDEX idx_clients_type (client_type),
    INDEX idx_clients_kyc_status (kyc_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_kyc_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    document_type ENUM('national_id', 'passport', 'utility_bill', 'business_registration', 'other') NOT NULL,
    document_number VARCHAR(160) NULL,
    storage_key VARCHAR(500) NOT NULL,
    expires_at DATE NULL,
    verification_status ENUM('pending', 'verified', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(500) NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    retention_until DATETIME NOT NULL,
    CONSTRAINT fk_client_kyc_documents_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_kyc_documents_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY uq_client_kyc_documents_type (client_id, document_type),
    INDEX idx_client_kyc_documents_review (client_id, verification_status),
    INDEX idx_client_kyc_documents_expiry (expires_at),
    INDEX idx_client_kyc_documents_retention (retention_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A driver remains a user with role=delivery. The driver profile owns KYC,
-- vehicle and availability information; it does not change the auth contract.
CREATE TABLE IF NOT EXISTS drivers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    kyc_status ENUM('not_submitted', 'submitted', 'under_review', 'verified', 'rejected', 'expired') NOT NULL DEFAULT 'not_submitted',
    kyc_reviewed_by INT UNSIGNED NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    driver_id INT UNSIGNED NOT NULL,
    document_type ENUM('government_id', 'drivers_license', 'vehicle_registration', 'proof_of_address', 'profile_photo', 'other') NOT NULL,
    document_number VARCHAR(160) NULL,
    storage_key VARCHAR(500) NOT NULL,
    expires_at DATE NULL,
    verification_status ENUM('pending', 'verified', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    rejection_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    retention_until DATETIME NOT NULL,
    CONSTRAINT fk_driver_documents_driver FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE CASCADE,
    CONSTRAINT fk_driver_documents_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_driver_documents_review (driver_id, verification_status),
    INDEX idx_driver_documents_expiry (expires_at),
    INDEX idx_driver_documents_retention (retention_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One current availability row per driver keeps offer eligibility fast. History
-- belongs in audit_logs, rather than duplicating a second availability ledger.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate cards are managed operations data. Quotes snapshot the applied values,
-- so changing a future rate never changes a delivery already booked.
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
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rate_cards_business FOREIGN KEY (business_account_id) REFERENCES business_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_rate_cards_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rate_cards_lookup (city, service_type, is_active, effective_from),
    INDEX idx_rate_cards_business (business_account_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- deliveries is the delivery request and its operational record. Do not create
-- a second delivery_requests table: the status lifecycle makes this one record
-- authoritative from creation through completion.
CREATE TABLE IF NOT EXISTS deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tracking_number VARCHAR(64) NOT NULL UNIQUE,
    public_tracking_token CHAR(32) NOT NULL UNIQUE COMMENT 'High-entropy random token used exclusively on the public tracking endpoint. Never expose the numeric id publicly.',
    client_id INT UNSIGNED NOT NULL,
    business_account_id INT UNSIGNED NULL,
    delivery_person_id INT UNSIGNED NULL,
    pickup_hub_id INT UNSIGNED NULL,
    delivery_hub_id INT UNSIGNED NULL,
    pickup_address TEXT NOT NULL,
    pickup_city VARCHAR(80) NOT NULL DEFAULT 'Kano',
    pickup_contact_name VARCHAR(150) NOT NULL,
    pickup_contact_phone VARCHAR(32) NOT NULL,
    pickup_latitude DECIMAL(10,7) NULL,
    pickup_longitude DECIMAL(10,7) NULL,
    delivery_address TEXT NOT NULL,
    delivery_city VARCHAR(80) NOT NULL DEFAULT 'Kano',
    delivery_contact_name VARCHAR(150) NOT NULL,
    delivery_contact_phone VARCHAR(32) NOT NULL,
    delivery_latitude DECIMAL(10,7) NULL,
    delivery_longitude DECIMAL(10,7) NULL,
    service_type ENUM('same_day', 'scheduled', 'business') NOT NULL DEFAULT 'same_day',
    item_description TEXT NOT NULL,
    item_category VARCHAR(100) NOT NULL,
    item_quantity INT UNSIGNED NOT NULL DEFAULT 1,
    item_weight DECIMAL(9,2) NOT NULL,
    item_dimensions VARCHAR(150) NULL,
    is_fragile TINYINT(1) NOT NULL DEFAULT 0,
    is_perishable TINYINT(1) NOT NULL DEFAULT 0,
    special_instructions TEXT NULL,
    distance_km DECIMAL(10,2) NOT NULL,
    base_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    weight_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fragile_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_status ENUM('unpaid', 'pending', 'paid', 'refunded', 'waived') NOT NULL DEFAULT 'unpaid',
    status ENUM('pending', 'under_review', 'broadcasted', 'assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived', 'delivered', 'completed', 'cancelled', 'rejected', 'failed') NOT NULL DEFAULT 'pending',
    status_reason VARCHAR(500) NULL,
    delivery_otp CHAR(6) NULL,
    otp_failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    otp_locked_until DATETIME NULL,
    preferred_pickup_time DATETIME NULL,
    preferred_delivery_time DATETIME NULL,
    request_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pickup_time DATETIME NULL,
    delivery_time DATETIME NULL,
    tracking_started_at DATETIME NULL,
    last_location_latitude DECIMAL(10,7) NULL,
    last_location_longitude DECIMAL(10,7) NULL,
    last_location_accuracy_m DECIMAL(8,2) NULL,
    last_location_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_deliveries_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_deliveries_driver FOREIGN KEY (delivery_person_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_deliveries_business FOREIGN KEY (business_account_id) REFERENCES business_accounts(id) ON DELETE SET NULL,
    CONSTRAINT fk_deliveries_pickup_hub FOREIGN KEY (pickup_hub_id) REFERENCES hubs(id) ON DELETE SET NULL,
    CONSTRAINT fk_deliveries_delivery_hub FOREIGN KEY (delivery_hub_id) REFERENCES hubs(id) ON DELETE SET NULL,
    INDEX idx_deliveries_status (status),
    INDEX idx_deliveries_client_status (client_id, status),
    INDEX idx_deliveries_client_time (client_id, request_time),
    INDEX idx_deliveries_client_status_time (client_id, status, request_time),
    INDEX idx_deliveries_client_status_id (client_id, status, id),
    INDEX idx_deliveries_driver_status (delivery_person_id, status),
    INDEX idx_deliveries_driver_time (delivery_person_id, request_time),
    INDEX idx_deliveries_driver_status_time (delivery_person_id, status, request_time),
    INDEX idx_deliveries_driver_status_id (delivery_person_id, status, id),
    INDEX idx_deliveries_status_time (status, request_time),
    INDEX idx_deliveries_status_id (status, id),
    INDEX idx_deliveries_offer_lookup (status, delivery_person_id, pickup_city, delivery_city, request_time),
    INDEX idx_deliveries_service_area (pickup_city, delivery_city, status),
    INDEX idx_deliveries_request_time (request_time),
    INDEX idx_deliveries_payment_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_price_quotes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NULL,
    rate_card_id INT UNSIGNED NULL,
    quote_status ENUM('estimated', 'accepted', 'superseded', 'cancelled') NOT NULL DEFAULT 'estimated',
    currency CHAR(3) NOT NULL DEFAULT 'NGN',
    distance_km DECIMAL(10,2) NOT NULL,
    weight_kg DECIMAL(9,2) NOT NULL,
    breakdown JSON NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    calculated_by INT UNSIGNED NULL,
    calculated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    CONSTRAINT fk_delivery_price_quotes_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_price_quotes_rate FOREIGN KEY (rate_card_id) REFERENCES rate_cards(id) ON DELETE SET NULL,
    CONSTRAINT fk_delivery_price_quotes_user FOREIGN KEY (calculated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_delivery_price_quotes_delivery_status (delivery_id, quote_status),
    INDEX idx_delivery_price_quotes_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each broadcast is materialized per eligible driver. The atomic acceptance
-- transaction locks exactly one delivery_assignments row for a delivery.
CREATE TABLE IF NOT EXISTS delivery_driver_offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    driver_id INT UNSIGNED NOT NULL,
    offered_by INT UNSIGNED NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    driver_id INT UNSIGNED NOT NULL,
    accepted_offer_id BIGINT UNSIGNED NULL,
    assigned_by INT UNSIGNED NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NOT NULL,
    changed_by_user_id INT UNSIGNED NULL,
    changed_by_role ENUM('client', 'delivery', 'admin', 'system') NOT NULL,
    reason VARCHAR(500) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_status_history_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_status_history_actor FOREIGN KEY (changed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status_history_delivery_time (delivery_id, created_at),
    INDEX idx_status_history_status (to_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_location_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    delivery_person_id INT UNSIGNED NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    accuracy_m DECIMAL(8,2) NULL,
    heading_degrees DECIMAL(6,2) NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_location_events_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_location_events_driver FOREIGN KEY (delivery_person_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_location_events_delivery_time (delivery_id, recorded_at),
    INDEX idx_location_events_delivery_id (delivery_id, id),
    INDEX idx_location_events_driver_time (delivery_person_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_proofs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id BIGINT UNSIGNED NOT NULL,
    captured_by_driver_id INT UNSIGNED NOT NULL,
    proof_type ENUM('otp', 'recipient_name', 'signature', 'photo', 'note') NOT NULL,
    storage_key VARCHAR(500) NULL,
    recipient_name VARCHAR(150) NULL,
    verification_status ENUM('captured', 'verified', 'rejected') NOT NULL DEFAULT 'captured',
    metadata JSON NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    CONSTRAINT fk_delivery_proofs_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    CONSTRAINT fk_delivery_proofs_driver FOREIGN KEY (captured_by_driver_id) REFERENCES drivers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_delivery_proofs_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_delivery_proofs_delivery (delivery_id, proof_type),
    INDEX idx_delivery_proofs_verification (verification_status, captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS delivery_earnings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_person_id INT UNSIGNED NOT NULL,
    delivery_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    earning_type ENUM('delivery_fee', 'adjustment', 'bonus', 'deduction') NOT NULL DEFAULT 'delivery_fee',
    status ENUM('pending', 'paid', 'void') NOT NULL DEFAULT 'pending',
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_earnings_driver FOREIGN KEY (delivery_person_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_earnings_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id) ON DELETE CASCADE,
    UNIQUE KEY uq_earnings_delivery_fee (delivery_id, earning_type),
    INDEX idx_earnings_driver_status (delivery_person_id, status),
    INDEX idx_earnings_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    delivery_id BIGINT UNSIGNED NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT UNSIGNED NULL,
    actor_role ENUM('client', 'delivery', 'admin', 'system') NOT NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    delivery_id BIGINT UNSIGNED NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atomic database fallback for development. Production uses Redis; this table
-- is provisioned during deployment and never created by an HTTP request.
CREATE TABLE IF NOT EXISTS rate_limit_buckets (
    rate_key CHAR(64) NOT NULL PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_expires_at INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rate_limit_bucket_expiry (window_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Asynchronous background job queue table
CREATE TABLE IF NOT EXISTS job_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_name VARCHAR(60) NOT NULL DEFAULT 'default',
    job_type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reserved_at DATETIME NULL,
    completed_at DATETIME NULL,
    failed_at DATETIME NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_status_available (queue_name, status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
