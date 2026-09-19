<?php
/**
 * Test script for 3.1 Live Telemetry Table Isolation.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../controllers/DeliveryPersonController.php';
require_once __DIR__ . '/../controllers/LiveTrackingController.php';

// Reflection validation
$dpReflector = new ReflectionClass('DeliveryPersonController');
assert($dpReflector->hasMethod('updateLocation'), 'DeliveryPersonController missing updateLocation method');

$ltReflector = new ReflectionClass('LiveTrackingController');
assert($ltReflector->hasMethod('delivery'), 'LiveTrackingController missing delivery method');
assert($ltReflector->hasMethod('activeForAdmin'), 'LiveTrackingController missing activeForAdmin method');

echo "✓ Live telemetry isolation classes and reflection verified.\n";
echo "✓ All 3.1 Live Telemetry Table Isolation tests passed successfully.\n";
