<?php
require_once __DIR__ . '/support/BookingFixture.php';
require_once __DIR__ . '/../helpers/refresh_session.php';
require_once __DIR__ . '/../helpers/report_export.php';

$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
$db = bookingFixture();
$db->exec("ALTER TABLE users ADD COLUMN full_name TEXT DEFAULT 'Test'; ALTER TABLE users ADD COLUMN email TEXT DEFAULT 'test@example.test';
    ALTER TABLE users ADD COLUMN phone TEXT DEFAULT '08000000000'; ALTER TABLE users ADD COLUMN is_approved INTEGER DEFAULT 1;
    ALTER TABLE users ADD COLUMN token_version INTEGER DEFAULT 0;
    CREATE TABLE auth_refresh_sessions (id INTEGER PRIMARY KEY, user_id INTEGER, family_id TEXT, token_hash TEXT UNIQUE, token_version INTEGER,
        expires_at TEXT, last_used_at TEXT, revoked_at TEXT, revoked_reason TEXT, created_ip BLOB, user_agent_hash TEXT)");
putenv('APP_ENV=development');
$_SERVER['HTTP_USER_AGENT'] = 'Concurrent browser';
$initial = RefreshSession::issue($db, ['id' => 1, 'token_version' => 0]);
$_COOKIE['TIL_REFRESH'] = $initial;
$rotated = RefreshSession::rotate($db);
check($rotated['refresh_token'] !== $initial, 'Refresh did not rotate.');
try { RefreshSession::rotate($db); throw new LogicException('Old token authenticated during race grace.'); }
catch (RefreshRotationConflict $e) { check(true, 'Concurrent loser receives conflict.'); }
check((int)$db->query('SELECT token_version FROM users WHERE id=1')->fetchColumn() === 0, 'Benign overlap revoked account sessions.');
check((int)$db->query('SELECT COUNT(*) FROM auth_refresh_sessions')->fetchColumn() === 2, 'Race issued another credential.');
check((int)$db->query('SELECT COUNT(*) FROM auth_refresh_sessions WHERE revoked_at IS NULL')->fetchColumn() === 1, 'Winner was revoked by loser.');
$_COOKIE['TIL_REFRESH'] = $rotated['refresh_token'];
$next = RefreshSession::rotate($db);
check($next['id'] === 1, 'New cookie cannot rotate after a conflict.');

define('STORAGE_PATH', sys_get_temp_dir() . '/til-export-' . bin2hex(random_bytes(6)));
$db->exec("ALTER TABLE audit_logs ADD COLUMN created_at TEXT DEFAULT '2026-09-20 00:00:00';
    CREATE TABLE report_exports (id TEXT PRIMARY KEY, owner_user_id INTEGER, report_kind TEXT, filters TEXT, upper_id INTEGER,
        status TEXT DEFAULT 'pending', attempts INTEGER DEFAULT 0, reservation_token TEXT, reserved_until TEXT, file_name TEXT,
        row_count INTEGER, error_message TEXT, expires_at TEXT, completed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
$db->beginTransaction();
$insert = $db->prepare('INSERT INTO audit_logs (action, actor_role, actor_user_id, entity_type, entity_id, delivery_id) VALUES (?, ?, ?, ?, ?, ?)');
for ($i=1; $i<=1101; $i++) $insert->execute([$i === 1 ? '=1+1' : 'delivery.created', 'admin', 99, 'delivery', $i, $i]);
$db->commit();
try {
    $export = ReportExport::request($db, 99, 'audit', []);
    $insert->execute(['after.request', 'admin', 99, 'delivery', 1102, 1102]);
    $beat = 0;
    check(ReportExport::processNext($db, static function () use (&$beat) { $beat++; }), 'Export was not processed.');
    $row = ReportExport::owned($db, $export['export_id'], 99);
    check($row['status'] === 'ready' && (int)$row['row_count'] === 1101, 'Paged export lost rows or exceeded its upper ID.');
    check($beat === 3, 'Export did not use bounded pages/heartbeat.');
    $fp = fopen(ReportExport::path($row), 'r'); $header = fgetcsv($fp); $first = fgetcsv($fp); fclose($fp);
    check($first[2] === "'=1+1", 'Export allowed spreadsheet formula injection.');
    check(!ReportExport::processNext($db), 'Completed report was generated twice.');
    $filtered = ReportExport::request($db, 99, 'audit', ['action' => 'created']);
    check(ReportExport::processNext($db), 'Filtered export was not processed.');
    check((int)ReportExport::owned($db, $filtered['export_id'], 99)['row_count'] === 1100, 'Export did not match the audit listing substring filter.');
    try { ReportExport::owned($db, $export['export_id'], 1); throw new LogicException('Cross-owner export access allowed.'); }
    catch (TransactionBusinessException $e) { check($e->getStatusCode() === 404, 'Cross-owner download must not reveal the job.'); }
    $db->exec("UPDATE report_exports SET expires_at = '2000-01-01'");
    try { ReportExport::owned($db, $export['export_id'], 99); throw new LogicException('Expired report accepted.'); }
    catch (TransactionBusinessException $e) { check($e->getStatusCode() === 410, 'Expired report status.'); }
    $bad = ReportExport::request($db, 100, 'audit', []); // Suspended admin.
    try { ReportExport::processNext($db); throw new LogicException('Suspended owner generated report.'); }
    catch (RuntimeException $e) { check(ReportExport::owned($db, $bad['export_id'], 100)['status'] === 'failed', 'Failed report not observable.'); }
    // Database timestamps remain UTC even when the PHP host uses a local zone.
    $zone = date_default_timezone_get();
    date_default_timezone_set('America/Los_Angeles');
    $_COOKIE['TIL_REFRESH'] = $next['refresh_token'];
    $nextHash = hash('sha256', $next['refresh_token']);
    $db->prepare('UPDATE auth_refresh_sessions SET expires_at = ? WHERE token_hash = ?')->execute([gmdate('Y-m-d H:i:s', time()-60), $nextHash]);
    try { RefreshSession::rotate($db); throw new LogicException('Expired UTC token authenticated on a non-UTC host.'); }
    catch (RuntimeException $e) {
        $reason = $db->prepare('SELECT revoked_reason FROM auth_refresh_sessions WHERE token_hash = ?'); $reason->execute([$nextHash]);
        check($reason->fetchColumn() === 'expired', 'Refresh expiration depended on the PHP host timezone.');
    } finally { date_default_timezone_set($zone); }
    // A replay outside the bounded window still triggers compromise defense.
    $_COOKIE['TIL_REFRESH'] = $initial;
    $db->prepare('UPDATE auth_refresh_sessions SET revoked_at = ? WHERE token_hash = ?')->execute([gmdate('Y-m-d H:i:s', time()-10), hash('sha256',$initial)]);
    try { RefreshSession::rotate($db); throw new LogicException('Old stolen token accepted.'); }
    catch (RuntimeException $e) { check((int)$db->query('SELECT token_version FROM users WHERE id=1')->fetchColumn() === 1, 'Replay outside grace did not revoke credentials.'); }
} finally {
    foreach (glob(STORAGE_PATH . '/exports/*') as $path) unlink($path);
    if (is_dir(STORAGE_PATH . '/exports')) rmdir(STORAGE_PATH . '/exports');
    if (is_dir(STORAGE_PATH)) rmdir(STORAGE_PATH);
}
echo "PASS: {$checks} actual refresh-rotation/export authorization and recovery checks (SQLite dialect adapter).\n";
