<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth_middleware.php';
require_once __DIR__ . '/../helpers/kano_service_area.php';
require_once __DIR__ . '/../helpers/delivery_status_policy.php';
require_once __DIR__ . '/../helpers/operational_records.php';
require_once __DIR__ . '/../helpers/operations_schema.php';
require_once __DIR__ . '/../helpers/notification_service.php';
require_once __DIR__ . '/../helpers/cache_helper.php';
require_once __DIR__ . '/../helpers/database_transaction.php';

class DeliveryPersonController
{
    private const KANO_LAT_MIN = 11.85;
    private const KANO_LAT_MAX = 12.25;
    private const KANO_LON_MIN = 8.25;
    private const KANO_LON_MAX = 8.85;
    /**
     * Get list of open broadcasted delivery offers awaiting driver acceptance (with pagination)
     */
    public static function getAvailableOffers(PDO $db, int $userId): void
    {
        AuthMiddleware::requireDriverKyc($db, $userId);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        OperationsSchema::requireTables($db, ['drivers', 'driver_availability', 'delivery_driver_offers']);
        $driverId = OperationsSchema::ensureDriverProfile($db, $userId);
        $availability = $db->prepare('SELECT availability_status FROM driver_availability WHERE driver_id = ?');
        $availability->execute([$driverId]);
        if ($availability->fetchColumn() !== 'available') Response::forbidden('Set your availability to available before viewing delivery offers.');

        $countStmt = $db->prepare("SELECT COUNT(*) FROM deliveries d JOIN delivery_driver_offers o ON o.delivery_id = d.id WHERE o.driver_id = ? AND o.offer_status = 'offered' AND (o.expires_at IS NULL OR o.expires_at > NOW()) AND d.delivery_person_id IS NULL AND d.status = 'broadcasted' AND d.pickup_city = 'Kano' AND d.delivery_city = 'Kano'");
        $countStmt->execute([$driverId]);
        $totalCount = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT d.*, o.id AS offer_id, o.expires_at AS offer_expires_at,
                              u.full_name as client_name,
                              u.phone as client_phone
                              FROM deliveries d
                              JOIN delivery_driver_offers o ON o.delivery_id = d.id
                              JOIN users u ON d.client_id = u.id
                              WHERE o.driver_id = :driver_profile_id AND o.offer_status = 'offered' AND (o.expires_at IS NULL OR o.expires_at > NOW())
                              AND d.delivery_person_id IS NULL 
                              AND d.status = 'broadcasted'
                              AND d.pickup_city = 'Kano' AND d.delivery_city = 'Kano'
                              ORDER BY d.request_time DESC
                              LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':driver_profile_id', $driverId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Show the same commission used when an order is settled.
        foreach ($offers as &$offer) {
            $offer['driver_earning'] = self::driverEarning((float)$offer['total_cost']);
            unset($offer['delivery_otp']);
        }

        Response::paginated($offers, $totalCount, $page, $limit, 'Available delivery offers retrieved.');
    }

