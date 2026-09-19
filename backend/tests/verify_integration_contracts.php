<?php

declare(strict_types=1);

/**
 * Executable integration-contract checks for the backend HTTP boundaries.
 *
 * These checks intentionally use the checked-in router/controller contract so
 * CI can run without production credentials. Database-backed endpoint tests
 * can layer on the same assertions when TEST_DB_* credentials are available.
 */
require_once __DIR__ . '/../helpers/authorization_policy.php';
require_once __DIR__ . '/../helpers/response.php';

const BACKEND_ROOT = __DIR__ . '/..';

function fileText(string $relativePath): string
{
    $path = BACKEND_ROOT . '/' . ltrim($relativePath, '/');
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$relativePath}");
    }
    return $contents;
}

function contains(string $contents, string $needle, string $message): void
{
    if (!str_contains($contents, $needle)) {
        throw new RuntimeException($message);
    }
}

function absent(string $contents, string $needle, string $message): void
{
    if (str_contains($contents, $needle)) {
        throw new RuntimeException($message);
    }
}

// Tracking authorization: public tracking may use a secure reference, but
// phone knowledge must not promote an anonymous viewer to a private viewer.
$deliveryController = fileText('controllers/DeliveryController.php');
contains($deliveryController, 'public_tracking_token', 'Tracking must query the secure public tracking token.');
contains($deliveryController, 'AuthMiddleware::getAuthenticatedUser', 'Tracking must resolve authenticated viewers.');
absent($deliveryController, 'verifyPhone', 'Phone verification must not authorize private tracking data.');

// Delivery list contract: fields rendered by client/admin list views must stay
// in their selective SQL projections, including item_description.
contains($deliveryController, 'd.item_description', 'Client delivery lists must return item_description.');
$adminController = fileText('controllers/AdminController.php');
contains($adminController, 'd.item_description', 'Admin delivery lists must return item_description.');

// Refresh rotation contract: long-lived credentials remain cookie-only.
$refreshSession = fileText('helpers/refresh_session.php');
contains($refreshSession, "\$_COOKIE[self::COOKIE_NAME]", 'Refresh sessions must read the HttpOnly cookie.');
absent($refreshSession, 'HTTP_X_REFRESH_TOKEN', 'Refresh tokens must not be accepted through a custom header.');
absent($refreshSession, "\$_POST['refresh_token']", 'Refresh tokens must not be accepted from form data.');

// KYC upload contract: route, controller, and content validation must remain
// connected before a document can enter private storage.
$apiRouter = fileText('api/index.php');
$kycController = fileText('controllers/ClientKycController.php');
 $rateLimiter = fileText('helpers/rate_limiter.php');
contains($apiRouter, "RateLimiter::check('public_tracking')", 'Public tracking must have an endpoint-specific rate limit.');
contains($apiRouter, 'RateLimiter::check(\'otp_confirmation\', $db)', 'OTP confirmation must have an endpoint-specific rate limit.');
contains($rateLimiter, "'public_tracking'", 'Public tracking rate-limit policy is missing.');
contains($rateLimiter, "'otp_confirmation'", 'OTP confirmation rate-limit policy is missing.');
contains($apiRouter, '/client/kyc/documents/upload', 'KYC upload route is missing.');
contains($kycController, 'UploadSecurity::validateUploadedFile', 'KYC uploads must pass upload validation.');
contains($kycController, 'Storage::adapter()->put', 'KYC uploads must use the storage adapter.');
contains($kycController, 'client_kyc_documents', 'KYC uploads must persist document metadata.');
contains($kycController, 'JobQueue::push(\'storage.delete\'', 'Replaced KYC objects must be queued for asynchronous deletion.');
absent($kycController, 'Storage::adapter()->delete($previous[\'storage_key\'])', 'Replaced KYC objects must not be deleted synchronously.');
$queueWorker = fileText('scripts/queue_worker.php');
contains($queueWorker, "'storage.delete'", 'The queue worker must dispatch storage deletion jobs.');

// Pagination contract: high-volume delivery and live feeds expose cursor or
// bounded pagination rather than unbounded result sets.
contains($deliveryController, 'next_cursor', 'Client delivery history must expose cursor pagination.');
contains($adminController, 'next_cursor', 'Admin delivery monitoring must expose cursor pagination.');
$liveTracking = fileText('controllers/LiveTrackingController.php');
contains($liveTracking, "ORDER BY latest.id DESC", 'Live telemetry must seek the latest event by indexed id.');
contains($liveTracking, "LIMIT :limit", 'Live telemetry must enforce a page limit.');

// Role-based access matrix: protected operations must have positive and
// negative cases for each supported user role.
$roleCases = [
    ['admin', 'operations.deliveries.read', true],
    ['client', 'operations.deliveries.read', false],
    ['delivery', 'operations.deliveries.read', false],
    ['client', 'delivery.request.create', true],
    ['delivery', 'delivery.request.create', false],
    ['delivery', 'tracking.live.read_own', true],
    ['admin', 'tracking.live.read_own', true],
];
foreach ($roleCases as [$role, $permission, $expected]) {
    $actual = AuthorizationPolicy::can($role, $permission);
    if ($actual !== $expected) {
        throw new RuntimeException("Role policy mismatch for {$role}: {$permission}");
    }
}

echo "Backend integration contract checks passed.\n";