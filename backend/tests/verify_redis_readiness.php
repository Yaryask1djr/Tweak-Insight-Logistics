<?php
require_once __DIR__ . '/support/RedisQueueTestClient.php';
require_once __DIR__ . '/../helpers/job_queue.php';
$port = (int)getenv('QUEUE_TEST_REDIS_PORT');
if ($port !== 16379) throw new RuntimeException('Use the isolated test Redis port 16379.');
JobQueue::setRedisConnectionFactoryForTesting(static fn() => new RedisQueueTestClient($port));
putenv('QUEUE_DRIVER=redis');
$redis = new RedisQueueTestClient($port);
$queue = 'readiness_' . bin2hex(random_bytes(8));
try {
    $redis->command('RPUSH', 'queue:' . $queue, 'one');
    $redis->command('ZADD', 'queue:delayed:' . $queue, '1', 'two');
    $redis->command('ZADD', 'queue:processing:' . $queue, '1', 'three');
    $redis->command('RPUSH', 'queue:failed:' . $queue, 'four');
    $result = JobQueue::redisReadiness($queue);
    if ($result['counts'] !== ['pending'=>1,'delayed'=>1,'processing'=>1,'failed'=>1]) throw new RuntimeException('Readiness queried wrong queue keys.');
    JobQueue::setRedisConnectionFactoryForTesting(static function () { throw new RuntimeException('Redis disconnected'); });
    $failed = false;
    try { JobQueue::redisReadiness($queue); } catch (RuntimeException $e) { $failed = true; }
    if (!$failed) throw new RuntimeException('Dependency outage was hidden by fallback.');
    echo "PASS: direct Redis round trip, all four queue counts and fail-closed outage probe.\n";
} finally {
    foreach (['queue:','queue:delayed:','queue:processing:','queue:failed:'] as $prefix) $redis->command('DEL', $prefix . $queue);
    $redis->close(); JobQueue::setRedisConnectionFactoryForTesting(null);
}
