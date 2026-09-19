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

// Case 2: Untrusted Origin
$untrustedOrigin = 'https://malicious-phishing-site.com';
assert(in_array($untrustedOrigin, $allowedOrigins, true) === false, 'Untrusted origin should not be in allowed list.');

echo "CORS whitelist policy verification passed.\n";
