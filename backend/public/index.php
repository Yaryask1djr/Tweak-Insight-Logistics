<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/security_headers.php';
SecurityHeaders::send();

// Apache sends SPA routes here so each navigation reads the active release
// pointer. Legacy index.html remains available only before the first activation.
require_once dirname(__DIR__) . '/helpers/frontend_release.php';
$index = FrontendRelease::index(__DIR__);

if (!is_file($index)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Frontend build not found.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
readfile($index);
