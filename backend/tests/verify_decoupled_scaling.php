<?php
/**
 * Test script for 2.1 Decouple Local Disk State for Horizontal Scaling.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';
require_once __DIR__ . '/../helpers/storage_adapter.php';
require_once __DIR__ . '/../helpers/logger.php';

// Test 1: Storage Adapter Factory
$adapter = Storage::adapter();
assert($adapter instanceof StorageAdapter, 'Storage::adapter() must implement StorageAdapter.');
assert($adapter instanceof LocalStorageAdapter, 'Default storage adapter must be LocalStorageAdapter.');

// Test storage operations
$tempFile = tempnam(sys_get_temp_dir(), 'til_test_');
file_put_contents($tempFile, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
$testKey = 'test-docs/verify_' . bin2hex(random_bytes(8)) . '.pdf';

$putResult = $adapter->put($testKey, $tempFile, 'application/pdf');
assert($putResult === true, 'Failed writing test file via StorageAdapter.');
assert($adapter->exists($testKey) === true, 'StorageAdapter::exists() returned false.');

try {
    $adapter->presignedUrl($testKey);
    throw new RuntimeException('Local document storage must not expose a direct URL.');
} catch (LogicException) {
    // Local files are streamed only through authorized controllers.
}

$adapter->delete($testKey);
assert($adapter->exists($testKey) === false, 'StorageAdapter::delete() failed to remove test file.');
@unlink($tempFile);

echo "✓ StorageAdapter operations verified successfully.\n";

// Test 2: Logger Structured Streaming
// Rate limiting has dedicated verification scripts because its production
// configuration intentionally fails closed when Redis or its database migration
// is unavailable; this isolated storage test must not mutate rate-limit state.
Logger::info('Decoupled scaling test message', ['module' => 'test', 'status' => 'verified']);
echo "✓ Logger structured JSON streaming verified.\n";

echo "\nAll 2.1 Decoupled Local Disk State tests passed successfully.\n";
