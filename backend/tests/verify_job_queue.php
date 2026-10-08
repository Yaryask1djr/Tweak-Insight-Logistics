<?php

/**
 * Real Redis queue integration tests, with no application bootstrap or production DB.
 * Start disposable Redis on loopback; QUEUE_TEST_REDIS_PORT=16379 php tests/verify_job_queue.php
 * Only randomly named test queues are mutated. No FLUSHDB/FLUSHALL commands are used.
 */
require_once __DIR__ . '/../helpers/job_queue.php';
require_once __DIR__ . '/support/RedisQueueTestClient.php';

$port = (int)(getenv('QUEUE_TEST_REDIS_PORT') ?: 0);
if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "Set QUEUE_TEST_REDIS_PORT to an isolated Redis instance on 127.0.0.1.\n");
    exit(2);
}
$redis = new RedisQueueTestClient($port);
$queue = 'test_' . bin2hex(random_bytes(12));
$other = $queue . '_other';
$keys = static fn(string $q): array => ['queue:' . $q, 'queue:delayed:' . $q, 'queue:processing:' . $q, 'queue:failed:' . $q];
[$ready, $delayed, $processing, $failed] = $keys($queue);
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function throws(callable $fn, string $message): void
{
    try { $fn(); } catch (Throwable $e) { check(true, $message); return; }
    throw new RuntimeException($message);
}
// Any accidental MySQL fallback must fail this test, even when enqueue catches the error.
final class ForbiddenQueueDb extends PDO
{
    public int $calls = 0;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->calls++;
        throw new RuntimeException('Redis operation attempted to use MySQL.');
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->calls++;
        throw new RuntimeException('Redis operation attempted to use MySQL.');
    }
}
$db = new ForbiddenQueueDb();
JobQueue::setDb($db);
$factory = static fn() => new RedisQueueTestClient($port);
putenv('QUEUE_DRIVER=redis');
putenv('QUEUE_VISIBILITY_TIMEOUT=300');
JobQueue::setRedisConnectionFactoryForTesting($factory);

