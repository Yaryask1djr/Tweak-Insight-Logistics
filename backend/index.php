<?php
/**
 * Tweak Insight Logistics — Root Entry Point
 *
 * This legacy entry point remains as a safe fallback for Apache hosts that
 * have not yet moved their DocumentRoot to backend/public. It maps only
 * whitelisted, compiled React assets from public/ and never exposes any other
 * backend file.
 */
require_once __DIR__ . '/helpers/security_headers.php';
SecurityHeaders::send();

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Route all API endpoints to api/index.php
$apiPrefixes = [
    '/api',
    '/auth/',
    '/admin/',
    '/client/',
    '/deliveries/',
    '/delivery/',
    '/delivery-person/',
    '/notifications',
    '/health',
];

$isApi = false;
foreach ($apiPrefixes as $prefix) {
    if (str_starts_with($uri, $prefix)) {
        $isApi = true;
        break;
    }
}

if ($isApi) {
    require_once __DIR__ . '/bootstrap.php';
    header('Cache-Control: private, no-store, max-age=0');
    require_once __DIR__ . '/api/index.php';
    exit;
}

/** Send cache headers only for assets that are safe to store publicly. */
function sendPublicAssetCacheHeaders(string $filePath): void
{
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $immutableExtensions = ['css', 'js', 'webp', 'avif', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf'];

    if (in_array($extension, $immutableExtensions, true)) {
        header('Cache-Control: public, max-age=31536000, s-maxage=31536000, immutable');
        header('Vary: Accept-Encoding');
    } elseif (in_array($extension, ['json', 'txt', 'webmanifest'], true)) {
        header('Cache-Control: public, max-age=86400, s-maxage=86400');
    }
}

/**
 * Serve one compiled asset from public/. The realpath and trailing-separator
 * check prevent URL traversal into the backend source tree.
 */
function servePublicAsset(string $publicRoot, string $uri, string $method): bool
{
    $requestedFile = realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim($uri, '/'));
    $publicPrefix = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!$requestedFile || !str_starts_with($requestedFile, $publicPrefix) || !is_file($requestedFile)) {
        return false;
    }

    $mimeTypes = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'webmanifest' => 'application/manifest+json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'txt' => 'text/plain; charset=UTF-8',
    ];
    $extension = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
    if (!isset($mimeTypes[$extension])) {
        return false;
    }

    header('Content-Type: ' . $mimeTypes[$extension]);
    header('Content-Length: ' . filesize($requestedFile));
    sendPublicAssetCacheHeaders($requestedFile);
    if ($method === 'GET') {
        readfile($requestedFile);
    }
    return true;
}

$publicRoot = realpath(__DIR__ . '/public');
$spaIndex = $publicRoot ? $publicRoot . DIRECTORY_SEPARATOR . 'index.html' : false;
if ($publicRoot && is_file($spaIndex)) {
    if (in_array($method, ['GET', 'HEAD'], true) && servePublicAsset($publicRoot, $uri, $method)) {
        exit;
    }

    // A missing static resource must remain a 404. Returning index.html for a
    // stale /static/js/*.js request makes browsers reject HTML as JavaScript.
    if (preg_match('#^/(?:static/|asset-manifest\.json$|manifest\.json$|robots\.txt$|favicon(?:-[0-9]+x[0-9]+)?\.(?:ico|png)$|logo(?:[0-9]+)?\.(?:png|webp)$)#i', $uri)) {
        header('Cache-Control: no-store, max-age=0');
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(404);
        echo json_encode(['status' => 'error', 'code' => 404, 'message' => 'Build asset not found.']);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    if ($method === 'GET') {
        readfile($spaIndex);
    }
    exit;
}

// Fallback if frontend build not found
header('Content-Type: application/json; charset=UTF-8');
http_response_code(503);
echo json_encode([
    'status' => 'error',
    'code' => 503,
    'message' => 'React frontend build not found in backend/public. Please run `npm run build:backend` in the frontend directory.'
], JSON_UNESCAPED_SLASHES);
exit;
