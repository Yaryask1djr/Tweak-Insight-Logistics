<?php

require_once __DIR__ . '/../bootstrap.php';

// Test Whitelist Logic
$defaultOrigins = 'http://localhost:3000,http://127.0.0.1:3000,http://localhost:8000,http://127.0.0.1:8000,https://app.tweakinsight.com';
$allowedEnv = getenv('ALLOWED_ORIGINS') ?: $defaultOrigins;
$allowedOrigins = array_map('trim', explode(',', $allowedEnv));

// Case 1: Whitelisted Origin
$origin1 = 'http://localhost:3000';
assert(in_array($origin1, $allowedOrigins, true) === true, 'Whitelisted origin http://localhost:3000 was not allowed.');

$origin2 = 'https://app.tweakinsight.com';
assert(in_array($origin2, $allowedOrigins, true) === true, 'Whitelisted origin https://app.tweakinsight.com was not allowed.');

// Case 3: SecurityConfig helper method
$resolvedOrigins = SecurityConfig::getAllowedOrigins();
assert(is_array($resolvedOrigins) && count($resolvedOrigins) > 0, 'SecurityConfig::getAllowedOrigins() must return a non-empty array.');
assert(in_array('https://app.tweakinsight.com', $resolvedOrigins, true), 'Default allowed origins must include primary domain.');
assert(!in_array('https://malicious-phishing-site.com', $resolvedOrigins, true), 'Untrusted origins must be rejected by resolver.');

echo "CORS whitelist policy verification passed.\n";