try {
    check(JobQueue::pop($queue) === null, 'Empty Redis queue must return null without querying MySQL.');
    check(JobQueue::push('test.job', ['nested' => ['value' => 'Kano']], $queue), 'Immediate enqueue failed.');
    $first = JobQueue::pop($queue);
    check($first !== null && $first['attempts'] === 1, 'Initial reservation must increment attempts.');
    check(strlen($first['id']) === 24 && strlen($first['reservation_token']) === 48, 'IDs must retain their full string values.');
    check($first['payload']['nested']['value'] === 'Kano', 'Payload must round-trip.');
    check($redis->command('LLEN', $ready) === 0 && $redis->command('ZCARD', $processing) === 1, 'Reserved job must stay recoverable.');
    check(JobQueue::pop($queue) === null, 'A live reservation must not be delivered to another worker.');
    check(JobQueue::renew($first), 'Current worker must be able to renew its lease.');

    // Simulate process death by expiring its reservation without acknowledging it.
    $redis->command('ZADD', $processing, '0', $first['_queue_redis_raw']);
    check(!JobQueue::complete($first), 'Expired worker must not acknowledge a job, even before reclamation.');
    check(!JobQueue::renew($first), 'Expired worker must not revive its reservation.');
    $second = JobQueue::pop($queue);
    check($second['id'] === $first['id'] && $second['attempts'] === 2, 'Recovery must preserve ID and retry count.');
    check($second['reservation_token'] !== $first['reservation_token'], 'Recovery must assign a fresh reservation token.');
    check(!JobQueue::complete($first) && !JobQueue::fail($first, 'late failure'), 'Stale worker must not alter the new reservation.');
    check(JobQueue::complete($second), 'Current worker must be able to acknowledge.');
    check(!JobQueue::complete($second) && $redis->command('ZCARD', $processing) === 0, 'Duplicate completion must be harmless.');

    check(JobQueue::push('test.delay', [], $queue, 3600), 'Delayed enqueue failed.');
    check(JobQueue::pop($queue) === null, 'Delayed job must not execute early.');
    $raw = $redis->command('ZRANGE', $delayed, '0', '-1')[0];
    $redis->command('ZADD', $delayed, '0', $raw);
    $delayedJob = JobQueue::pop($queue);
    check($delayedJob['job_type'] === 'test.delay' && $redis->command('ZCARD', $delayed) === 0, 'Due job must move into a reservation.');
    check(JobQueue::complete($delayedJob), 'Delayed job completion failed.');

    $precise = ['large_id' => PHP_INT_MAX, 'coordinate' => 12.123456789012345, 'empty_list' => []];
    check(JobQueue::push('test.precision', $precise, $queue), 'Precision fixture failed.');
    $exact = JobQueue::pop($queue);
    check($exact['payload'] === $precise, 'Lua must preserve payload numbers and empty arrays exactly.');
    check(JobQueue::complete($exact), 'Precision fixture completion failed.');
    $redis->command('RPUSH', $ready, json_encode([
        'id' => 'legacy_hex_id', 'queue' => $queue, 'job_type' => 'test.legacy',
        'payload' => $precise, 'attempts' => 0, 'max_attempts' => 3,
    ], JSON_THROW_ON_ERROR));
    $legacy = JobQueue::pop($queue);
    check($legacy['payload'] === $precise, 'Legacy payload precision must survive reservation.');
    check(JobQueue::fail($legacy, 'retry precision'), 'Legacy precision retry failed.');
    $raw = $redis->command('ZRANGE', $delayed, '0', '-1')[0];
    $redis->command('ZADD', $delayed, '0', $raw);
    $legacy = JobQueue::pop($queue);
    check($legacy['payload'] === $precise && $legacy['attempts'] === 2, 'Legacy payload precision must survive retries.');
    check(JobQueue::complete($legacy), 'Legacy precision completion failed.');

    check(JobQueue::push('test.failure', [], $queue), 'Retry fixture enqueue failed.');
    $job = JobQueue::pop($queue);
    $stableId = $job['id'];
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        check($job['attempts'] === $attempt && $job['id'] === $stableId, 'Retry count or stable job ID was lost.');
        check(JobQueue::fail($job, 'Provider unavailable'), 'Failure must be persisted.');
        check(!JobQueue::fail($job, 'duplicate'), 'Duplicate failure must not schedule a second retry.');
        if ($attempt < 3) {
            $entries = $redis->command('ZRANGE', $delayed, '0', '-1', 'WITHSCORES');
            $serverTime = (int)$redis->command('TIME')[0];
            $delay = (int)$entries[1] - $serverTime;
            $expected = $attempt === 1 ? 30 : 120;
            check($delay >= $expected - 2 && $delay <= $expected, 'Retry backoff is incorrect.');
            check(JobQueue::pop($queue) === null, 'Retry must respect backoff.');
            $redis->command('ZADD', $delayed, '0', $entries[0]);
            $job = JobQueue::pop($queue);
        }
    }
    check($redis->command('ZCARD', $failed) === 1 && JobQueue::pop($queue) === null, 'Exhausted job must stay in failed storage.');

    check(JobQueue::push('test.crash', [], $queue), 'Crash fixture enqueue failed.');
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $crash = JobQueue::pop($queue);
        check($crash['attempts'] === $attempt, 'Crashes must count toward the retry limit.');
        $redis->command('ZADD', $processing, '0', $crash['_queue_redis_raw']);
    }
    check(JobQueue::pop($queue) === null && $redis->command('ZCARD', $failed) === 2, 'Repeated crashes must eventually fail the job.');

    $redis->command('RPUSH', $ready, '{malformed-json');
    check(JobQueue::push('test.valid', [], $queue), 'Poison fixture enqueue failed.');
    $valid = JobQueue::pop($queue);
    check($valid['job_type'] === 'test.valid' && $redis->command('ZCARD', $failed) === 3, 'Poison payload must be retained and must not block valid work.');
    check(JobQueue::complete($valid), 'Valid job after poison payload failed.');
    $tampered = $valid;
    $tampered['reservation_token'] = str_repeat('0', 48);
    throws(static fn() => JobQueue::complete($tampered), 'Mismatched reservation context must be rejected.');

    check(JobQueue::push('test.isolation', [], $other), 'Second queue fixture failed.');
    check(JobQueue::pop($queue) === null && JobQueue::pop($other) !== null, 'Queues must remain isolated.');
    check(!JobQueue::push('test.invalid', [], 'delayed:default'), 'Reserved namespaces must not be used as queue names.');
    check(!JobQueue::push('test.invalid', [], $queue, -1), 'Negative delay must be rejected.');
    $redis->command('SET', $ready, 'wrong-type');
    throws(static fn() => JobQueue::pop($queue), 'Unexpected key types must surface an error.');
    check($redis->command('GET', $ready) === 'wrong-type', 'Type failure must not mutate data.');
    $redis->command('DEL', $ready);

    JobQueue::setRedisConnectionFactoryForTesting(static function () { throw new RuntimeException('Simulated outage'); });
    check(!JobQueue::push('test.outage', [], $queue), 'Enqueue outage must be reported.');
    throws(static fn() => JobQueue::pop($queue), 'Reserve outage must not be treated as an empty queue.');
    throws(static fn() => JobQueue::complete($valid), 'Acknowledgement outage must surface an error.');
    throws(static fn() => JobQueue::fail($valid, 'error'), 'Retry outage must surface an error.');
    check($db->calls === 0, 'Redis IDs must never be sent to MySQL, including during an outage.');
    echo "PASS: {$checks} real Redis queue checks.\n";
} finally {
    JobQueue::setRedisConnectionFactoryForTesting(null);
    $redis->command('DEL', ...$keys($queue), ...$keys($other));
    $redis->close();
}
