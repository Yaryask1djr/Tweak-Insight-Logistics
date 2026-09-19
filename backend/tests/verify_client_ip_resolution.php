<?php

declare(strict_types=1);

/**
 * Verifies that audit logging and rate limiting resolve client IPs with the
 * same trusted-proxy policy and ignore forged forwarding headers on direct IPs.
 */
require_once __DIR__ . '/../helpers/client_ip.php';
require_once __DIR__ . '/../helpers/logger.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';

putenv('APP_ENV=development');
putenv('TRUSTED_PROXIES=10.0.0.0/8,2001:db8:feed::/48');

$untrusted = [
    'REMOTE_ADDR' => '198.51.100.25',
    'HTTP_X_FORWARDED_FOR' => '8.8.8.8, 10.0.0.9',
    'HTTP_X_REAL_IP' => '1.1.1.1',
    'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
];
assert(ClientIp::resolve($untrusted) === '198.51.100.25', 'Direct peers must ignore forged forwarding headers.');

$trustedXff = [
    'REMOTE_ADDR' => '10.0.0.9',
    'HTTP_X_FORWARDED_FOR' => '8.8.8.8, 10.0.0.9',
];
assert(ClientIp::resolve($trustedXff) === '8.8.8.8', 'Trusted proxy X-Forwarded-For should resolve to the client.');

$incompleteChain = [
    'REMOTE_ADDR' => '10.0.0.9',
    'HTTP_X_FORWARDED_FOR' => '8.8.8.8, 198.51.100.10',
];
assert(ClientIp::resolve($incompleteChain) === '10.0.0.9', 'Untrusted intermediary hops must invalidate the forwarded chain.');

$trustedCf = [
    'REMOTE_ADDR' => '10.0.0.9',
    'HTTP_CF_CONNECTING_IP' => '1.1.1.1',
    'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
];
assert(ClientIp::resolve($trustedCf) === '1.1.1.1', 'Trusted Cloudflare header should take precedence.');
assert(ClientIp::ipInCidr('2001:db8:feed::7', '2001:db8:feed::/48'), 'IPv6 proxy CIDR should match.');

putenv('TRUSTED_PROXY_HOPS=2');
assert(ClientIp::resolve($trustedXff) === '8.8.8.8', 'Configured proxy hop count should accept the complete chain.');
assert(ClientIp::resolve([
    'REMOTE_ADDR' => '10.0.0.9',
    'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
]) === '10.0.0.9', 'Incomplete configured proxy chains must be rejected.');
putenv('TRUSTED_PROXY_HOPS');

// Production must never silently trust a broad RFC-1918 default. Without an
// explicitly configured list, a private REMOTE_ADDR is treated as the client.
putenv('APP_ENV=production');
putenv('TRUSTED_PROXIES');
$productionWithoutConfiguration = [
    'REMOTE_ADDR' => '10.0.0.9',
    'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
];
assert(ClientIp::resolve($productionWithoutConfiguration) === '10.0.0.9', 'Production must not trust proxy headers without TRUSTED_PROXIES.');
putenv('TRUSTED_PROXIES=10.0.0.0/8');
assert(ClientIp::resolve($productionWithoutConfiguration) === '8.8.8.8', 'An explicit production proxy list should enable forwarding headers.');

$originalServer = $_SERVER;
$_SERVER = $untrusted;
$loggerMethod = (new ReflectionClass(Logger::class))->getMethod('getClientIp');
$limiterMethod = (new ReflectionClass(RateLimiter::class))->getMethod('getClientIp');
assert($loggerMethod->invoke(null) === '198.51.100.25', 'Logger must reject forged X-Forwarded-For values.');
assert($limiterMethod->invoke(null) === '198.51.100.25', 'Rate limiter must match logger policy.');
$_SERVER = $originalServer;

echo "Client IP resolution checks passed.\n";
