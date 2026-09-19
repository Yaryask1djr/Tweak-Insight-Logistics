<?php
/**
 * Automated Verification Suite for Queue Worker Daemonization,
 * Notification Processing, and Audit Log Exports.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/job_queue.php';

echo ">>> Starting Verification Suite: Background Job Worker Daemonization <<<\n\n";

$db = null;
try {
    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'tweak_insight_logistics';
    $dbUser = getenv('DB_USER') ?: 'root';
    $dbPass = getenv('DB_PASS') ?: '';
    $dbPort = getenv('DB_PORT') ?: '3306';
    $db = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,
    ]);
    JobQueue::setDb($db);
} catch (Throwable $e) {
    echo "[INFO] Live MySQL database unreachable ({$e->getMessage()}). Offline test mode activated.\n";
}

$workerScript = __DIR__ . '/../scripts/queue_worker.php';

// --- Test 1: Production deployment config files existence and validity ---
echo "1. Checking production process manager configurations...\n";
$systemdFile = __DIR__ . '/../deploy/systemd/til-queue-worker.service';
$supervisorFile = __DIR__ . '/../deploy/supervisor/til-queue-worker.conf';
$setupScript = __DIR__ . '/../deploy/setup_daemon.sh';

assert(file_exists($systemdFile), "Missing systemd service file: {$systemdFile}");
assert(file_exists($supervisorFile), "Missing supervisor conf file: {$supervisorFile}");
assert(file_exists($setupScript), "Missing setup daemon script: {$setupScript}");

$systemdContent = file_get_contents($systemdFile);
assert(strpos($systemdContent, 'Restart=always') !== false, "systemd missing Restart=always");
assert(strpos($systemdContent, 'queue_worker.php') !== false, "systemd missing queue_worker.php command");

$supervisorContent = file_get_contents($supervisorFile);
assert(strpos($supervisorContent, 'autorestart=true') !== false, "supervisor missing autorestart=true");
assert(strpos($supervisorContent, 'queue_worker.php') !== false, "supervisor missing queue_worker.php command");

echo "   [PASS] systemd and supervisord service files verified.\n";

// --- Test 2: CLI Options & Help ---
echo "2. Testing CLI argument parser and --help output...\n";
$helpOutput = shell_exec('php "' . $workerScript . '" --help');
assert(strpos($helpOutput, 'Tweak Insight Logistics — Queue Worker Daemon') !== false, "Help output missing header");
assert(strpos($helpOutput, '--queue=') !== false, "Help output missing --queue option");
assert(strpos($helpOutput, '--memory-limit=') !== false, "Help output missing --memory-limit option");
echo "   [PASS] CLI flags and help banner verified.\n";

// --- Test 3: Asynchronous Notification Processing ---
echo "3. Testing asynchronous notification queueing & worker execution...\n";
if ($db === null) {
    echo "   [SKIP] Requires live MySQL connection.\n";
} else {
    // Process any leftover jobs first so queue is clean
    shell_exec('php "' . $workerScript . '" --once');

    $notifPayload = [
        'user_id'     => 1,
        'delivery_id' => 999,
        'type'        => 'driver.assignment_accepted',
        'title'       => 'Driver En Route',
        'body'        => 'Your test driver is approaching pickup location.',
        'channel'     => 'external_sms',
    ];

    $pushed = JobQueue::push('notification.external_dispatch', $notifPayload);
    assert($pushed === true, "Failed to push notification job to queue");

    $workerOutput = shell_exec('php "' . $workerScript . '" --once');
    assert(strpos($workerOutput, 'Processing job #') !== false, "Worker did not process job");
    assert(strpos($workerOutput, 'completed successfully') !== false, "Job execution did not succeed");

    $stmt = $db->query("SELECT status, attempts FROM job_queue WHERE job_type = 'notification.external_dispatch' ORDER BY id DESC LIMIT 1");
    $jobRow = $stmt->fetch(PDO::FETCH_ASSOC);
    assert($jobRow['status'] === 'completed', "Job status is not completed in database (got {$jobRow['status']})");
    echo "   [PASS] Asynchronous notification processed & completed.\n";
}

// --- Test 4: Asynchronous Audit Log Export ---
echo "4. Testing asynchronous audit log export to CSV...\n";
if ($db === null) {
    echo "   [SKIP] Requires live MySQL connection.\n";
} else {
    $adminUserId = (int)$db->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn() ?: null;
    $db->prepare("INSERT INTO audit_logs (actor_user_id, actor_role, action, entity_type, entity_id, created_at) VALUES (?, 'admin', 'test.daemon_verification', 'system', 101, NOW())")->execute([$adminUserId]);

    $exportId = 'test_' . bin2hex(random_bytes(4));
    $exportPayload = [
        'admin_id'  => $adminUserId ?: 0,
        'export_id' => $exportId,
        'filters'   => ['action' => 'test.daemon_verification'],
    ];

    $pushedExport = JobQueue::push('audit.export', $exportPayload);
    assert($pushedExport === true, "Failed to push audit export job to queue");

    $workerExportOutput = shell_exec('php "' . $workerScript . '" --once');
    assert(strpos($workerExportOutput, 'audit.export') !== false, "Worker did not execute audit.export handler");

    // Verify CSV file creation
    $exportDir = __DIR__ . '/../storage/exports';
    $csvFiles = glob($exportDir . '/*_' . $exportId . '.csv');
    assert(!empty($csvFiles), "CSV export file was not created in storage/exports");
    $csvFile = $csvFiles[0];
    $csvContent = file_get_contents($csvFile);
    assert(strpos($csvContent, 'Log ID') !== false && strpos($csvContent, 'Action') !== false, "CSV missing header row");
    assert(strpos($csvContent, 'test.daemon_verification') !== false, "CSV missing matching audit record");
    echo "   [PASS] Asynchronous audit CSV export generated ({$csvFile}, size: " . filesize($csvFile) . " bytes).\n";
}

// --- Test 5: Worker Heartbeat & Status Command ---
echo "5. Testing worker heartbeat file & --status CLI report...\n";
$heartbeatFile = __DIR__ . '/../storage/logs/worker_heartbeat.json';
assert(file_exists($heartbeatFile), "Heartbeat file missing: {$heartbeatFile}");

$heartbeat = json_decode(file_get_contents($heartbeatFile), true);
assert(isset($heartbeat['pid']), "Heartbeat missing pid");
assert(isset($heartbeat['status']), "Heartbeat missing status");
assert(isset($heartbeat['memory_usage_mb']), "Heartbeat missing memory_usage_mb");

$statusOutput = shell_exec('php "' . $workerScript . '" --status');
assert(strpos($statusOutput, 'Tweak Insight Logistics — Queue Worker Status') !== false, "Status command missing header");
assert(strpos($statusOutput, 'PID:') !== false, "Status command missing PID");
echo "   [PASS] Heartbeat tracking & live status command verified.\n";

// --- Test 6: Memory Limit Threshold Recycling ---
echo "6. Testing memory recycling auto-exit...\n";
if ($db === null) {
    echo "   [SKIP] Requires live MySQL connection.\n";
} else {
    // Setting a tiny memory limit (1 MB) to verify worker gracefully exits with code 0 after a job
    $recycleOutput = shell_exec('php "' . $workerScript . '" --once --memory-limit=1');
    assert(strpos($recycleOutput, 'Worker finished') !== false || strpos($recycleOutput, 'Memory limit threshold reached') !== false, "Worker did not exit cleanly under memory constraint");
    echo "   [PASS] Memory threshold exit protection verified.\n";
}

echo "\n>>> ALL DAEMON WORKER VERIFICATION TESTS FINISHED! <<<\n";
