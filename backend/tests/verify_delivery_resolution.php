<?php

/**
 * Execute the actual resolution service against in-memory SQLite.
 * Only FOR UPDATE is omitted by this adapter: MySQL lock/concurrency behavior
 * still requires staging tests. These tests verify effects and atomic rollback.
 */
require_once __DIR__ . '/../helpers/delivery_resolution.php';

final class ResolutionTestDb extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->sqliteCreateFunction('UTC_TIMESTAMP', static fn() => '2026-09-20 00:00:00');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function fixture(string $status = 'assigned', ?string $pickup = null, string $availability = 'busy'): PDO
{
    $db = new ResolutionTestDb();
    $db->exec("CREATE TABLE deliveries (id INTEGER PRIMARY KEY, status TEXT, tracking_number TEXT,
        delivery_person_id INTEGER, pickup_time TEXT, status_reason TEXT, payment_status TEXT);
        CREATE TABLE users (id INTEGER PRIMARY KEY, is_approved INTEGER, account_status TEXT, role TEXT);
        CREATE TABLE drivers (id INTEGER PRIMARY KEY, user_id INTEGER, kyc_status TEXT, active_status TEXT);
        CREATE TABLE driver_availability (driver_id INTEGER PRIMARY KEY, availability_status TEXT, available_since TEXT);
        CREATE TABLE delivery_assignments (id INTEGER PRIMARY KEY, delivery_id INTEGER, driver_id INTEGER,
            assignment_status TEXT, is_current INTEGER, released_at TEXT, release_reason TEXT);
        CREATE TABLE delivery_driver_offers (id INTEGER PRIMARY KEY, delivery_id INTEGER, driver_id INTEGER,
            offer_status TEXT, responded_at TEXT, response_reason TEXT);
        CREATE TABLE delivery_status_history (id INTEGER PRIMARY KEY, delivery_id INTEGER, from_status TEXT,
            to_status TEXT, changed_by_user_id INTEGER, changed_by_role TEXT, reason TEXT, metadata TEXT);
        CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INTEGER, actor_role TEXT, action TEXT,
            entity_type TEXT, entity_id INTEGER, delivery_id INTEGER, before_state TEXT, after_state TEXT,
            metadata TEXT, request_id TEXT, ip_address BLOB);
        INSERT INTO users VALUES (10, 1, 'active', 'delivery');
        INSERT INTO drivers VALUES (5, 10, 'verified', 'active');
        INSERT INTO delivery_assignments VALUES (1, 1, 5, 'locked', 1, NULL, NULL);
        INSERT INTO delivery_driver_offers VALUES (1, 1, 5, 'accepted', NULL, NULL);
        INSERT INTO delivery_driver_offers VALUES (2, 1, 6, 'offered', NULL, NULL);
        INSERT INTO delivery_driver_offers VALUES (3, 2, 7, 'offered', NULL, NULL);");
    $db->prepare("INSERT INTO deliveries VALUES (1, ?, 'TIL-TEST', 10, ?, NULL, 'paid')")->execute([$status, $pickup]);
    $db->prepare('INSERT INTO driver_availability VALUES (5, ?, NULL)')->execute([$availability]);
    return $db;
}
function resolve(PDO $db, string $reason = 'Client requested cancellation'): void
{
    DatabaseTransaction::run($db, static fn(PDO $db) => DeliveryResolution::apply($db, 1, 'cancelled', 99, $reason));
}
function rejectResolution(PDO $db, string $reason = 'Client requested cancellation'): void
{
    $db->beginTransaction();
    $rejected = false;
    try { DeliveryResolution::apply($db, 1, 'cancelled', 99, $reason); }
    catch (TransactionBusinessException $e) { $rejected = true; }
    finally { $db->rollBack(); }
    check($rejected, 'Unsafe cancellation was accepted.');
}

