<?php
/**
 * Verify that RateLimiter::getClientIp() only trusts proxy headers from known trusted ranges.
 * Uses Reflection to access the private static methods.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';

$reflector = new ReflectionClass('RateLimiter');

$ipInCidr = $reflector->getMethod('ipInCidr');
$isFromTrustedProxy = $reflector->getMethod('isFromTrustedProxy');

// ----------------------------------------------------------------
// Test 1: CIDR matching — ipInCidr()
// ----------------------------------------------------------------

// Loopback
assert($ipInCidr->invoke(null, '127.0.0.1', '127.0.0.1/8') === true,  '127.0.0.1 should match 127.0.0.1/8');
assert($ipInCidr->invoke(null, '127.0.0.99', '127.0.0.1/8') === true, '127.0.0.99 should match 127.0.0.1/8');

// RFC-1918 private ranges
assert($ipInCidr->invoke(null, '192.168.1.55', '192.168.0.0/16') === true,  '192.168.1.55 should match 192.168.0.0/16');
assert($ipInCidr->invoke(null, '10.0.50.1', '10.0.0.0/8') === true,         '10.x.x.x should match 10.0.0.0/8');
assert($ipInCidr->invoke(null, '172.20.5.1', '172.16.0.0/12') === true,     '172.20.x.x should match 172.16.0.0/12');

// Cloudflare published range
assert($ipInCidr->invoke(null, '103.21.244.10', '103.21.244.0/22') === true, 'Cloudflare IP should match 103.21.244.0/22');

// Non-matching public IPs
assert($ipInCidr->invoke(null, '8.8.8.8', '127.0.0.1/8') === false,         '8.8.8.8 should NOT match loopback range');
assert($ipInCidr->invoke(null, '1.2.3.4', '10.0.0.0/8') === false,          '1.2.3.4 should NOT match private range');
assert($ipInCidr->invoke(null, '45.33.32.156', '103.21.244.0/22') === false, 'Random public IP should NOT match Cloudflare range');

// Exact IP match (no CIDR slash)
assert($ipInCidr->invoke(null, '203.0.113.1', '203.0.113.1') === true,  'Exact IP match should pass');
assert($ipInCidr->invoke(null, '203.0.113.2', '203.0.113.1') === false, 'Different exact IP should not match');

echo "✓ ipInCidr CIDR matching tests passed.\n";

// ----------------------------------------------------------------
// Test 2: isFromTrustedProxy() — uses TRUSTED_PROXIES env or defaults
// ----------------------------------------------------------------

// Explicit development proxy list for this test.
putenv('APP_ENV=development');
putenv('TRUSTED_PROXIES=127.0.0.1/8,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');

assert($isFromTrustedProxy->invoke(null, '127.0.0.1') === true,    'Loopback should be trusted proxy');
assert($isFromTrustedProxy->invoke(null, '10.10.5.2') === true,    'RFC-1918 10.x.x.x should be trusted');
assert($isFromTrustedProxy->invoke(null, '192.168.0.1') === true,  'RFC-1918 192.168.x.x should be trusted');
assert($isFromTrustedProxy->invoke(null, '1.2.3.4') === false,     'Random public IP should NOT be trusted');
assert($isFromTrustedProxy->invoke(null, '45.33.32.156') === false, 'Linode public IP should NOT be trusted');
assert($isFromTrustedProxy->invoke(null, '0.0.0.0') === false,     '0.0.0.0 should NOT be trusted');

// Production must fail safe: no implicit RFC-1918 trust when configuration is absent.
putenv('APP_ENV=production');
putenv('TRUSTED_PROXIES');
assert($isFromTrustedProxy->invoke(null, '10.10.5.2') === false, 'Production must require an explicit TRUSTED_PROXIES list');
putenv('TRUSTED_PROXIES=10.0.0.0/8');
assert($isFromTrustedProxy->invoke(null, '10.10.5.2') === true, 'Explicit production proxy list should be honoured');

echo "✓ isFromTrustedProxy subnet validation tests passed.\n";
echo "\nAll rate limiter proxy trust verification tests passed.\n";
