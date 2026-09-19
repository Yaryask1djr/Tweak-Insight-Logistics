<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/database_transaction.php';

echo "Testing DatabaseTransaction::isTransientLockFailure...\n";

// 1. Test isTransientLockFailure
$deadlockEx = new PDOException('Deadlock found when trying to get lock; try restarting transaction', 40001);
$deadlockEx->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
if (!DatabaseTransaction::isTransientLockFailure($deadlockEx)) {
    die("FAILED: Deadlock detection\n");
}
echo "[PASS] Deadlock 1213 detection\n";

$timeoutEx = new PDOException('Lock wait timeout exceeded; try restarting transaction', 0);
$timeoutEx->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];
if (!DatabaseTransaction::isTransientLockFailure($timeoutEx)) {
    die("FAILED: Lock wait timeout detection\n");
}
echo "[PASS] Lock wait timeout 1205 detection\n";

$generalEx = new PDOException('Table not found', 42000);
$generalEx->errorInfo = ['42000', 1146, 'Table not found'];
if (DatabaseTransaction::isTransientLockFailure($generalEx)) {
    die("FAILED: General error non-retry\n");
}
echo "[PASS] General SQL error non-retry detection\n";

$db = null;
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306', getenv('DB_NAME') ?: 'til_logistics');
    $db = new PDO($dsn, getenv('DB_USER') ?: 'til_app', getenv('DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 1,
    ]);
} catch (Throwable $e) {
    echo "[INFO] Live MySQL unreachable; executing transaction retry suite using in-memory SQLite engine.\n";
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
$attempts = 0;

$result = DatabaseTransaction::run($db, function($db) use (&$attempts, $deadlockEx) {
    $attempts++;
    if ($attempts === 1) {
        throw $deadlockEx;
    }
    return 'SUCCESS_ON_ATTEMPT_' . $attempts;
}, 3);

if ($attempts !== 2 || $result !== 'SUCCESS_ON_ATTEMPT_2') {
    die("FAILED: Deadlock retry should have succeeded on attempt 2 (got attempts={$attempts}, result={$result})\n");
}
echo "[PASS] Deadlock auto-retry recovered on attempt 2\n";

// 3. Test Lock Wait Timeout Auto-Retry logic
$attempts = 0;
$result = DatabaseTransaction::run($db, function($db) use (&$attempts, $timeoutEx) {
    $attempts++;
    if ($attempts < 3) {
        throw $timeoutEx;
    }
    return 'SUCCESS_ON_ATTEMPT_3';
}, 3);

if ($attempts !== 3 || $result !== 'SUCCESS_ON_ATTEMPT_3') {
    die("FAILED: Lock wait timeout retry should have succeeded on attempt 3 (got attempts={$attempts}, result={$result})\n");
}
echo "[PASS] Lock wait timeout auto-retry recovered on attempt 3\n";

// 4. Test Max Attempts Exhaustion
$attempts = 0;
$exhaustedCaught = false;
try {
    DatabaseTransaction::run($db, function($db) use (&$attempts, $deadlockEx) {
        $attempts++;
        throw $deadlockEx;
    }, 3);
} catch (PDOException $e) {
    $exhaustedCaught = true;
}

if ($attempts !== 3 || !$exhaustedCaught) {
    die("FAILED: Exhausted attempts should have attempted 3 times and thrown (got attempts={$attempts})\n");
}
echo "[PASS] Exhausted retry attempts properly thrown\n";

// 5. Test real database transaction commit
$val = DatabaseTransaction::run($db, function($db) {
    $stmt = $db->query('SELECT 42 as answer');
    return $stmt->fetchColumn();
});
if ($val != 42) {
    die("FAILED: Live DB query inside transaction returned unexpected value\n");
}
echo "[PASS] Live DB query inside transaction committed successfully\n";

echo "\n>>> ALL DEADLOCK AUTO-RETRY TESTS PASSED SUCCESSFULLY! <<<\n";