$db = fixture();
resolve($db);
check($db->query('SELECT status FROM deliveries')->fetchColumn() === 'cancelled', 'Delivery must be cancelled.');
check($db->query('SELECT payment_status FROM deliveries')->fetchColumn() === 'paid', 'Cancellation must not invent a refund or change payment truth.');
check($db->query('SELECT is_current FROM delivery_assignments')->fetchColumn() === 0, 'Current assignment must close.');
check($db->query('SELECT assignment_status FROM delivery_assignments')->fetchColumn() === 'cancelled', 'Assignment must record cancellation.');
check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === 'available', 'Eligible idle driver must become available.');
check($db->query('SELECT offer_status FROM delivery_driver_offers WHERE id = 2')->fetchColumn() === 'withdrawn', 'Open offer must be withdrawn.');
check($db->query('SELECT offer_status FROM delivery_driver_offers WHERE id = 1')->fetchColumn() === 'accepted', 'Historical acceptance must be preserved.');
check($db->query('SELECT offer_status FROM delivery_driver_offers WHERE id = 3')->fetchColumn() === 'offered', 'Other deliveries must be unchanged.');
check((int)$db->query('SELECT COUNT(*) FROM delivery_status_history')->fetchColumn() === 1, 'One status event is required.');
check((int)$db->query('SELECT delivery_id FROM audit_logs')->fetchColumn() === 1, 'Audit entry must link to the delivery.');
rejectResolution($db);
check((int)$db->query('SELECT COUNT(*) FROM delivery_status_history')->fetchColumn() === 1, 'Repeated cancellation must not duplicate history.');

foreach (['picked_up', 'in_transit', 'arrived', 'failed'] as $status) {
    $db = fixture($status, '2026-09-19 23:00:00');
    rejectResolution($db);
    check($db->query('SELECT status FROM deliveries')->fetchColumn() === $status, 'Custody state must remain unchanged.');
    check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === 'busy', 'Driver holding goods must remain busy.');
}
$db = fixture('assigned', '2026-09-19 23:00:00');
rejectResolution($db); // Defend against inconsistent legacy statuses with a recorded pickup.

foreach (['offline', 'paused'] as $availability) {
    $db = fixture('assigned', null, $availability);
    resolve($db);
    check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === $availability, 'Cancellation must not override a driver-selected availability state.');
}
$db = fixture();
$db->exec("UPDATE users SET account_status = 'suspended'");
resolve($db);
check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === 'offline', 'Suspended driver must not become dispatchable.');

foreach ([['assigned', null], ['failed', '2026-09-19 23:00:00'], ['cancelled', '2026-09-19 23:00:00']] as [$status, $pickup]) {
    $db = fixture();
    $db->prepare("INSERT INTO deliveries VALUES (2, ?, 'OTHER', 10, ?, NULL, 'unpaid')")->execute([$status, $pickup]);
    resolve($db);
    check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === 'busy', 'Other unresolved work must keep the driver busy.');
}
$db = fixture('broadcasted');
$db->exec('UPDATE deliveries SET delivery_person_id = NULL; DELETE FROM delivery_assignments;');
resolve($db);
check($db->query('SELECT offer_status FROM delivery_driver_offers WHERE id = 2')->fetchColumn() === 'withdrawn', 'Unassigned cancellation must withdraw offers.');

$db = fixture();
$db->exec('UPDATE delivery_assignments SET driver_id = 999');
rejectResolution($db);
check($db->query('SELECT status FROM deliveries')->fetchColumn() === 'assigned', 'Inconsistent assignments must not partially cancel.');
foreach (['', str_repeat('x', 501)] as $reason) rejectResolution(fixture(), $reason);

// Trigger actual database failures after the state writes, and require complete rollback.
foreach (['delivery_status_history', 'audit_logs'] as $table) {
    $db = fixture();
    $db->exec("CREATE TRIGGER reject_insert BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'Injected record failure'); END");
    $failed = false;
    try { resolve($db); } catch (PDOException $e) { $failed = true; }
    check($failed && !$db->inTransaction(), 'Record failure must surface after rollback.');
    check($db->query('SELECT status FROM deliveries')->fetchColumn() === 'assigned', 'Record failure must roll back delivery status.');
    check($db->query('SELECT is_current FROM delivery_assignments')->fetchColumn() === 1, 'Record failure must roll back assignment closure.');
    check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === 'busy', 'Record failure must roll back driver release.');
    check($db->query('SELECT offer_status FROM delivery_driver_offers WHERE id = 2')->fetchColumn() === 'offered', 'Record failure must roll back offer withdrawal.');
    check((int)$db->query('SELECT COUNT(*) FROM delivery_status_history')->fetchColumn() === 0, 'Record failure must roll back history.');
}
echo "PASS: {$checks} cancellation and rollback checks (SQLite; MySQL locks not simulated).\n";
