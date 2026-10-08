<?php
declare(strict_types=1);
// Real service implementations; SQLite adapts MySQL syntax only. This is not a locking test.
putenv('LOG_CHANNEL=file'); putenv('APP_ENV=test');
require_once __DIR__ . '/../helpers/account_registration.php';
require_once __DIR__ . '/../helpers/kyc_workflow.php';
require_once __DIR__ . '/../helpers/notification_inbox.php';
require_once __DIR__ . '/../helpers/maintenance_runner.php';

final class EnterpriseFixture extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'));
        $this->sqliteCreateFunction('DATABASE', static fn() => 'test');
        $this->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, full_name TEXT, email TEXT UNIQUE COLLATE NOCASE, phone TEXT, password_hash TEXT, address TEXT, is_approved INTEGER DEFAULT 0, account_status TEXT DEFAULT 'active');
            CREATE TABLE clients (id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE, client_type TEXT, kyc_status TEXT DEFAULT 'not_submitted', kyc_rejection_reason TEXT, kyc_reviewed_by INTEGER, kyc_reviewed_at TEXT);
            CREATE TABLE drivers (id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE, kyc_status TEXT DEFAULT 'not_submitted', active_status TEXT DEFAULT 'inactive', kyc_rejection_reason TEXT, kyc_reviewed_by INTEGER, kyc_reviewed_at TEXT);
            CREATE TABLE driver_availability (driver_id INTEGER PRIMARY KEY, availability_status TEXT);
            CREATE TABLE client_kyc_documents (id INTEGER PRIMARY KEY, client_id INTEGER, document_type TEXT, storage_key TEXT, document_number TEXT, expires_at TEXT, retention_until TEXT, verification_status TEXT, rejection_reason TEXT, reviewed_by INTEGER, reviewed_at TEXT, UNIQUE(client_id, document_type));
            CREATE TABLE driver_documents (id INTEGER PRIMARY KEY, driver_id INTEGER, document_type TEXT, storage_key TEXT, document_number TEXT, expires_at TEXT, retention_until TEXT, verification_status TEXT, rejection_reason TEXT, reviewed_by INTEGER, reviewed_at TEXT);
            CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, actor_user_id INTEGER, actor_role TEXT, action TEXT, entity_type TEXT, entity_id INTEGER, delivery_id INTEGER, before_state TEXT, after_state TEXT, metadata TEXT, request_id TEXT, ip_address BLOB);
            CREATE TABLE notifications (id INTEGER PRIMARY KEY, user_id INTEGER, channel TEXT, delivery_id INTEGER, notification_type TEXT, title TEXT, body TEXT, payload TEXT, delivery_status TEXT, sent_at TEXT, read_at TEXT, created_at TEXT);
            CREATE TABLE deliveries (id INTEGER PRIMARY KEY, status TEXT);
            CREATE TABLE delivery_proofs (id INTEGER PRIMARY KEY, delivery_id INTEGER, captured_by_driver_id INTEGER, proof_type TEXT, verification_status TEXT, metadata TEXT);
            ATTACH DATABASE ':memory:' AS INFORMATION_SCHEMA;
            CREATE TABLE INFORMATION_SCHEMA.TABLES (TABLE_NAME TEXT, TABLE_SCHEMA TEXT);
            INSERT INTO INFORMATION_SCHEMA.TABLES VALUES ('drivers', 'test'), ('delivery_proofs', 'test');");
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace([' FOR UPDATE', 'INSERT IGNORE'], ['', 'INSERT OR IGNORE'], $query), $options);
    }
}
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function denied(callable $work, int $code): void {
    try { $work(); } catch (TransactionBusinessException $e) { check($e->getStatusCode() === $code, 'Wrong business response'); return; }
    throw new RuntimeException('Expected business rejection ' . $code);
}
function fails(callable $work): void {
    try { $work(); } catch (PDOException $e) { check(true, 'Storage failure propagated'); return; }
    throw new RuntimeException('Expected storage failure');
}
$db = new EnterpriseFixture();
$fields = ['full_name' => 'Test User', 'email' => 'client@example.test', 'phone' => '08000000000', 'address' => 'Kano', 'password' => 'test-password-only'];
foreach (['[]', 'null', 'true', '{'] as $raw) denied(static fn() => HttpInput::parseObject($raw), 422);
denied(static fn() => HttpInput::parseObject(str_repeat(' ', 16385)), 413);
foreach (['email' => [], 'full_name' => (object)[], 'phone' => str_repeat('1', 33), 'password' => str_repeat('é', 37)] as $key => $value) denied(static fn() => AccountRegistration::validate(array_replace($fields, [$key => $value]), 'client'), 422);
foreach ([0, -1, true, [], '2e3', '4294967296'] as $id) denied(static fn() => HttpInput::positiveId(['id' => $id], 'id'), 422);
check(HttpInput::positiveId(['id' => '123'], 'id') === 123, 'Valid ID');
$client = AccountRegistration::create($db, $fields, 'client');
check((int)$db->query('SELECT COUNT(*) FROM clients')->fetchColumn() === 1, 'Client profile created');
check(password_verify($fields['password'], $db->query('SELECT password_hash FROM users')->fetchColumn()), 'Password hash matches');
denied(static fn() => AccountRegistration::create($db, $fields, 'client'), 409);
$db->exec("CREATE TRIGGER fail_driver BEFORE INSERT ON driver_availability BEGIN SELECT RAISE(ABORT, 'Injected storage failure'); END");
fails(static fn() => AccountRegistration::create($db, array_replace($fields, ['email' => 'driver@example.test']), 'delivery'));
check((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM drivers')->fetchColumn() === 0, 'Profile failure rolls back account');
$db->exec('DROP TRIGGER fail_driver');
$driver = AccountRegistration::create($db, array_replace($fields, ['email' => 'driver@example.test']), 'delivery');
$meta = KycWorkflow::uploadMetadata(['document_number' => 'ID123', 'expires_at' => '2099-12-31']);
foreach ([['expires_at' => '2026-02-30'], ['expires_at' => '2000-01-01'], ['document_number' => []], ['document_number' => str_repeat('x', 161)]] as $input) denied(static fn() => KycWorkflow::uploadMetadata($input), 422);
$upload = KycWorkflow::recordUpload($db, $client, 'client', 'national_id', 'private/first.pdf', $meta, '2099-12-31');
$replacement = KycWorkflow::recordUpload($db, $client, 'client', 'national_id', 'private/second.pdf', $meta, '2099-12-31');
check($replacement['document_id'] === $upload['document_id'] && $replacement['previous_storage_key'] === 'private/first.pdf', 'Replacement preserves identity and returns cleanup key');
$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'Injected audit failure'); END");
fails(static fn() => KycWorkflow::clientDecision($db, $client, 99, true));
check($db->query('SELECT kyc_status FROM clients')->fetchColumn() === 'submitted', 'Audit failure rolls back approval');
check($db->query('SELECT verification_status FROM client_kyc_documents')->fetchColumn() === 'pending', 'Audit failure rolls back document');
fails(static fn() => KycWorkflow::recordUpload($db, $client, 'client', 'national_id', 'private/uncommitted.pdf', $meta, '2099-12-31'));
check($db->query('SELECT storage_key FROM client_kyc_documents')->fetchColumn() === 'private/second.pdf', 'Upload failure retains previous metadata');
$db->exec('DROP TRIGGER fail_audit');
KycWorkflow::clientDecision($db, $client, 99, true);
denied(static fn() => KycWorkflow::clientDecision($db, $client, 99, false, 'Late review'), 409);
denied(static fn() => KycWorkflow::recordUpload($db, $client, 'client', 'national_id', 'private/late.pdf', $meta, '2099-12-31'), 409);
check($db->query('SELECT storage_key FROM client_kyc_documents')->fetchColumn() === 'private/second.pdf', 'Verified document cannot be replaced');
$id = KycWorkflow::recordUpload($db, $driver, 'delivery', 'government_id', 'driver/id.pdf', $meta, '2099-12-31')['document_id'];
$licence = KycWorkflow::recordUpload($db, $driver, 'delivery', 'drivers_license', 'driver/licence.pdf', $meta, '2099-12-31')['document_id'];
KycWorkflow::reviewDriverDocument($db, $id, 99, 'verified', '');
denied(static fn() => KycWorkflow::reviewDriverDocument($db, $id, 99, 'rejected', 'Late review'), 409);
$db->exec("UPDATE driver_documents SET expires_at = '2000-01-01' WHERE id = {$licence}");
denied(static fn() => KycWorkflow::reviewDriverDocument($db, $licence, 99, 'verified', ''), 422);
$db->exec("UPDATE driver_documents SET expires_at = '2099-12-31' WHERE id = {$licence}");
KycWorkflow::reviewDriverDocument($db, $licence, 99, 'verified', '');
$db->exec("UPDATE driver_documents SET expires_at = '2000-01-01' WHERE id = {$licence}");
denied(static fn() => KycWorkflow::driverDecision($db, $driver, 99, true), 422);
$db->exec("UPDATE driver_documents SET expires_at = '2099-12-31'; UPDATE users SET account_status = 'suspended' WHERE id = {$driver}");
denied(static fn() => KycWorkflow::driverDecision($db, $driver, 99, true), 409);
check($db->query("SELECT account_status FROM users WHERE id = {$driver}")->fetchColumn() === 'suspended', 'KYC cannot release account suspension');
$db->exec("UPDATE users SET account_status = 'active' WHERE id = {$driver}; CREATE TRIGGER fail_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'Injected audit failure'); END");
fails(static fn() => KycWorkflow::driverDecision($db, $driver, 99, true));
check((int)$db->query("SELECT is_approved FROM users WHERE id = {$driver}")->fetchColumn() === 0, 'Driver audit failure rolls back account approval');
$db->exec('DROP TRIGGER fail_audit');
KycWorkflow::driverDecision($db, $driver, 99, true);
check($db->query('SELECT availability_status FROM driver_availability')->fetchColumn() === 'offline', 'Approval does not put driver on duty');
$db->exec("INSERT INTO deliveries VALUES (1, 'arrived'); CREATE TRIGGER fail_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'Injected audit failure'); END");
fails(static fn() => DatabaseTransaction::run($db, static function (PDO $db) use ($driver): void {
    $db->exec("UPDATE deliveries SET status = 'delivered' WHERE id = 1");
    OperationalRecords::otpProofCaptured($db, 1, $driver, true);
}, 1, false));
check($db->query('SELECT status FROM deliveries')->fetchColumn() === 'arrived' && (int)$db->query('SELECT COUNT(*) FROM delivery_proofs')->fetchColumn() === 0, 'Required proof audit failure rolls back proof and delivery');
$db->exec('DROP TRIGGER fail_audit');
$insert = $db->prepare("INSERT INTO notifications (id, user_id, channel, notification_type, title, body, delivery_status, created_at) VALUES (?, ?, ?, 'test', ?, ?, ?, '2026-09-29 00:00:00')");
for ($i = 1; $i <= 65; $i++) $insert->execute([$i, $client, 'in_app', 'Parcel ' . $i, $i === 60 ? '100% delivered' : 'Update', $i % 2 ? 'sent' : 'read']);
$insert->execute([66, $driver, 'in_app', 'Private driver', 'Secret', 'sent']);
$insert->execute([67, $client, 'email', 'Provider message', 'External', 'sent']);
$page = NotificationInbox::list($db, $client, ['page' => 2, 'limit' => 20]);
check($page['total'] === 65 && count($page['items']) === 20 && (int)$page['items'][0]['id'] === 45, 'Stable pagination excludes other owners and channels');
$page = NotificationInbox::list($db, $client, ['search' => '%', 'unread' => false]);
check($page['total'] === 1 && (int)$page['items'][0]['id'] === 60, 'Search escapes SQL wildcard');
$page = NotificationInbox::list($db, $client, ['unread' => true, 'sort' => 'oldest']);
check($page['total'] === 33 && (int)$page['items'][0]['id'] === 1, 'Unread filter applies across all pages');
foreach ([['page' => 10001], ['limit' => 101], ['sort' => 'id; DROP TABLE users'], ['search' => []], ['unread' => 'nonsense']] as $query) denied(static fn() => NotificationInbox::list($db, $client, $query), 422);
$visited = [];
$status = MaintenanceRunner::run(['first', 'fails', 'throws', 'last'], static function ($name) { if ($name === 'throws') throw new RuntimeException('Child failed'); return $name === 'fails' ? 2 : 0; }, static function ($name, $code) use (&$visited) { $visited[$name] = $code; });
check($status === 1 && count($visited) === 4 && $visited['last'] === 0, 'All maintenance jobs run after a child failure');
check(MaintenanceRunner::run(['one'], static fn() => 0, static fn() => null) === 0, 'Successful maintenance exit');
check(LogSanitizer::uri('/track?token=sensitive&reference=private') === '/track', 'Query secrets are removed');
check(LogSanitizer::requestId(str_repeat('x', 65)) === null && LogSanitizer::requestId("a\nb") === null, 'Untrusted request ID bounded');
$context = LogSanitizer::context(['password' => 'secret', 'nested' => ['access_token' => 'secret'], 'message' => "first\nforged"]);
check($context['password'] === '[REDACTED]' && $context['nested']['access_token'] === '[REDACTED]' && $context['message'] === 'first forged', 'Structured log redaction and injection guard');
check(method_exists(Logger::class, 'critical') && method_exists(Logger::class, 'debug'), 'Authentication failure logger methods exist');
echo "PASS enterprise boundaries: {$checks} assertions (SQLite; native locking and process execution not exercised).\n";
