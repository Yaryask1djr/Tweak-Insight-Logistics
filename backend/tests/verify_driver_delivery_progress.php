<?php

/** Executes production milestone logic on SQLite. MySQL locking requires native staging tests. */
require_once __DIR__ . '/../helpers/driver_delivery_progress.php';
require_once __DIR__ . '/support/PaymentFixture.php';
paymentEnvironment();

final class ProgressTestDb extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->sqliteCreateFunction('UTC_TIMESTAMP', static fn() => '2026-09-20 12:00:00');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

$checks = 0;
function check(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function fixture(?string $payment = 'paid', string $status = 'driver_en_route', ?string $pickup = null): PDO
{
    $db = new ProgressTestDb();
    $db->exec("CREATE TABLE deliveries (id INTEGER PRIMARY KEY, status TEXT, payment_status TEXT, pickup_time TEXT, status_reason TEXT, delivery_person_id INTEGER, client_id INTEGER DEFAULT 1, total_cost TEXT DEFAULT '650.25');
        CREATE TABLE users (id INTEGER PRIMARY KEY, is_approved INTEGER, account_status TEXT, role TEXT);
        CREATE TABLE drivers (id INTEGER PRIMARY KEY, user_id INTEGER, kyc_status TEXT, active_status TEXT);
        CREATE TABLE driver_availability (driver_id INTEGER PRIMARY KEY, availability_status TEXT, available_since TEXT);
        CREATE TABLE delivery_status_history (id INTEGER PRIMARY KEY, delivery_id INTEGER, from_status TEXT, to_status TEXT,
            changed_by_user_id INTEGER, changed_by_role TEXT, reason TEXT, metadata TEXT);
        CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INTEGER, actor_role TEXT, action TEXT, entity_type TEXT,
            entity_id INTEGER, delivery_id INTEGER, before_state TEXT, after_state TEXT, metadata TEXT, request_id TEXT, ip_address BLOB);
        INSERT INTO users VALUES (10, 1, 'active', 'delivery');
        INSERT INTO drivers VALUES (5, 10, 'verified', 'active');
        INSERT INTO driver_availability VALUES (5, 'busy', NULL)");
    $db->prepare('INSERT INTO deliveries (id,status,payment_status,pickup_time,status_reason,delivery_person_id) VALUES (1, ?, ?, ?, NULL, 10)')->execute([$status, $payment, $pickup]);
    paymentTables($db);
    $hash = DeliveryPayments::inputHash($db->query('SELECT * FROM deliveries')->fetch(PDO::FETCH_ASSOC));
    $db->prepare("INSERT INTO delivery_fare_approvals (id,delivery_id,version,amount_minor,currency,input_hash) VALUES (1,1,1,65025,'NGN',?)")->execute([$hash]);
    $db->exec("INSERT INTO delivery_payment_attempts (id,reference,delivery_id,client_id,fare_approval_id,provider,environment,amount_minor,currency,status)
        VALUES ('fixture','TILPAY-fixture',1,1,1,'paystack','test',65025,'NGN','verified');
        INSERT INTO delivery_payment_receipts (attempt_id,delivery_id,fare_approval_id,client_id,provider,environment,reference,provider_transaction_id,amount_minor,currency)
        VALUES ('fixture',1,1,1,'paystack','test','TILPAY-fixture','10001',65025,'NGN')");
    return $db;
}
function snapshot(PDO $db): array
{
    $result = [];
    foreach (['deliveries', 'users', 'drivers', 'driver_availability', 'delivery_status_history', 'audit_logs'] as $table) {
        $result[$table] = $db->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}
function advance(PDO $db, string $target = 'picked_up', ?string $reason = null): array
{
    return DatabaseTransaction::run($db, static fn(PDO $db) => DriverDeliveryProgress::apply($db, 1, 10, $target, $reason));
}
function reject(PDO $db, int $code = 409, string $target = 'picked_up', int $driver = 10, ?string $reason = null): void
{
    $before = snapshot($db);
    $db->beginTransaction();
    $caught = false;
    try { DriverDeliveryProgress::apply($db, 1, $driver, $target, $reason); }
    catch (TransactionBusinessException $e) { $caught = true; check($e->getStatusCode() === $code, 'Incorrect rejection code.'); }
    finally { $db->rollBack(); }
    check($caught, 'Unsafe milestone was accepted.');
    check(snapshot($db) === $before, 'Rejected milestone changed persisted state.');
}

foreach (['unpaid', 'pending', 'refunded', 'waived', null, '', 'Paid', 'unknown'] as $payment) reject(fixture($payment));
$db = fixture(); $db->exec("UPDATE delivery_payment_attempts SET status = 'pending'"); reject($db);
$db = fixture(); $db->exec("UPDATE deliveries SET total_cost = '999.00'"); reject($db);
$db = fixture(); $db->exec('DROP TABLE delivery_payment_receipts'); $db->exec('CREATE TABLE delivery_payment_receipts (id INTEGER, attempt_id TEXT, provider TEXT, amount_minor INTEGER, currency TEXT, client_id INTEGER, delivery_id INTEGER, fare_approval_id INTEGER, environment TEXT)'); reject($db);
$db = fixture();
try { DriverDeliveryProgress::apply($db, 1, 10, 'picked_up'); throw new RuntimeException('Untransactional milestone accepted.'); }
catch (LogicException $e) { check(!$db->inTransaction(), 'Service must not create its own transaction.'); }
$result = advance($db);
check($result === ['delivery_id' => 1, 'previous_status' => 'driver_en_route', 'status' => 'picked_up'], 'Unexpected transition response.');
$state = snapshot($db);
check(!$db->inTransaction() && $state['deliveries'][0]['status'] === 'picked_up', 'Pickup did not commit.');
check($state['deliveries'][0]['pickup_time'] === '2026-09-20 12:00:00', 'Pickup timestamp was not written.');
check($state['deliveries'][0]['payment_status'] === 'paid', 'Milestone must not change payment status.');
check(count($state['delivery_status_history']) === 1 && count($state['audit_logs']) === 1, 'Pickup lacks required records.');
check(json_decode($state['delivery_status_history'][0]['metadata'], true)['payment_status_at_pickup'] === 'paid', 'Recorded payment state is missing from pickup history.');
check($state['driver_availability'][0]['availability_status'] === 'busy', 'Pickup released the driver.');
reject($db); // Lost-response retry cannot duplicate pickup/history.
reject(fixture(), 403, 'picked_up', 11);
foreach (['assigned', 'pending', 'cancelled', 'failed'] as $from) reject(fixture('paid', $from));
reject(fixture(), 409, 'delivered'); // Completion remains OTP-only.
reject(fixture('paid', 'driver_en_route', '2026-09-19 12:00:00'));
reject(fixture('paid', 'picked_up'), 409, 'failed', 10, 'Recipient unavailable');

foreach (["UPDATE users SET account_status = 'suspended'", 'UPDATE users SET is_approved = 0', "UPDATE users SET role = 'client'",
    "UPDATE drivers SET kyc_status = 'rejected'", "UPDATE drivers SET active_status = 'inactive'", 'DELETE FROM driver_availability'] as $sql) {
    $db = fixture(); $db->exec($sql); reject($db, 403);
}
$db = fixture('unpaid', 'assigned');
advance($db, 'driver_en_route');
check($db->query('SELECT status FROM deliveries')->fetchColumn() === 'driver_en_route', 'Unpaid customers must still be reachable at pickup.');
check($db->query('SELECT pickup_time FROM deliveries')->fetchColumn() === null, 'Starting a route recorded custody.');
foreach ([['picked_up', 'in_transit'], ['in_transit', 'arrived']] as [$from, $to]) {
    $db = fixture('refunded', $from, '2026-09-19 12:00:00'); advance($db, $to);
    check($db->query('SELECT status FROM deliveries')->fetchColumn() === $to, 'A later payment change must not strand goods already in custody.');
    check($db->query('SELECT pickup_time FROM deliveries')->fetchColumn() === '2026-09-19 12:00:00', 'Later milestones changed the original pickup time.');
}
$db = fixture('unpaid'); advance($db, 'failed', '  Vehicle fault  ');
check($db->query('SELECT status_reason FROM deliveries')->fetchColumn() === 'Vehicle fault', 'Failure reason was not normalized.');
check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === 'available', 'Idle driver was not released after a pre-pickup failure.');
foreach (['picked_up', 'in_transit', 'arrived'] as $from) {
    $db = fixture('paid', $from, '2026-09-19 12:00:00'); advance($db, 'failed', 'Recipient unavailable');
    check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === 'busy', 'Custody failure released the driver.');
}
foreach ([['assigned', null], ['failed', '2026-09-19 12:00:00']] as [$other, $pickup]) {
    $db = fixture(); $db->prepare('INSERT INTO deliveries (id,status,payment_status,pickup_time,status_reason,delivery_person_id) VALUES (2, ?, ?, ?, NULL, 10)')->execute([$other, 'paid', $pickup]);
    advance($db, 'failed', 'Vehicle fault');
    check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === 'busy', 'Other work or custody was ignored.');
}
foreach (['paused', 'offline'] as $availability) {
    $db = fixture(); $db->prepare('UPDATE driver_availability SET availability_status = ?')->execute([$availability]);
    advance($db, 'failed', 'Vehicle fault');
    check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === $availability, 'Failure changed an explicit availability choice.');
}
foreach (['', str_repeat('x', 501), "Invalid\0reason"] as $reason) reject(fixture(), 422, 'failed', 10, $reason);

