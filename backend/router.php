<?php
// Enhanced router for PHP built-in server
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
require_once __DIR__ . '/helpers/security_headers.php';
SecurityHeaders::send();

// The PHP development server is launched with backend/ as its document root.
// Keep private project directories unreachable here too; this mirrors the
// production Apache deny list and prevents an accidental local exposure.
function isPrivateBackendPath(string $path): bool
{
    return (bool)preg_match(
        '#^/(?:storage|config|controllers|database|docs|helpers|scripts|vendor|\.git|\.svn)(?:/|$)|^/(?:\.env(?:\..*)?|bootstrap\.php|router\.php|composer\.(?:json|lock)|phpunit\.xml(?:\.dist)?)$#i',
        $path
    );
}

if (isPrivateBackendPath($uri)) {
    header('Cache-Control: no-store, max-age=0');
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(404);
    echo json_encode(['status' => 'error', 'code' => 404, 'message' => 'Not found.']);
    exit;
}

// Route API endpoints to api/index.php
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
    // API responses may include live delivery, account, and tracking data.
    // They must never be stored by a browser or an intermediary CDN cache.
    header('Cache-Control: private, no-store, max-age=0');
    require_once __DIR__ . '/api/index.php';
    exit;
}

/** Send production cache headers when the PHP built-in server serves the React build. */
function sendStaticCacheHeaders(string $filePath): void
{
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $immutableExtensions = ['css', 'js', 'webp', 'avif', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf'];

    if (in_array($extension, $immutableExtensions, true)) {
        // React build assets are fingerprinted. Long browser/CDN caching avoids
        // re-downloading JavaScript, CSS, fonts, and compressed imagery.
        header('Cache-Control: public, max-age=31536000, s-maxage=31536000, immutable');
        header('Vary: Accept-Encoding');
        return;
    }

    if ($extension === 'json' || $extension === 'txt') {
        header('Cache-Control: public, max-age=86400, s-maxage=86400');
    }
}

// React is the single frontend in the standard deployment. Its build is copied
// to public/ by `npm run build:backend`; the API remains handled above.
$publicRoot = realpath(__DIR__ . '/public');
$spaIndex = $publicRoot . DIRECTORY_SEPARATOR . 'index.html';
if (is_file($spaIndex)) {
    $legacyRoutes = [
        '/index.php' => '/',
        '/login.php' => '/login',
        '/register.php' => '/register-client',
        '/track.php' => '/track',
        '/coverage.php' => '/coverage',
        '/request-delivery.php' => '/dashboard',
    ];

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($legacyRoutes[$uri])) {
        header('Location: ' . $legacyRoutes[$uri], true, 302);
        exit;
    }

    // Serve real build artefacts from public/. The local PHP command uses
    // backend/ as its document root, so `return false` would look in the
    // wrong directory instead of this public directory.
    $requestedFile = realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim($uri, '/'));
    if ($requestedFile && str_starts_with($requestedFile, $publicRoot) && is_file($requestedFile)) {
        $mimeTypes = [
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'map' => 'application/json; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'txt' => 'text/plain; charset=UTF-8',
        ];
        $extension = strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION));
        if (isset($mimeTypes[$extension])) {
            header('Content-Type: ' . $mimeTypes[$extension]);
            sendStaticCacheHeaders($requestedFile);
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                readfile($requestedFile);
            }
            exit;
        }
    }

    // BrowserRouter needs this fallback for /track, /dashboard, and other SPA routes.
    if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        header('Content-Type: text/html; charset=UTF-8');
        // The HTML shell intentionally revalidates so it can point users at
        // the latest fingerprinted React chunks after a deployment.
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            readfile($spaIndex);
        }
        exit;
    }
}

// Fallback if React build is not found
header('Content-Type: application/json; charset=UTF-8');
http_response_code(503);
echo json_encode([
    'status' => 'error',
    'code' => 503,
    'message' => 'React frontend build not found in backend/public. Please run `npm run build:backend` in the frontend directory.'
], JSON_UNESCAPED_SLASHES);
exit;
