<?php

/**
 * MySQL integration test; no application bootstrap and no persistent table writes.
 * Set QUEUE_TEST_MYSQL_DSN, QUEUE_TEST_MYSQL_USER and QUEUE_TEST_MYSQL_PASSWORD.
 * The database name must end in _queue_test. A temporary job_queue shadows any table.
 */
require_once __DIR__ . '/../helpers/job_queue.php';
$dsn = (string)getenv('QUEUE_TEST_MYSQL_DSN');
if (!preg_match('/\Amysql:.*\bdbname=([A-Za-z0-9_]+_queue_test)(?:;|$)/', $dsn)) {
    fwrite(STDERR, "Set QUEUE_TEST_MYSQL_DSN to a disposable database ending in _queue_test.\n");
    exit(2);
}
$db = new PDO($dsn, (string)getenv('QUEUE_TEST_MYSQL_USER'), (string)getenv('QUEUE_TEST_MYSQL_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
if (!preg_match('/CREATE TABLE IF NOT EXISTS job_queue \([\s\S]*?\) ENGINE=InnoDB[^;]*;/', $schema, $match)) {
    throw new RuntimeException('Queue schema not found.');
}
$db->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $match[0]));
JobQueue::setDb($db);
putenv('QUEUE_DRIVER=mysql');
putenv('QUEUE_VISIBILITY_TIMEOUT=300');
$redisCalls = 0;
JobQueue::setRedisConnectionFactoryForTesting(static function () use (&$redisCalls) {
    $redisCalls++;
    throw new RuntimeException('MySQL operation attempted to use Redis.');
});
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
try {
    check(JobQueue::pop('test') === null, 'Empty MySQL queue must return null.');
    check(JobQueue::push('test.job', ['value' => 'Kano'], 'test'), 'MySQL enqueue failed.');
    $first = JobQueue::pop('test');
    check($first['attempts'] === 1 && $first['payload']['value'] === 'Kano', 'Reservation did not retain payload or attempts.');
    check($first['_queue_driver'] === 'mysql' && strlen($first['reservation_token']) === 48, 'Reservation context missing.');
    check(JobQueue::pop('test') === null, 'Live job was reserved twice.');
    check(JobQueue::renew($first), 'Current lease cannot be renewed.');
    $expire = $db->prepare("UPDATE job_queue SET reserved_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = ?");
    $expire->execute([$first['id']]);
    check(!JobQueue::complete($first) && !JobQueue::renew($first), 'Expired worker still owns its reservation.');
    $second = JobQueue::pop('test');
    check($second['id'] === $first['id'] && $second['attempts'] === 2, 'Crash recovery lost ID or attempts.');
    check($second['reservation_token'] !== $first['reservation_token'], 'Crash recovery reused a reservation token.');
    check(!JobQueue::complete($first) && !JobQueue::fail($first, 'stale'), 'Stale worker changed reclaimed work.');
    check(JobQueue::complete($second) && !JobQueue::complete($second), 'Completion must be fenced and idempotent.');

    check(JobQueue::push('test.failure', [], 'test'), 'Retry fixture failed.');
    $job = JobQueue::pop('test');
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        check($job['attempts'] === $attempt, 'Retry attempt count is incorrect.');
        check(JobQueue::fail($job, 'Provider unavailable'), 'Failure was not persisted.');
        check(!JobQueue::fail($job, 'duplicate'), 'Duplicate failure changed job state.');
        $row = $db->query('SELECT *, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), available_at) AS delay FROM job_queue WHERE id = ' . (int)$job['id'])->fetch();
        if ($attempt < 3) {
            $expected = $attempt === 1 ? 30 : 120;
            check($row['status'] === 'pending' && (int)$row['delay'] >= $expected - 2 && (int)$row['delay'] <= $expected, 'Retry backoff is incorrect.');
            check(JobQueue::pop('test') === null, 'Retry executed early.');
            $db->exec("UPDATE job_queue SET available_at = UTC_TIMESTAMP() WHERE status = 'pending'");
            $job = JobQueue::pop('test');
        } else {
            check($row['status'] === 'failed' && $row['failed_at'] !== null, 'Exhausted job was not retained as failed.');
        }
    }
    check(JobQueue::push('test.delay', [], 'delay', 3600) && JobQueue::pop('delay') === null, 'Delayed work executed early.');
    check(JobQueue::push('test.legacy', [], 'test'), 'Legacy fixture failed.');
    $db->exec("UPDATE job_queue SET status = 'processing', attempts = 1, reserved_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 301 SECOND) WHERE job_type = 'test.legacy'");
    $legacy = JobQueue::pop('test');
    check($legacy['job_type'] === 'test.legacy' && $legacy['attempts'] === 2, 'Legacy reservation was not recovered.');
    check(JobQueue::complete($legacy), 'Recovered legacy job could not be acknowledged.');
    $db->exec("INSERT INTO job_queue (job_type, payload, queue_name) VALUES ('test.poison', 'null', 'test')");
    check(JobQueue::push('test.valid', [], 'test'), 'Valid fixture failed.');
    $valid = JobQueue::pop('test');
    check($valid['job_type'] === 'test.valid', 'Poison payload blocked valid work.');
    check($db->query("SELECT status FROM job_queue WHERE job_type = 'test.poison'")->fetchColumn() === 'failed', 'Poison job was not quarantined.');
    check(JobQueue::complete($valid), 'Valid job could not complete.');

    $db->beginTransaction();
    $rejected = false;
    try { JobQueue::pop('test'); } catch (LogicException $e) { $rejected = true; }
    check($rejected && $db->inTransaction(), 'Pop must not commit a caller-owned transaction.');
    $db->rollBack();
    check($redisCalls === 0, 'Explicit MySQL driver attempted Redis access.');
    echo "PASS: {$checks} MySQL queue checks.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->exec('DROP TEMPORARY TABLE job_queue');
    JobQueue::setRedisConnectionFactoryForTesting(null);
}
