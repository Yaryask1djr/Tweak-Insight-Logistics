<?php

// -------------------------------------------------------------
// Tweak Insight Logistics — Dedicated API Router
// -------------------------------------------------------------

// Bootstrap environment & classes
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/auth_middleware.php';
require_once __DIR__ . '/../helpers/tracker_helper.php';
require_once __DIR__ . '/../helpers/rate_limiter.php';
require_once __DIR__ . '/../helpers/security_headers.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../controllers/AdminController.php';
require_once __DIR__ . '/../controllers/DeliveryController.php';
require_once __DIR__ . '/../controllers/DeliveryPersonController.php';
require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../controllers/DriverOperationsController.php';
require_once __DIR__ . '/../controllers/OperationsManagementController.php';
require_once __DIR__ . '/../controllers/LiveTrackingController.php';
require_once __DIR__ . '/../controllers/ClientKycController.php';

SecurityHeaders::send();

// Dynamic Whitelisted CORS Configuration
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$defaultOrigins = 'http://localhost:3000,http://127.0.0.1:3000,http://localhost:8000,http://127.0.0.1:8000';
$allowedEnv = getenv('ALLOWED_ORIGINS') ?: $defaultOrigins;
$allowedOrigins = array_map('trim', explode(',', $allowedEnv));

if (!empty($origin) && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header("Access-Control-Allow-Credentials: true");
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Request-ID");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=UTF-8");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Parse Request Path
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Normalize path: strip "/backend" and "/api" prefix for routing
$path = preg_replace('#^(/backend)?(/api)?#', '', $uri);
$path = '/' . ltrim($path, '/');

// Lazy DB Connection helper
function getDb(): PDO {
    static $db = null;
    if ($db === null) {
        $database = new Database();
        $db = $database->getConnection();
    }
    return $db;
}

// -------------------------------------------------------------
// API ROUTING TABLE
// -------------------------------------------------------------

