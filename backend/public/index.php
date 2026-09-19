<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/security_headers.php';
SecurityHeaders::send();

// This file is intentionally minimal. Apache serves index.html for SPA routes
// through .htaccess; the fallback prevents accidental PHP source disclosure if
// a host resolves the directory index through PHP first.
$index = __DIR__ . '/index.html';

if (!is_file($index)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Frontend build not found.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
readfile($index);