// Inject database failures after earlier writes to verify whole-transaction rollback.
foreach (['picked_up', 'failed'] as $target) {
    foreach (['delivery_status_history', 'audit_logs'] as $table) {
        $db = fixture(); $before = snapshot($db);
        $db->exec("CREATE TRIGGER fail_record BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'Injected record failure'); END");
        $failed = false;
        try { advance($db, $target, $target === 'failed' ? 'Vehicle fault' : null); } catch (PDOException $e) { $failed = true; }
        check($failed && !$db->inTransaction(), 'Persistence failure did not surface after rollback.');
        check(snapshot($db) === $before, 'Record failure left partial state, timestamp, availability or history.');
    }
}
$db = fixture(); $before = snapshot($db);
$db->exec("CREATE TRIGGER fail_availability BEFORE UPDATE ON driver_availability BEGIN SELECT RAISE(ABORT, 'Injected availability failure'); END");
$failed = false;
try { advance($db, 'failed', 'Vehicle fault'); } catch (PDOException $e) { $failed = true; }
check($failed && snapshot($db) === $before, 'Availability failure did not roll back milestone state.');

$input = DriverDeliveryProgress::request('{"delivery_id":"1","status":"picked_up","payment_status":"paid","pickup_time":"2000-01-01"}');
check($input === ['delivery_id' => 1, 'status' => 'picked_up', 'status_reason' => null], 'Status input accepted a client payment/timestamp override.');
reject(fixture('unpaid'), 409, $input['status']);
foreach (['[]', 'null', '{', '{"delivery_id":true,"status":"picked_up"}', '{"delivery_id":1.5,"status":"picked_up"}',
    '{"delivery_id":0,"status":"picked_up"}', '{"delivery_id":[],"status":"picked_up"}', '{"delivery_id":1,"status":[]}',
    '{"delivery_id":1,"status":"delivered"}', '{"delivery_id":1,"status":"failed","status_reason":[]}',
    '{"delivery_id":1,"status":"failed"}'] as $raw) {
    $caught = false;
    try { DriverDeliveryProgress::request($raw); } catch (TransactionBusinessException $e) { $caught = $e->getStatusCode() === 422; }
    check($caught, 'Malformed status request accepted.');
}
try { DriverDeliveryProgress::request(str_repeat(' ', 4097)); throw new RuntimeException('Oversized status request accepted.'); }
catch (TransactionBusinessException $e) { check($e->getStatusCode() === 413, 'Incorrect request size response.'); }

echo "PASS: {$checks} driver milestone/payment-gate checks (SQLite; MySQL locking and provider verification not tested).\n";
