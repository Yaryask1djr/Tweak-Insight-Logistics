<?php

require_once __DIR__ . '/../../helpers/delivery_booking.php';
require_once __DIR__ . '/../../helpers/booking_outbox.php';

function bookingPlan(PDO $db): array
{
    return ['rate_card_id' => null, 'quote' => ['base_cost' => 500, 'distance_charge' => 50, 'weight_charge' => 0, 'fragile_charge' => 0, 'total_cost' => 550], 'fields' => [
        'pickup_address' => 'Sabon Gari, Kano', 'pickup_city' => 'Kano', 'pickup_contact_name' => 'Sender', 'pickup_contact_phone' => '08000000001',
        'delivery_address' => 'Bompai, Kano', 'delivery_city' => 'Kano', 'delivery_contact_name' => 'Recipient', 'delivery_contact_phone' => '08000000002',
        'service_type' => 'same_day', 'item_description' => 'Parcel', 'item_category' => 'small_package', 'item_quantity' => 1, 'item_weight' => 2.5,
        'item_dimensions' => '', 'preferred_pickup_time' => null, 'preferred_delivery_time' => null, 'special_instructions' => '',
        'is_fragile' => 0, 'is_perishable' => 0, 'distance_km' => 6, 'base_cost' => 550, 'weight_charge' => 0, 'fragile_charge' => 0, 'total_cost' => 550,
        'pickup_latitude' => 12.0022, 'pickup_longitude' => 8.5385, 'delivery_latitude' => 12.015, 'delivery_longitude' => 8.555,
    ]];
}

/** Only SQL dialect changes: production services themselves are exercised. */
final class BookingSqliteDb extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->sqliteCreateFunction('UTC_TIMESTAMP', static fn() => gmdate('Y-m-d H:i:s'));
    }
    private function sql(string $query): string
    {
        return str_replace([' FOR UPDATE', 'ON DUPLICATE KEY UPDATE key_hash = VALUES(key_hash)', 'ON DUPLICATE KEY UPDATE event_hash = VALUES(event_hash)'],
            ['', 'ON CONFLICT(client_id, operation, key_hash) DO UPDATE SET key_hash = excluded.key_hash', 'ON CONFLICT(event_hash) DO UPDATE SET event_hash = excluded.event_hash'], $query);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->sql($query), $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null ? parent::query($this->sql($query)) : parent::query($this->sql($query), $fetchMode, ...$fetchModeArgs);
    }
}

function bookingFixture(): PDO
{
    $db = new BookingSqliteDb();
    $columns = array_keys(bookingPlan($db)['fields']);
    $db->exec('CREATE TABLE deliveries (id INTEGER PRIMARY KEY, client_id INTEGER, tracking_number TEXT UNIQUE, public_tracking_token TEXT UNIQUE,
        delivery_otp TEXT, status TEXT, payment_status TEXT, ' . implode(', ', array_map(static fn($name) => $name . ' TEXT', $columns)) . ')');
    $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, account_status TEXT);
        INSERT INTO users VALUES (1, 'client', 'active'), (2, 'client', 'active'), (99, 'admin', 'active'), (100, 'admin', 'suspended');
        CREATE TABLE booking_requests (id INTEGER PRIMARY KEY, client_id INTEGER, operation TEXT, key_hash TEXT, request_hash TEXT,
            delivery_id INTEGER UNIQUE, response_json TEXT, UNIQUE(client_id, operation, key_hash));
        CREATE TABLE delivery_items (id INTEGER PRIMARY KEY, delivery_id INTEGER, description TEXT, category TEXT, quantity INTEGER,
            weight_kg REAL, dimensions TEXT, is_fragile INTEGER, is_perishable INTEGER);
        CREATE TABLE delivery_price_quotes (id INTEGER PRIMARY KEY, delivery_id INTEGER, rate_card_id INTEGER, quote_status TEXT,
            distance_km REAL, weight_kg REAL, breakdown TEXT, total_amount REAL, calculated_by INTEGER);
        CREATE TABLE delivery_booking_snapshots (delivery_id INTEGER PRIMARY KEY, snapshot_json TEXT, snapshot_hash TEXT);
        CREATE TRIGGER snapshot_no_update BEFORE UPDATE ON delivery_booking_snapshots BEGIN SELECT RAISE(ABORT, 'Immutable'); END;
        CREATE TABLE booking_outbox (id INTEGER PRIMARY KEY, delivery_id INTEGER, event_type TEXT, payload TEXT, status TEXT DEFAULT 'pending',
            attempts INTEGER DEFAULT 0, available_at TEXT DEFAULT CURRENT_TIMESTAMP, processed_at TEXT, last_error TEXT,
            external_delivery_status TEXT DEFAULT 'unconfigured', UNIQUE(delivery_id, event_type));
        CREATE TABLE delivery_status_history (id INTEGER PRIMARY KEY, delivery_id INTEGER, from_status TEXT, to_status TEXT,
            changed_by_user_id INTEGER, changed_by_role TEXT, reason TEXT, metadata TEXT);
        CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INTEGER, actor_role TEXT, action TEXT, entity_type TEXT,
            entity_id INTEGER, delivery_id INTEGER, before_state TEXT, after_state TEXT, metadata TEXT, request_id TEXT, ip_address BLOB);
        CREATE TABLE notifications (id INTEGER PRIMARY KEY, user_id INTEGER, delivery_id INTEGER, channel TEXT, notification_type TEXT,
            title TEXT, body TEXT, payload TEXT, delivery_status TEXT, sent_at TEXT)");
    return $db;
}