    /**
     * Driver claims/accepts a delivery offer with atomic lock
     */
    public static function acceptDeliveryOffer(PDO $db, int $userId): void
    {
        // Server-side KYC gate — driver must be verified before accepting any offer.
        AuthMiddleware::requireDriverKyc($db, $userId);

        $data = json_decode(file_get_contents("php://input"));
        $deliveryId = $data->delivery_id ?? null;

        if (!$deliveryId) {
            Response::error('Delivery ID is required.');
        }
        OperationsSchema::requireTables($db, ['drivers', 'driver_availability', 'delivery_driver_offers']);
        $driverProfileId = OperationsSchema::ensureDriverProfile($db, $userId);
        $availability = $db->prepare('SELECT availability_status FROM driver_availability WHERE driver_id = ?');
        $availability->execute([$driverProfileId]);
        if ($availability->fetchColumn() !== 'available') Response::forbidden('Set your availability to available before accepting an offer.');

        // Wrap the dispatch transaction in DatabaseTransaction::run with 3-attempt deadlock/lock timeout retry
        DatabaseTransaction::run($db, function(PDO $db) use ($deliveryId, $driverProfileId, $userId) {
            // 1. Atomic pessimistic lock: lock the delivery row to prevent concurrent claims
            $lockStmt = $db->prepare("SELECT id, status, delivery_person_id, pickup_city, delivery_city 
                                      FROM deliveries 
                                      WHERE id = ? 
                                      FOR UPDATE");
            $lockStmt->execute([$deliveryId]);
            $deliveryRow = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$deliveryRow) {
                DatabaseTransaction::fail('Delivery offer not available or has been cancelled.', 404);
            }

            if ($deliveryRow['status'] !== 'broadcasted' || !empty($deliveryRow['delivery_person_id'])) {
                DatabaseTransaction::fail('This delivery offer has already been accepted by another delivery partner.', 409);
            }

            if ($deliveryRow['pickup_city'] !== 'Kano' || $deliveryRow['delivery_city'] !== 'Kano') {
                DatabaseTransaction::fail('This delivery is outside the Kano service area.', 400);
            }

            // 2. Validate driver's offer record
            $offer = $db->prepare("UPDATE delivery_driver_offers 
                                   SET offer_status = 'accepted', responded_at = NOW() 
                                   WHERE delivery_id = ? AND driver_id = ? 
                                   AND offer_status = 'offered' 
                                   AND (expires_at IS NULL OR expires_at > NOW())");
            $offer->execute([$deliveryId, $driverProfileId]);
            if (!$offer->rowCount()) {
                DatabaseTransaction::fail('This offer is no longer available to your account.', 409);
            }

            // 3. Assign the delivery
            $assignStmt = $db->prepare("UPDATE deliveries 
                                        SET delivery_person_id = :user_id, status = 'assigned'
                                        WHERE id = :delivery_id 
                                        AND delivery_person_id IS NULL 
                                        AND status = 'broadcasted'");
            $assignStmt->execute([
                'user_id' => $userId,
                'delivery_id' => $deliveryId
            ]);

            if ($assignStmt->rowCount() === 0) {
                DatabaseTransaction::fail('This delivery offer has already been accepted by another delivery partner.', 409);
            }

            // 4. Withdraw competing offers for this delivery
            $db->prepare("UPDATE delivery_driver_offers 
                          SET offer_status = 'withdrawn', responded_at = NOW(), 
                              response_reason = 'Another driver accepted the delivery' 
                          WHERE delivery_id = ? AND driver_id != ? AND offer_status = 'offered'")
               ->execute([$deliveryId, $driverProfileId]);

            // 5. Update driver availability to busy
            $db->prepare("UPDATE driver_availability 
                          SET availability_status = 'busy', available_since = NULL 
                          WHERE driver_id = ?")
               ->execute([$driverProfileId]);

            // 6. Record operational transitions and audit ledger
            OperationalRecords::statusTransition($db, (int)$deliveryId, 'broadcasted', 'assigned', $userId, 'delivery', null, [
                'assignment_method' => 'broadcast',
            ]);
            OperationalRecords::assignmentLocked($db, (int)$deliveryId, $userId);
        }, 3);

        // 7. Post-transaction notifications and response payload
        NotificationService::assignmentAccepted($db, (int)$deliveryId, $userId);

        $fetchStmt = $db->prepare("SELECT d.*, u.full_name as client_name, u.phone as client_phone 
                                   FROM deliveries d 
                                   JOIN users u ON d.client_id = u.id 
                                   WHERE d.id = ?");
        $fetchStmt->execute([$deliveryId]);
        $delivery = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        Response::json($delivery, 'Delivery offer accepted. You are assigned to this delivery.');
    }

    /**
     * Get jobs assigned to current driver (with pagination and status filter)
     */
    public static function getMyAssignments(PDO $db, int $userId): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        $status = trim($_GET['status'] ?? 'all');

        $whereClause = "WHERE d.delivery_person_id = :user_id";
        $params = ['user_id' => $userId];

        if ($status !== 'all' && !empty($status)) {
            $whereClause .= " AND d.status = :status";
            $params['status'] = $status;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM deliveries d {$whereClause}");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        $query = "SELECT d.*, 
                  u.full_name as client_name,
                  u.phone as client_phone
                  FROM deliveries d
                  JOIN users u ON d.client_id = u.id
                  {$whereClause}
                  ORDER BY d.request_time DESC
                  LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($query);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deliveries as &$d) {
            $d['driver_earning'] = self::driverEarning((float)$d['total_cost']);
            unset($d['delivery_otp']);
        }

        Response::paginated($deliveries, $totalCount, $page, $limit, 'Driver assignments retrieved.');
    }

    /**
     * Driver updates delivery milestone status
     */
    public static function updateDeliveryStatus(PDO $db, int $userId): void
    {
        AuthMiddleware::requireDriverKyc($db, $userId);
        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->delivery_id) || empty($data->status)) {
            Response::error('Both delivery_id and status are required.');
        }

        // Verify assignment
        $checkStmt = $db->prepare("SELECT * FROM deliveries WHERE id = ? AND delivery_person_id = ?");
        $checkStmt->execute([$data->delivery_id, $userId]);
        $delivery = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            Response::forbidden('Delivery record not found or not assigned to your account.');
        }

        if (!DeliveryStatusPolicy::driverTransition($delivery['status'], $data->status)) {
            Response::error("Invalid delivery workflow transition from '{$delivery['status']}' to '{$data->status}'.", 409);
        }

        $updateFields = ["status = :status"];
        $params = [
            'status' => $data->status,
            'id' => $data->delivery_id,
            'user_id' => $userId
        ];

        if ($data->status === 'failed') {
            $reason = trim((string)($data->status_reason ?? ''));
            if ($reason === '') Response::error('A reason is required when marking a delivery failed.', 422);
            $updateFields[] = 'status_reason = :status_reason';
            $params['status_reason'] = $reason;
        }

        if ($data->status === 'picked_up' && empty($delivery['pickup_time'])) {
            $updateFields[] = "pickup_time = NOW()";
        }

        $sql = "UPDATE deliveries SET " . implode(', ', $updateFields) . " WHERE id = :id AND delivery_person_id = :user_id";
        $updateStmt = $db->prepare($sql);
        $updateStmt->execute($params);
        OperationalRecords::statusTransition(
            $db,
            (int)$data->delivery_id,
            $delivery['status'],
            $data->status,
            $userId,
            'delivery',
            $params['status_reason'] ?? null
        );
        NotificationService::deliveryStatusChanged(
            $db,
            (int)$data->delivery_id,
            $delivery['status'],
            $data->status
        );
        if ($data->status === 'failed') self::releaseAvailabilityIfIdle($db, $userId);

        Response::json([
            'delivery_id' => (int)$data->delivery_id,
            'status' => $data->status,
            'updated_at' => date('Y-m-d H:i:s')
        ], "Delivery status successfully updated to '{$data->status}'.");
    }

    /** Store a GPS sample from the assigned driver's device for an in-transit delivery. */
    public static function updateLocation(PDO $db, int $userId): void
    {
        AuthMiddleware::requireDriverKyc($db, $userId);
        $data = json_decode(file_get_contents('php://input'));
        if (empty($data->delivery_id) || !isset($data->latitude, $data->longitude)) {
            Response::error('delivery_id, latitude, and longitude are required.');
        }

        $latitude = (float)$data->latitude;
        $longitude = (float)$data->longitude;
        $accuracy = isset($data->accuracy_m) ? max(0, (float)$data->accuracy_m) : null;
        $heading = isset($data->heading_degrees) && $data->heading_degrees !== null ? (float)$data->heading_degrees : null;
        if ($accuracy !== null && $accuracy > 500) Response::error('GPS accuracy is too low; wait for a better location signal.', 422);
        if ($heading !== null && ($heading < 0 || $heading > 360)) Response::error('GPS heading must be between 0 and 360 degrees.', 422);
        if ($latitude < self::KANO_LAT_MIN || $latitude > self::KANO_LAT_MAX || $longitude < self::KANO_LON_MIN || $longitude > self::KANO_LON_MAX) {
            Response::error('Location is outside the Kano service area.', 422);
        }

        $check = $db->prepare("SELECT id FROM deliveries WHERE id = ? AND delivery_person_id = ? AND status = 'in_transit'");
        $check->execute([(int)$data->delivery_id, $userId]);
        if (!$check->fetch()) {
            Response::forbidden('Live location can be updated only for your assigned in-transit delivery.');
        }
        $recent = $db->prepare('SELECT a.last_location_at FROM driver_availability a JOIN drivers d ON d.id = a.driver_id WHERE d.user_id = ?');
        $recent->execute([$userId]);
        $lastAt = $recent->fetchColumn();
        if ($lastAt && strtotime($lastAt) > time() - 10) {
            Response::json(['delivery_id' => (int)$data->delivery_id, 'accepted' => false, 'retry_after_seconds' => 10], 'GPS update throttled to one sample every 10 seconds.', 202);
        }

        // Ephemeral GPS store: Update driver_availability
        $updateAvail = $db->prepare('UPDATE driver_availability a JOIN drivers d ON d.id = a.driver_id SET a.last_latitude = ?, a.last_longitude = ?, a.last_location_at = NOW() WHERE d.user_id = ?');
        $updateAvail->execute([$latitude, $longitude, $userId]);

        $locationPayload = [
            'latitude'   => $latitude,
            'longitude'  => $longitude,
            'accuracy_m' => $accuracy,
            'heading_degrees' => $heading,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Multi-tier fast cache (Redis / APCu / Memory / Fast file cache)
        CacheHelper::setDriverLocation((int)$data->delivery_id, $locationPayload, 300);
        CacheHelper::delete("admin:active_deliveries_geo");

        // Fast Redis GEO & Ephemeral Key update if Redis is available
        $redisHost = getenv('REDIS_HOST');
        if (!empty($redisHost) && class_exists('Redis')) {
            try {
                $redis = new Redis();
                if ($redis->connect($redisHost, (int)(getenv('REDIS_PORT') ?: 6379), 0.2)) {
                    $auth = getenv('REDIS_PASSWORD');
                    if (!empty($auth)) $redis->auth($auth);
                    $redis->geoAdd('geo:drivers', (float)$longitude, (float)$latitude, (string)$userId);
                }
            } catch (Throwable $e) {}
        }

        // Write point-in-time breadcrumb to isolated telemetry table
        $event = $db->prepare('INSERT INTO delivery_location_events (delivery_id, delivery_person_id, latitude, longitude, accuracy_m, heading_degrees, recorded_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $event->execute([(int)$data->delivery_id, $userId, $latitude, $longitude, $accuracy, $heading]);

        // Initialize tracking_started_at once on deliveries table (prevents repeated row-lock churn)
        $db->prepare('UPDATE deliveries SET tracking_started_at = NOW() WHERE id = ? AND tracking_started_at IS NULL')->execute([(int)$data->delivery_id]);

        Response::json(['delivery_id' => (int)$data->delivery_id, 'latitude' => $latitude, 'longitude' => $longitude, 'heading_degrees' => $heading], 'Live location recorded.');
    }

    /**
     * Get detailed earnings breakdown for driver (with pagination)
     */
    public static function getMyEarnings(PDO $db, int $userId): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM delivery_earnings WHERE delivery_person_id = ?");
        $countStmt->execute([$userId]);
        $totalCount = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("SELECT e.*, d.tracking_number, d.item_description, d.total_cost as delivery_cost,
                              d.delivery_time, d.pickup_address, d.delivery_address
                              FROM delivery_earnings e
                              JOIN deliveries d ON e.delivery_id = d.id
                              WHERE e.delivery_person_id = :user_id
                              ORDER BY e.created_at DESC
                              LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $earnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::paginated($earnings, $totalCount, $page, $limit, 'Driver earnings retrieved.');
    }

    /** A driver who closes their last active assignment becomes available again. */
    private static function releaseAvailabilityIfIdle(PDO $db, int $userId): void
    {
        if (!OperationsSchema::hasTable($db, 'drivers') || !OperationsSchema::hasTable($db, 'driver_availability')) return;
        $active = $db->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_person_id = ? AND status IN ('assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived')");
        $active->execute([$userId]);
        if ((int)$active->fetchColumn() !== 0) return;
        $driverId = OperationsSchema::driverIdForUser($db, $userId);
        if ($driverId) $db->prepare("UPDATE driver_availability SET availability_status = 'available', available_since = NOW() WHERE driver_id = ? AND availability_status = 'busy'")->execute([$driverId]);
    }

    /**
     * Get aggregate earnings metrics for driver
     */
    public static function getEarningsSummary(PDO $db, int $userId): void
    {
        $stmt = $db->prepare("SELECT 
            COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as total_earned,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as total_pending,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURRENT_DATE AND status != 'void' THEN amount ELSE 0 END), 0) as today_earned,
            COALESCE(SUM(CASE WHEN YEARWEEK(created_at, 1) = YEARWEEK(CURRENT_DATE, 1) AND status != 'void' THEN amount ELSE 0 END), 0) as weekly_earned,
            COALESCE(SUM(CASE WHEN YEAR(created_at) = YEAR(CURRENT_DATE) AND MONTH(created_at) = MONTH(CURRENT_DATE) AND status != 'void' THEN amount ELSE 0 END), 0) as monthly_earned,
            COUNT(*) as completed_trips
            FROM delivery_earnings 
            WHERE delivery_person_id = ?");
        $stmt->execute([$userId]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);

        Response::json([
            'total_earned' => (float)$summary['total_earned'],
            'total_pending' => (float)$summary['total_pending'],
            'today_earned' => (float)$summary['today_earned'],
            'weekly_earned' => (float)$summary['weekly_earned'],
            'monthly_earned' => (float)$summary['monthly_earned'],
            'completed_trips' => (int)$summary['completed_trips'],
            'currency' => 'NGN',
            'currency_symbol' => '₦'
        ], 'Earnings summary retrieved.');
    }

    /** Keep dashboard estimates aligned with the backend settlement rule. */
    private static function driverEarning(float $totalCost): float
    {
        $config = file_exists(__DIR__ . '/../config/config.php') ? require __DIR__ . '/../config/config.php' : [];
        $rate = (float)($config['pricing']['driver_commission_pct'] ?? 0.65);
        return round($totalCost * max(0, min(1, $rate)), 2);
    }
}