switch (true) {
    // ---------------------------------------------------------
    // AUTHENTICATION
    // ---------------------------------------------------------
    case ($path === '/auth/register-client' && $method === 'POST'):
        RateLimiter::check('register');
        AuthController::registerClient(getDb());
        break;

    case ($path === '/auth/register-delivery' && $method === 'POST'):
        RateLimiter::check('register');
        AuthController::registerDelivery(getDb());
        break;

    case ($path === '/auth/login' && $method === 'POST'):
        RateLimiter::check('login');
        AuthController::login(getDb());
        break;

    case ($path === '/auth/refresh' && $method === 'POST'):
        RateLimiter::check('refresh');
        AuthController::refresh(getDb());
        break;

    case ($path === '/auth/me' && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'account.read_self');
        AuthController::me($db, $user);
        break;

    case ($path === '/auth/logout' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verify($db);
        AuthController::logout($db, $user);
        break;

    case ($path === '/auth/change-password' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verify($db);
        AuthController::changePassword($db, $user);
        break;

    // ---------------------------------------------------------
    // CLIENT KYC
    // ---------------------------------------------------------
    case (($path === '/client/kyc/status' || $path === '/client/kyc') && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'kyc.read_own');
        ClientKycController::getKycStatus($db, (int)$user['id']);
        break;

    case (($path === '/client/kyc/documents/upload' || $path === '/client/kyc/submit') && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'kyc.submit_own');
        ClientKycController::uploadDocument($db, (int)$user['id']);
        break;

    case ($path === '/client/kyc/documents/file' && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'kyc.read_own');
        ClientKycController::getDocumentFile($db, (int)$user['id']);
        break;

    // ---------------------------------------------------------
    // PUBLIC & CLIENT DELIVERIES
    // ---------------------------------------------------------
    case ($path === '/deliveries/calculate-price' && $method === 'POST'):
        DeliveryController::calculatePrice(getDb());
        break;

    case ($path === '/deliveries/track' && $method === 'GET'):
        RateLimiter::check('public_tracking');
        DeliveryController::trackDelivery(getDb());
        break;

    case ($path === '/deliveries/live-location' && $method === 'GET'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'tracking.live.read_own'); LiveTrackingController::delivery($db, $user); break;

    case ($path === '/deliveries/live-location/stream' && $method === 'GET'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'tracking.live.read_own'); LiveTrackingController::streamDeliveryLocation($db, $user); break;

    case ($path === '/admin/live-tracking' && $method === 'GET'):
        $db = getDb(); AuthMiddleware::verifyPermission($db, 'operations.deliveries.read'); LiveTrackingController::activeForAdmin($db); break;

    case ($path === '/deliveries/confirm-receipt' && $method === 'POST'):
        $db = getDb();
        RateLimiter::check('otp_confirmation', $db);
        $user = AuthMiddleware::verifyPermission($db, 'driver.proof.confirm_assigned');
        DeliveryController::confirmReceipt($db, (int)$user['id']);
        break;

    case ($path === '/deliveries/create' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'delivery.request.create');
        DeliveryController::createDelivery($db, (int)$user['id']);
        break;

    case (($path === '/deliveries/my-requests' || $path === '/deliveries/my-deliveries') && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'delivery.request.read_own');
        DeliveryController::getClientDeliveries($db, (int)$user['id']);
        break;

    // ---------------------------------------------------------
    // USER NOTIFICATIONS
    // ---------------------------------------------------------
    case ($path === '/notifications' && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'notifications.read_own');
        NotificationController::list($db, (int)$user['id']);
        break;

    case ($path === '/notifications/unread-count' && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'notifications.read_own');
        NotificationController::unreadCount($db, (int)$user['id']);
        break;

    case ($path === '/notifications/read' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'notifications.read_own');
        NotificationController::markRead($db, (int)$user['id']);
        break;

    case ($path === '/notifications/read-all' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'notifications.read_own');
        NotificationController::markAllRead($db, (int)$user['id']);
        break;

    // ---------------------------------------------------------
    // ADMIN OPERATIONS
    // ---------------------------------------------------------
    case ($path === '/admin/client-kyc/pending' && $method === 'GET'):
        $db = getDb();
        AuthMiddleware::verifyPermission($db, 'operations.clients.read');
        AdminController::getPendingClientKyc($db);
        break;

    case ($path === '/admin/client-kyc/details' && $method === 'GET'):
        $db = getDb();
        AuthMiddleware::verifyPermission($db, 'operations.clients.read');
        AdminController::getClientKycDetails($db);
        break;

    case ($path === '/admin/client-kyc/approve' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'operations.clients.manage');
        AdminController::approveClientKyc($db, (int)$user['id']);
        break;

    case ($path === '/admin/client-kyc/reject' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'operations.clients.manage');
        AdminController::rejectClientKyc($db, (int)$user['id']);
        break;

    case ($path === '/admin/client-kyc/document-file' && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'operations.clients.read');
        AdminController::adminClientDocumentFile($db, (int)$user['id']);
        break;

    case ($path === '/admin/pending-deliverymen' && $method === 'GET'):
        $db = getDb();
        AuthMiddleware::verifyPermission($db, 'operations.drivers.read');
        AdminController::getPendingDeliverymen($db);
        break;

    case ($path === '/admin/approve-deliveryman' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'operations.drivers.manage');
        $data = json_decode(file_get_contents("php://input"));
        $id = (int)($data->user_id ?? $data->id ?? $_GET['id'] ?? 0);
        if (!$id) Response::error('User ID is required.');
        AdminController::approveDeliveryman($db, $id, (int)$user['id']);
        break;

    case ($path === '/admin/reject-deliveryman' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'operations.drivers.manage');
        $data = json_decode(file_get_contents("php://input"));
        $id = (int)($data->user_id ?? $data->id ?? $_GET['id'] ?? 0);
        $reason = trim((string)($data->reason ?? ''));
        if (!$id) Response::error('User ID is required.');
        AdminController::rejectDeliveryman($db, $id, (int)$user['id'], $reason);
        break;

    case ($path === '/admin/review-driver-document' && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.drivers.manage'); AdminController::reviewDriverDocument($db, (int)$user['id']); break;

    case ($path === '/admin/pending-driver-documents' && $method === 'GET'):
        $db = getDb(); AuthMiddleware::verifyPermission($db, 'operations.drivers.read'); AdminController::pendingDriverDocuments($db); break;

    case ($path === '/admin/driver-document-file' && $method === 'GET'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.drivers.read'); AdminController::driverDocumentFile($db, (int)$user['id']); break;

    case ($path === '/admin/release-assignment' && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.assignment.manage'); AdminController::releaseAssignment($db, (int)$user['id']); break;

    case ($path === '/admin/manual-assign' && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.assignment.manage'); AdminController::manualAssign($db, (int)$user['id']); break;

    case ($path === '/admin/audit-log' && $method === 'GET'):
        $db = getDb(); AuthMiddleware::verifyPermission($db, 'operations.audit.read'); AdminController::auditLog($db); break;

    case (($path === '/admin/audit-log/export' || $path === '/admin/audit-logs/export') && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.audit.read'); AdminController::queueAuditExport($db, (int)$user['id']); break;

    case ($path === '/admin/reports/deliveries' && $method === 'GET'):
        $db = getDb(); AuthMiddleware::verifyPermission($db, 'operations.deliveries.read'); AdminController::deliveryReport($db); break;

    case ($path === '/admin/drivers' && $method === 'GET'):
        $db = getDb(); AuthMiddleware::verifyPermission($db, 'operations.drivers.read'); OperationsManagementController::drivers($db); break;

    case ($path === '/admin/driver-operations' && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.drivers.manage'); OperationsManagementController::updateDriver($db, (int)$user['id']); break;

    case ($path === '/admin/rate-cards' && $method === 'GET'):
        $db = getDb(); AuthMiddleware::verifyPermission($db, 'operations.rates.read'); OperationsManagementController::rateCards($db); break;

    case ($path === '/admin/rate-cards' && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.rates.manage'); OperationsManagementController::saveRateCard($db, (int)$user['id']); break;

    case ($path === '/admin/business-accounts' && $method === 'GET'):
        $db = getDb(); AuthMiddleware::verifyPermission($db, 'operations.business.read'); OperationsManagementController::businessAccounts($db); break;

    case ($path === '/admin/business-accounts' && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.business.manage'); OperationsManagementController::saveBusinessAccount($db, (int)$user['id']); break;

    case (($path === '/admin/users' || $path === '/admin/all-users') && $method === 'GET'):
        $db = getDb();
        AuthMiddleware::verifyPermission($db, 'operations.clients.read');
        AdminController::getAllUsers($db);
        break;

    case (($path === '/admin/stats' || $path === '/admin/dashboard-stats') && $method === 'GET'):
        $db = getDb();
        AuthMiddleware::verifyPermission($db, 'operations.dashboard.read');
        AdminController::getDashboardStats($db);
        break;

    case (($path === '/admin/deliveries' || $path === '/admin/all-deliveries') && $method === 'GET'):
        $db = getDb();
        AuthMiddleware::verifyPermission($db, 'operations.deliveries.read');
        AdminController::getAllDeliveries($db);
        break;

    case ($path === '/admin/broadcast-offer' && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'operations.assignment.manage');
        AdminController::broadcastDeliveryOffer($db, (int)$user['id']);
        break;

    case ($path === '/admin/review-delivery' && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.deliveries.manage'); AdminController::reviewDeliveryRequest($db, (int)$user['id']); break;

    case ($path === '/admin/complete-delivery' && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.deliveries.manage'); AdminController::completeDelivery($db, (int)$user['id']); break;

    case ($path === '/admin/resolve-delivery' && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'operations.deliveries.manage'); AdminController::resolveDelivery($db, (int)$user['id']); break;

    // ---------------------------------------------------------
    // DELIVERY PARTNERS
    // ---------------------------------------------------------
    case (($path === '/delivery/available' || $path === '/delivery-person/available-deliveries') && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'driver.offer.read');
        DeliveryPersonController::getAvailableOffers($db, (int)$user['id']);
        break;

    case (($path === '/delivery/operations-profile' || $path === '/delivery-person/operations-profile') && $method === 'GET'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'driver.profile.read_own'); DriverOperationsController::profile($db, (int)$user['id']); break;

    case (($path === '/delivery/availability' || $path === '/delivery-person/availability') && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'driver.availability.manage_own'); DriverOperationsController::setAvailability($db, (int)$user['id']); break;

    case (($path === '/delivery/operations-profile' || $path === '/delivery-person/operations-profile') && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'driver.profile.manage_own'); DriverOperationsController::updateProfile($db, (int)$user['id']); break;

    case (($path === '/delivery/documents/upload' || $path === '/delivery-person/documents/upload') && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'driver.documents.manage_own'); DriverOperationsController::uploadDocument($db, (int)$user['id']); break;

    case (($path === '/delivery/documents/file' || $path === '/delivery-person/documents/file') && $method === 'GET'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'driver.documents.manage_own'); DriverOperationsController::documentFile($db, (int)$user['id']); break;

    case (($path === '/delivery/decline-offer' || $path === '/delivery-person/decline-offer') && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'driver.offer.decline'); DriverOperationsController::declineOffer($db, (int)$user['id']); break;

    case (($path === '/delivery/report-issue' || $path === '/delivery-person/report-issue') && $method === 'POST'):
        $db = getDb(); $user = AuthMiddleware::verifyPermission($db, 'driver.assignment.report_issue'); DriverOperationsController::reportAssignmentIssue($db, (int)$user['id']); break;

    case (($path === '/delivery/accept' || $path === '/delivery-person/accept-delivery') && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'driver.offer.accept');
        DeliveryPersonController::acceptDeliveryOffer($db, (int)$user['id']);
        break;

    case (($path === '/delivery/assignments' || $path === '/delivery-person/my-assignments') && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'driver.assignment.read_own');
        DeliveryPersonController::getMyAssignments($db, (int)$user['id']);
        break;

    case (($path === '/delivery/update-status' || $path === '/delivery-person/update-status') && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'driver.assignment.update_status');
        DeliveryPersonController::updateDeliveryStatus($db, (int)$user['id']);
        break;

    case (($path === '/delivery/location' || $path === '/delivery-person/update-location') && $method === 'POST'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'driver.assignment.update_location');
        DeliveryPersonController::updateLocation($db, (int)$user['id']);
        break;

    case (($path === '/delivery/earnings' || $path === '/delivery-person/my-earnings') && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'driver.earnings.read_own');
        DeliveryPersonController::getMyEarnings($db, (int)$user['id']);
        break;

    case (($path === '/delivery/earnings-summary' || $path === '/delivery-person/earnings-summary') && $method === 'GET'):
        $db = getDb();
        $user = AuthMiddleware::verifyPermission($db, 'driver.earnings.read_own');
        DeliveryPersonController::getEarningsSummary($db, (int)$user['id']);
        break;

    // Health check
    case ($path === '/' || $path === '/health'):
        Response::json([
            'service' => 'Tweak Insight Logistics API',
            'version' => '2.0.0',
            'status' => 'operational',
            'timestamp' => date('c')
        ], 'API operational.');
        break;

    default:
        Response::notFound("Endpoint '{$method} {$uri}' not found.");
        break;
}
