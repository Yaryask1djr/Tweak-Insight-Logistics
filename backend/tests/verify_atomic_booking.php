<?php

require_once __DIR__ . '/support/BookingFixture.php';
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function book(PDO $db, int $client = 1, string $key = 'booking-key-for-test-001', ?string $hash = null, ?callable $builder = null): array
{
    return DatabaseTransaction::run($db, static fn(PDO $db) => DeliveryBooking::apply($db, $client, $key, $hash ?? hash('sha256', 'request'), $builder ?? 'bookingPlan'));
}
$tables = ['deliveries', 'booking_requests', 'delivery_items', 'delivery_price_quotes', 'delivery_booking_snapshots', 'delivery_status_history', 'audit_logs', 'booking_outbox'];
$db = bookingFixture();
$first = book($db);
foreach ($tables as $table) check((int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === 1, "Missing atomic record: {$table}");
check((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 0, 'Booking dispatched before outbox processing.');
check($db->query('SELECT payment_status FROM deliveries')->fetchColumn() === 'unpaid', 'Booking invented payment.');
$repeat = book($db, 1, 'booking-key-for-test-001', null, static function () { throw new RuntimeException('A retry must not reprice.'); });
check($repeat['replayed'] && $repeat['data'] === $first['data'], 'Replay must return the exact saved data.');
check((int)$db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn() === 1, 'Retry duplicated the booking.');
$db->beginTransaction();
try {
    DeliveryBooking::apply($db, 1, 'booking-key-for-test-001', hash('sha256', 'different'), 'bookingPlan');
    throw new RuntimeException('Conflicting key was accepted.');
} catch (TransactionBusinessException $error) { check($error->getStatusCode() === 409, 'Conflicting key must return 409.'); }
finally { $db->rollBack(); }
check(book($db, 2)['data']['delivery_id'] !== $first['data']['delivery_id'], 'Key scope must include client identity.');
$snapshot = $db->query('SELECT snapshot_json FROM delivery_booking_snapshots WHERE delivery_id = 1')->fetchColumn();
$db->exec('UPDATE deliveries SET total_cost = 999 WHERE id = 1');
check($db->query('SELECT snapshot_json FROM delivery_booking_snapshots WHERE delivery_id = 1')->fetchColumn() === $snapshot, 'Operational edits changed the original snapshot.');
check(BookingOutbox::processNext($db), 'Committed event was not consumed.');
check((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'Only client and active admin need inbox messages.');
check($db->query('SELECT external_delivery_status FROM booking_outbox WHERE id = 1')->fetchColumn() === 'unconfigured', 'In-app delivery is not external provider delivery.');
check(BookingOutbox::processNext($db) && !BookingOutbox::processNext($db), 'Outbox did not drain.');
check((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 4, 'Outbox retry duplicated messages.');
$db->beginTransaction();
try { BookingOutbox::processNext($db); throw new RuntimeException('Dispatched in transaction.'); }
catch (LogicException $e) { check($db->inTransaction(), 'Outbox must not commit caller transaction.'); }
finally { $db->rollBack(); }

// Inject a database failure at every mandatory record, including the last response write.
foreach ($tables as $table) {
    $db = bookingFixture();
    $operation = $table === 'booking_requests' ? 'UPDATE' : 'INSERT';
    $db->exec("CREATE TRIGGER inject_failure BEFORE {$operation} ON {$table} BEGIN SELECT RAISE(ABORT, 'Injected failure'); END");
    try { book($db); throw new RuntimeException('Failure did not propagate.'); }
    catch (PDOException $e) { check(!$db->inTransaction(), 'Failed booking left a transaction open.'); }
    foreach ($tables as $empty) check((int)$db->query("SELECT COUNT(*) FROM {$empty}")->fetchColumn() === 0, "Partial booking after {$table} failure: {$empty}");
    $db->exec('DROP TRIGGER inject_failure');
    check(!book($db)['replayed'], 'Rollback retained a poisoned idempotency key.');
}
$db = bookingFixture(); book($db);
$db->exec("CREATE TRIGGER inbox_failure BEFORE INSERT ON notifications WHEN NEW.user_id = 99 BEGIN SELECT RAISE(ABORT, 'Admin inbox failure'); END");
try { BookingOutbox::processNext($db); throw new RuntimeException('Inbox failure ignored.'); }
catch (PDOException $e) { check((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 0, 'Outbox failure left partial inbox messages.'); }
check((int)$db->query('SELECT attempts FROM booking_outbox')->fetchColumn() === 1, 'Retry attempt was not recorded.');
$db->exec("DROP TRIGGER inbox_failure; UPDATE booking_outbox SET available_at = '2000-01-01'");
check(BookingOutbox::processNext($db), 'Outbox failed to recover.');
check((int)$db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn() === 1, 'Notification failure affected booking.');

check(DeliveryBooking::requestHash((object)['b' => 2, 'a' => (object)['y' => 1, 'x' => 0]]) === DeliveryBooking::requestHash((object)['a' => (object)['x' => 0, 'y' => 1], 'b' => 2]), 'JSON key ordering changed hash.');
check(DeliveryBooking::requestHash((object)['a' => 1]) !== DeliveryBooking::requestHash((object)['a' => '1']), 'JSON types must affect hash.');
foreach ([null, '', 'short', str_repeat('x', 129), "unsafe key", "key-with-newline\n"] as $key) {
    try { DeliveryBooking::validateKey($key); throw new RuntimeException('Invalid key accepted.'); }
    catch (TransactionBusinessException $e) { check($e->getStatusCode() === 422, 'Invalid key response.'); }
}
echo "PASS: {$checks} atomic booking/outbox checks (SQLite dialect adapter; MySQL locking requires integration test).\n";
