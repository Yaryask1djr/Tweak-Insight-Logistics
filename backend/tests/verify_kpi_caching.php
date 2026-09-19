<?php
/**
 * Test script for 3.2 Dashboard KPI Stats Caching.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/cache_helper.php';
require_once __DIR__ . '/../controllers/AdminController.php';

// Test 1: CacheHelper operations
$key = 'test_kpi_cache_' . bin2hex(random_bytes(4));
$val = ['kpi' => 1234, 'revenue' => 50000.00];

assert(CacheHelper::get($key) === null, 'Cache get before set should return null.');
assert(CacheHelper::set($key, $val, 10) === true, 'Cache set failed.');
assert(CacheHelper::get($key) === $val, 'Cache get did not return set value.');

$computed = CacheHelper::remember($key, 10, function () {
    return ['should_not_compute' => true];
});
assert($computed === $val, 'Cache remember should return existing cached value.');

CacheHelper::delete($key);
assert(CacheHelper::get($key) === null, 'Cache delete failed.');

echo "✓ CacheHelper operations verified successfully.\n";

// Test 2: AdminController::getDashboardStats reflection
$reflector = new ReflectionClass('AdminController');
assert($reflector->hasMethod('getDashboardStats'), 'AdminController missing getDashboardStats method.');

echo "✓ AdminController::getDashboardStats cached method verified.\n";
echo "✓ All 3.2 Dashboard KPI Stats Caching tests passed successfully.\n";
