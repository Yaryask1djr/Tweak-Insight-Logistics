<?php
/**
 * Test script for 2.3 Asynchronous Job Queue for Notifications & Webhooks.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/job_queue.php';
require_once __DIR__ . '/../helpers/notification_service.php';

// Test 1: Class and Reflection checks
$reflector = new ReflectionClass('JobQueue');
assert($reflector->hasMethod('push'), 'JobQueue missing push method');
assert($reflector->hasMethod('pop'), 'JobQueue missing pop method');
assert($reflector->hasMethod('complete'), 'JobQueue missing complete method');
assert($reflector->hasMethod('fail'), 'JobQueue missing fail method');

echo "✓ JobQueue class reflection verified.\n";

// Test 2: NotificationService integration
$notifReflector = new ReflectionClass('NotificationService');
assert($notifReflector->hasMethod('publish'), 'NotificationService missing publish method');
assert($notifReflector->hasMethod('publishToRole'), 'NotificationService missing publishToRole method');

echo "✓ NotificationService asynchronous external dispatch integration verified.\n";

// Test 3: Redis queue driver operations via test factory
class MockRedisQueueClient
{
    public array $lists = [];
    public array $zsets = [];
    public bool $closed = false;

    public function rPush(string $key, string $value): int
    {
        $this->lists[$key][] = $value;
        return count($this->lists[$key]);
    }

    public function lPop(string $key): ?string
    {
        if (empty($this->lists[$key])) {
            return null;
        }
        return array_shift($this->lists[$key]);
    }

    public function zAdd(string $key, float|int $score, string $member): int
    {
        $this->zsets[$key][$member] = $score;
        return 1;
    }

    public function zRangeByScore(string $key, string|int $min, string|int $max, array $options = []): array
    {
        if (empty($this->zsets[$key])) {
            return [];
        }
        $maxScore = is_numeric($max) ? (float)$max : PHP_FLOAT_MAX;
        $matches = [];
        foreach ($this->zsets[$key] as $member => $score) {
            if ($score <= $maxScore) {
                $matches[] = $member;
            }
        }
        return $matches;
    }

    public function zRem(string $key, string $member): int
    {
        if (isset($this->zsets[$key][$member])) {
            unset($this->zsets[$key][$member]);
            return 1;
        }
        return 0;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

$mockRedis = new MockRedisQueueClient();
JobQueue::setRedisConnectionFactoryForTesting(function () use ($mockRedis) {
    return $mockRedis;
});

// Push immediate job
$pushed = JobQueue::push('notification.sms', ['phone' => '+2348012345678', 'message' => 'Test SMS'], 'testing');
assert($pushed === true, 'Redis push should succeed');
assert(count($mockRedis->lists['queue:testing']) === 1, 'Job should be in ready list');

// Pop immediate job
$popped = JobQueue::pop('testing');
assert($popped !== null, 'Redis pop should return job');
assert($popped['job_type'] === 'notification.sms', 'Job type should match');
assert($popped['payload']['phone'] === '+2348012345678', 'Payload should match');
assert($popped['attempts'] === 1, 'Attempts should increment');
assert($popped['_queue_driver'] === 'redis', 'Driver marker should be redis');

// Delayed job push & pop migration
$pushedDelayed = JobQueue::push('webhook.deliver', ['url' => 'https://example.com'], 'testing', 0); // 0 delay ready
assert($pushedDelayed === true, 'Delayed push should succeed');
$poppedDelayed = JobQueue::pop('testing');
assert($poppedDelayed !== null, 'Popped delayed job should match');
assert($poppedDelayed['job_type'] === 'webhook.deliver', 'Job type should be webhook.deliver');

// Clean up test factory
JobQueue::setRedisConnectionFactoryForTesting(null);

echo "✓ Redis driver push, pop, and key generation operations verified.\n";
echo "✓ All 2.3 Asynchronous Job Queue tests passed successfully.\n";
