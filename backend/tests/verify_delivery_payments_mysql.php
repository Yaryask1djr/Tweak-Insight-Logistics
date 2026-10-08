<?php
/** Native PHP + EMPTY disposable MySQL DB ending _payment_test. Never uses application credentials/bootstrap.
 * Six processes exercise fare/checkout/receipt contention against canonical InnoDB tables with a simulated provider.
 */
require_once __DIR__ . '/support/PaymentFixture.php';
$dsn = (string)getenv('PAYMENT_TEST_MYSQL_DSN');
if (!preg_match('/\Amysql:.*\bdbname=([A-Za-z0-9_]+_payment_test)(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Provide PAYMENT_TEST_MYSQL_DSN for an empty disposable database ending in _payment_test.\n"); exit(2);
}
paymentEnvironment();
$db = new PDO($dsn, (string)getenv('PAYMENT_TEST_MYSQL_USER'), (string)getenv('PAYMENT_TEST_MYSQL_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$db->exec("SET time_zone = '+00:00'");
if (($argv[1] ?? '') === '--child') {
    $gateway = new TestPaymentGateway($db);
    $gateway->duringInitialize = static function (): void { usleep(250000); };
    try {
        $operation = $argv[2] ?? '';
        if ($operation === 'approve') DeliveryPayments::approveFare($db, 1, 99, 0, '650.25', 'Native concurrency test');
        elseif ($operation === 'initialize') DeliveryPayments::initialize($db, 1, 1, 1, $gateway);
        elseif ($operation === 'verify') DeliveryPayments::verifyOwned($db, (string)$db->query('SELECT reference FROM delivery_payment_attempts')->fetchColumn(), 1, $gateway);
        else throw new LogicException('Invalid child operation');
        echo json_encode(['ok' => true, 'initializations' => $gateway->initializations], JSON_THROW_ON_ERROR);
    } catch (TransactionBusinessException $e) {
        if ($e->getStatusCode() !== 409 || ($argv[2] ?? '') !== 'initialize') throw $e;
        echo json_encode(['ok' => false, 'initializations' => $gateway->initializations], JSON_THROW_ON_ERROR);
    }
    exit;
}
if ($db->query('SHOW TABLES')->fetchColumn() !== false) throw new RuntimeException('Test database must be empty.');
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS [a-z_]+ \([\s\S]*?\) ENGINE=InnoDB[^;]*;/', $schema, $tables);
foreach ($tables[0] as $sql) $db->exec($sql);
$db->exec("INSERT INTO users (id,role,full_name,email,phone,password_hash) VALUES
    (1,'client','Test client','client@example.test','08000000000','not-a-real-credential'),
    (99,'admin','Test admin','admin@example.test','08000000099','not-a-real-credential')");
DeliveryPayments::transaction($db, static fn(PDO $db) => DeliveryBooking::apply($db, 1, 'native-payment-booking', str_repeat('a', 64), 'bookingPlan'));
$db->exec("UPDATE deliveries SET status = 'driver_en_route'");
function clients(string $operation): array {
    $children = []; $responses = [];
    for ($i = 0; $i < 6; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--child', $operation], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot spawn native PHP process');
        fclose($pipes[0]); $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Child failed: ' . $err);
        $responses[] = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
    }
    return $responses;
}
clients('approve');
if ((int)$db->query('SELECT COUNT(*) FROM delivery_fare_approvals')->fetchColumn() !== 1) throw new RuntimeException('Duplicate approvals');
$responses = clients('initialize');
if (array_sum(array_column($responses, 'initializations')) !== 1 || (int)$db->query('SELECT COUNT(*) FROM delivery_payment_attempts')->fetchColumn() !== 1) throw new RuntimeException('Duplicate initialization/reference');
clients('verify');
if ((int)$db->query('SELECT COUNT(*) FROM delivery_payment_receipts')->fetchColumn() !== 1
    || (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.provider_verified'")->fetchColumn() !== 1) throw new RuntimeException('Duplicate financial facts');
DeliveryPayments::transaction($db, static function (PDO $db): void {
    $delivery = $db->query('SELECT * FROM deliveries WHERE id = 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
    DeliveryPayments::requirePickupEvidence($db, $delivery);
});
$attempt = (string)$db->query('SELECT id FROM delivery_payment_attempts')->fetchColumn();
DeliveryPayments::hold($db, $attempt, 'provider_refund_pending');
DeliveryPayments::reconcile($db, $attempt, (new TestPaymentGateway($db))->verify((string)$db->query('SELECT reference FROM delivery_payment_attempts')->fetchColumn()));
if (DeliveryPayments::pickupFlags($db, $db->query('SELECT * FROM deliveries')->fetchAll(PDO::FETCH_ASSOC))[1]) throw new RuntimeException('Refund hold was removed');
foreach (['fare' => 'delivery_fare_approvals', 'receipt' => 'delivery_payment_receipts'] as $kind => $table) {
    foreach (['UPDATE','DELETE'] as $action) {
        $name = 'payment_' . $kind . '_no_' . strtolower($action);
        $db->exec("CREATE TRIGGER {$name} BEFORE {$action} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable'");
        $rejected = false;
        try { $db->exec($action === 'UPDATE' ? "UPDATE {$table} SET amount_minor = 1" : "DELETE FROM {$table}"); } catch (PDOException $e) { $rejected = true; }
        if (!$rejected) throw new RuntimeException('Mutable payment facts');
    }
}
echo "PASS: native MySQL fare, checkout and receipt contention; six clients per operation, evidence gate and immutable triggers. Provider simulated.\n";
