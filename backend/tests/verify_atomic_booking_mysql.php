<?php
/** Native PHP + an EMPTY disposable MySQL database ending in _booking_test.
 * Creates test tables/data and leaves them available for inspection. Never uses app bootstrap.
 */
require_once __DIR__ . '/support/BookingFixture.php';
$dsn = (string)getenv('BOOKING_TEST_MYSQL_DSN');
if (!preg_match('/\Amysql:.*\bdbname=([A-Za-z0-9_]+_booking_test)(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Provide BOOKING_TEST_MYSQL_DSN for an empty disposable database ending in _booking_test.\n"); exit(2);
}
$db = new PDO($dsn, (string)getenv('BOOKING_TEST_MYSQL_USER'), (string)getenv('BOOKING_TEST_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db->exec("SET time_zone = '+00:00'");
$request = static function (PDO $db): array {
    return DatabaseTransaction::run($db, static fn(PDO $db) => DeliveryBooking::apply($db, 1, 'native-concurrent-test-key', hash('sha256', 'same-request'), static function (PDO $db): array { usleep(250000); return bookingPlan($db); }));
};
if (($argv[1] ?? '') === '--child') { echo json_encode($request($db), JSON_THROW_ON_ERROR); exit; }
if ($db->query('SHOW TABLES')->fetchColumn() !== false) throw new RuntimeException('Test database must be empty.');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS [a-z_]+ \([\s\S]*?\) ENGINE=InnoDB[^;]*;/', $schema, $tables);
foreach ($tables[0] as $sql) $db->exec($sql);
$db->exec("INSERT INTO users (id, role, full_name, email, phone, password_hash) VALUES (1,'client','Client','client@example.test','08000000000','not-a-real-credential'),(99,'admin','Admin','admin@example.test','08000000099','not-a-real-credential')");
$children = [];
for ($i=0; $i<6; $i++) {
    $process = proc_open([PHP_BINARY, __FILE__, '--child'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot spawn native PHP test client.');
    fclose($pipes[0]); $children[] = [$process, $pipes];
}
$responses = [];
foreach ($children as [$process, $pipes]) {
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Child failed: ' . $error);
    $responses[] = json_decode($out, true, 32, JSON_THROW_ON_ERROR);
}
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
check(count(array_filter($responses, static fn($r) => !$r['replayed'])) === 1, 'Exactly one request must create the booking.');
foreach ($responses as $response) check($response['data'] === $responses[0]['data'], 'Concurrent clients received different booking responses.');
foreach (['deliveries','booking_requests','delivery_items','delivery_price_quotes','delivery_booking_snapshots','delivery_status_history','audit_logs','booking_outbox'] as $table) {
    check((int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === 1, 'Incomplete/duplicated atomic record: ' . $table);
}
$db->beginTransaction();
try { DeliveryBooking::apply($db, 1, 'native-concurrent-test-key', hash('sha256', 'different'), 'bookingPlan'); throw new LogicException('Conflict accepted.'); }
catch (TransactionBusinessException $e) { check($e->getStatusCode() === 409, 'Conflicting data must return 409.'); }
finally { $db->rollBack(); }
check(BookingOutbox::processNext($db) && !BookingOutbox::processNext($db), 'Outbox did not process exactly once.');
check((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'Inbox projection duplicated or missed a recipient.');
$db->exec("CREATE TRIGGER inject_booking_history_failure BEFORE INSERT ON delivery_status_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected history failure'");
try {
    DatabaseTransaction::run($db, static fn(PDO $db) => DeliveryBooking::apply($db, 1, 'native-rollback-test-key', hash('sha256','request'), 'bookingPlan'));
    throw new LogicException('History failure ignored.');
} catch (PDOException $e) { check((int)$db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn() === 1, 'Required history failure left a partial booking.'); }
finally { $db->exec('DROP TRIGGER inject_booking_history_failure'); }
foreach (['UPDATE','DELETE'] as $action) {
    $name = 'booking_snapshot_no_' . strtolower($action);
    $db->exec("CREATE TRIGGER {$name} BEFORE {$action} ON delivery_booking_snapshots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable'");
    try { $db->exec($action === 'UPDATE' ? "UPDATE delivery_booking_snapshots SET snapshot_json = '{}'" : 'DELETE FROM delivery_booking_snapshots'); throw new LogicException('Immutable snapshot was changed.'); }
    catch (PDOException $e) { check(true, 'Snapshot mutation rejected.'); }
}
echo "PASS: {$checks} native MySQL booking checks, including six concurrent PHP clients.\n";
