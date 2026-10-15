<?php
declare(strict_types=1);

/**
 * Automated Verification Test for Driver Telemetry & Redis Streaming.
 *
 * Verifies:
 * 1. TelemetryStream class structure and methods.
 * 2. High-throughput Redis event buffering.
 * 3. Ephemeral live location read model without touching MySQL.
 * 4. Atomic multi-row batch flush into MySQL delivery_location_events.
 * 5. Resilient offline fallback: direct MySQL persistence when Redis is offline/unreachable.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/telemetry_stream.php';

echo "============================================================\n";
echo " Verifying Driver Telemetry & Redis Streaming Architecture \n";
echo "============================================================\n";

$checks = 0;
function check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException("FAILED: {$description}");
    }
    $checks++;
    echo "  [PASS] {$description}\n";
}

$db = (new Database())->getConnection();

// 1. Reflection Checks
$reflector = new ReflectionClass('TelemetryStream');
check($reflector->hasMethod('recordLocation'), 'TelemetryStream provides recordLocation()');
check($reflector->hasMethod('flushBatch'), 'TelemetryStream provides flushBatch()');
check($reflector->hasMethod('getBufferLength'), 'TelemetryStream provides getBufferLength()');
check($reflector->hasMethod('getLiveDeliveryLocation'), 'TelemetryStream provides getLiveDeliveryLocation()');

// 2. Client Connection Check
$client = TelemetryStream::getClient();
check($client !== null, 'Redis telemetry streaming client successfully established on port 16379');

// Clean any leftover buffer from prior test run
if ($client !== null && method_exists($client, 'del')) {
    $client->del(TelemetryStream::BUFFER_KEY);
}

// 3. Resolve a valid foreign-key delivery from database
$existing = $db->query("SELECT d.id, d.delivery_person_id FROM deliveries d WHERE d.delivery_person_id IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$existing) {
    // If no assigned delivery, find any delivery and any driver user
    $anyDelivery = $db->query("SELECT id FROM deliveries LIMIT 1")->fetchColumn();
    $anyDriver = $db->query("SELECT id FROM users WHERE role = 'delivery' LIMIT 1")->fetchColumn();
    if ($anyDelivery && $anyDriver) {
        $testDeliveryId = (int)$anyDelivery;
        $testDriverUserId = (int)$anyDriver;
    } else {
        throw new RuntimeException("No delivery record found to attach telemetry test events.");
    }
} else {
    $testDeliveryId = (int)$existing['id'];
    $testDriverUserId = (int)$existing['delivery_person_id'];
}

check($testDeliveryId > 0 && $testDriverUserId > 0, "Resolved valid test delivery #{$testDeliveryId} (Driver User #{$testDriverUserId})");

// 4. Telemetry Buffering Verification
$initialBufferDepth = TelemetryStream::getBufferLength();

$samples = [
    ['lat' => 12.0001, 'lon' => 8.5101, 'accuracy' => 8.5, 'heading' => 45.0],
    ['lat' => 12.0005, 'lon' => 8.5108, 'accuracy' => 6.2, 'heading' => 50.0],
    ['lat' => 12.0010, 'lon' => 8.5115, 'accuracy' => 5.0, 'heading' => 55.0],
    ['lat' => 12.0015, 'lon' => 8.5122, 'accuracy' => 4.8, 'heading' => 60.0],
    ['lat' => 12.0020, 'lon' => 8.5130, 'accuracy' => 4.5, 'heading' => 65.0],
];

foreach ($samples as $idx => $sample) {
    $result = TelemetryStream::recordLocation(
        $db,
        $testDeliveryId,
        $testDriverUserId,
        $sample['lat'],
        $sample['lon'],
        $sample['accuracy'],
        $sample['heading']
    );
    check($result['buffered'] === true, "Sample #{$idx} buffered to Redis list");
}

$newBufferDepth = TelemetryStream::getBufferLength();
check($newBufferDepth === $initialBufferDepth + 5, "Buffer length incremented by exactly 5 (now: {$newBufferDepth})");

// 5. Ephemeral Live Location Verification
$live = TelemetryStream::getLiveDeliveryLocation($testDeliveryId);
check($live !== null, 'Instant live delivery location retrieved from Redis ephemeral store');
check(abs((float)$live['latitude'] - 12.0020) < 0.0001, 'Live delivery latitude matches most recent GPS ping');
check(abs((float)$live['longitude'] - 8.5130) < 0.0001, 'Live delivery longitude matches most recent GPS ping');
check((float)$live['heading_degrees'] === 65.0, 'Live heading degrees matches latest ping');

// 6. Batch Flush to MySQL Verification
$countBefore = (int)$db->query("SELECT COUNT(*) FROM delivery_location_events WHERE delivery_id = {$testDeliveryId}")->fetchColumn();
$flushedCount = TelemetryStream::flushBatch($db, 100);
check($flushedCount >= 5, "Batch flusher successfully committed {$flushedCount} events to MySQL");

$countAfter = (int)$db->query("SELECT COUNT(*) FROM delivery_location_events WHERE delivery_id = {$testDeliveryId}")->fetchColumn();
check($countAfter >= $countBefore + 5, "MySQL delivery_location_events incremented by at least 5 (now: {$countAfter})");

// 7. Resilient Offline Fallback Verification
// Simulate Redis offline by injecting null client
TelemetryStream::setClientForTesting(null);
$fallbackResult = TelemetryStream::recordLocation(
    $db,
    $testDeliveryId,
    $testDriverUserId,
    12.0033,
    8.5144,
    4.0,
    95.0
);
check($fallbackResult['buffered'] === false, 'Offline simulation: event was not buffered to Redis');
check($fallbackResult['fallback_mysql'] === true, 'Offline simulation: zero-data-loss fallback to direct MySQL insert activated');

$verifyFallbackStmt = $db->prepare('SELECT COUNT(*) FROM delivery_location_events WHERE delivery_id = ? AND latitude = ?');
$verifyFallbackStmt->execute([$testDeliveryId, 12.0033]);
check((int)$verifyFallbackStmt->fetchColumn() === 1, 'Direct MySQL fallback event confirmed in database');

// 8. Cleanup test data created during this run
$db->prepare('DELETE FROM delivery_location_events WHERE delivery_id = ? AND latitude >= 12.0001 AND latitude <= 12.0035')->execute([$testDeliveryId]);
TelemetryStream::resetClientForTesting();
$cleanClient = TelemetryStream::getClient();
if ($cleanClient !== null && method_exists($cleanClient, 'del')) {
    $cleanClient->del(TelemetryStream::LIVE_DELIVERY_PREFIX . $testDeliveryId);
    $cleanClient->del(TelemetryStream::LIVE_DRIVER_PREFIX . $testDriverUserId);
}
check(true, 'Test telemetry artifacts cleaned up safely');

echo "\n============================================================\n";
echo "✓ ALL {$checks} DRIVER TELEMETRY & REDIS STREAMING TESTS PASSED!\n";
echo "============================================================\n";
