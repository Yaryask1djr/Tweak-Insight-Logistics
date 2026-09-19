<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/delivery_status_policy.php';
require_once __DIR__ . '/../helpers/operational_records.php';
require_once __DIR__ . '/../helpers/notification_service.php';
require_once __DIR__ . '/../helpers/operations_schema.php';
require_once __DIR__ . '/../helpers/cache_helper.php';
require_once __DIR__ . '/../helpers/storage_adapter.php';
require_once __DIR__ . '/../helpers/secure_document_download.php';
require_once __DIR__ . '/../helpers/database_transaction.php';
require_once __DIR__ . '/../helpers/spatial_helper.php';
require_once __DIR__ . '/ClientKycController.php';

class AdminController
{
    /**
     * Get pending delivery partner registrations for KYC verification (with pagination)
     */
    public static function getPendingDeliverymen(PDO $db): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_documents']);
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 15)));
        $cursor = isset($_GET['cursor']) ? (int)$_GET['cursor'] : null;
        $cursorClause = $cursor === null ? '' : ' AND u.id < :cursor';

        $stmt = $db->prepare("SELECT u.id, u.full_name, u.email, u.phone, u.address, u.created_at,
                              d.kyc_status,
                              (SELECT COUNT(*) FROM driver_documents dd WHERE dd.driver_id = d.id AND dd.verification_status = 'pending') AS pending_documents,
                              (SELECT COUNT(DISTINCT dd.document_type) FROM driver_documents dd WHERE dd.driver_id = d.id AND dd.document_type IN ('government_id', 'drivers_license') AND dd.verification_status = 'verified') AS verified_mandatory_documents
                              FROM users u
                              LEFT JOIN drivers d ON d.user_id = u.id
                              WHERE u.role = 'delivery' AND u.is_approved = 0 AND COALESCE(d.kyc_status, 'not_submitted') <> 'rejected'{$cursorClause}
                              ORDER BY u.id DESC
                              LIMIT :limit");
        if ($cursor !== null) $stmt->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $nextCursor = count($results) === $limit && !empty($results) ? (int)end($results)['id'] : null;
        Response::json(['items' => $results, 'next_cursor' => $nextCursor, 'has_more' => $nextCursor !== null], 'Pending delivery partners retrieved.');
    }

    /**
     * Approve delivery partner KYC
     */
    public static function approveDeliveryman(PDO $db, int $id, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_documents', 'driver_availability']);
        $driverId = OperationsSchema::ensureDriverProfile($db, $id);
        $documents = $db->prepare("SELECT COUNT(DISTINCT document_type) FROM driver_documents WHERE driver_id = ? AND document_type IN ('government_id', 'drivers_license') AND verification_status = 'verified'");
        $documents->execute([$driverId]);
        if ((int)$documents->fetchColumn() < 2) {
            Response::error('Verify both a government ID and driver licence before approving this delivery partner.', 422);
        }
        $stmt = $db->prepare("UPDATE users SET is_approved = 1, account_status = 'active' WHERE id = ? AND role = 'delivery'");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            $db->prepare("UPDATE drivers SET kyc_status = 'verified', kyc_reviewed_by = ?, kyc_reviewed_at = NOW(), kyc_rejection_reason = NULL, active_status = 'active' WHERE id = ?")->execute([$adminId, $driverId]);
            $db->prepare("INSERT IGNORE INTO driver_availability (driver_id, availability_status) VALUES (?, 'offline')")->execute([$driverId]);
            OperationalRecords::audit($db, $adminId, 'admin', 'driver.kyc_approved', 'driver', $driverId, null, ['user_id' => $id]);
            NotificationService::publish($db, $id, 'driver.kyc_approved', 'Partner account approved', 'Your KYC review is complete. Set your availability when you are ready to receive Kano delivery offers.');
            Response::json(['id' => $id, 'is_approved' => 1, 'kyc_status' => 'verified'], 'Delivery partner approved successfully.');
        } else {
            Response::error('Delivery partner not found or already approved.', 404);
        }
    }

    /**
     * Reject or deactivate delivery partner
     */
    public static function rejectDeliveryman(PDO $db, int $id, int $adminId, string $reason): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_documents', 'driver_availability']);
        $reason = trim($reason);
        if ($reason === '') Response::error('A rejection reason is required.', 422);

        $account = $db->prepare("SELECT id, is_approved FROM users WHERE id = ? AND role = 'delivery' LIMIT 1");
        $account->execute([$id]);
        $driverAccount = $account->fetch(PDO::FETCH_ASSOC);
        if (!$driverAccount) Response::notFound('Delivery partner not found.');
        if ((int)$driverAccount['is_approved'] === 1) Response::error('An approved delivery partner cannot be rejected from the pending KYC queue.', 409);

        $driverId = OperationsSchema::ensureDriverProfile($db, $id);
        try {
            $db->beginTransaction();
            // KYC rejection is not an account suspension: the partner must be able
            // to sign in, see the reason, and upload corrected documents.
            $db->prepare("UPDATE users SET account_status = 'active', is_approved = 0 WHERE id = ?")->execute([$id]);
            $db->prepare("UPDATE drivers SET kyc_status = 'rejected', active_status = 'inactive', kyc_rejection_reason = ?, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE id = ?")->execute([$reason, $adminId, $driverId]);
            $db->prepare("UPDATE driver_documents SET verification_status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE driver_id = ? AND verification_status = 'pending'")->execute([$reason, $adminId, $driverId]);
            $db->prepare("INSERT IGNORE INTO driver_availability (driver_id, availability_status) VALUES (?, 'offline')")->execute([$driverId]);
            $db->prepare("UPDATE driver_availability SET availability_status = 'offline' WHERE driver_id = ?")->execute([$driverId]);
            $db->commit();
            OperationalRecords::audit($db, $adminId, 'admin', 'driver.kyc_rejected', 'driver', $driverId, null, ['user_id' => $id, 'kyc_status' => 'rejected', 'reason' => $reason]);
            NotificationService::publish($db, $id, 'driver.kyc_rejected', 'KYC Verification: Action Required', 'Your KYC application requires attention: ' . $reason . '. Update and resubmit your documents.');
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        Response::json(['id' => $id, 'kyc_status' => 'rejected', 'reason' => $reason], 'Delivery partner KYC rejected. The partner can correct and resubmit documents.');
    }

    /**
     * Get all platform users (clients, drivers, admins with pagination and role filter)
     */
    public static function getAllUsers(PDO $db): void
    {
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 15)));
        $cursor = isset($_GET['cursor']) ? (int)$_GET['cursor'] : null;
        $role = trim($_GET['role'] ?? 'all');

        $whereClause = "";
        $params = [];

        if ($role !== 'all' && !empty($role)) {
            $whereClause = "WHERE role = :role";
            $params['role'] = $role;
        }
        if ($cursor !== null) {
            $whereClause .= $whereClause ? ' AND id < :cursor' : 'WHERE id < :cursor';
            $params['cursor'] = $cursor;
        }

        $sql = "SELECT id, role, full_name, email, phone, address, is_approved, created_at 
                FROM users 
                {$whereClause}
                ORDER BY id DESC
                LIMIT :limit";

        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $nextCursor = count($users) === $limit && !empty($users) ? (int)end($users)['id'] : null;
        Response::json(['items' => $users, 'next_cursor' => $nextCursor, 'has_more' => $nextCursor !== null], 'Users retrieved.');
    }

    /**
     * Get central operations KPI metrics with TTL caching.
     */
    public static function getDashboardStats(PDO $db): void
    {
        $forceRefresh = !empty($_GET['refresh']) || !empty($_GET['no_cache']);
        if ($forceRefresh) {
            CacheHelper::delete('kpi:admin_dashboard_stats');
        }

        $stats = CacheHelper::remember('kpi:admin_dashboard_stats', 45, function () use ($db) {
            // Deliveries by status
            $statusCounts = $db->query("SELECT status, COUNT(*) as count FROM deliveries GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

            // Revenue stats
            $revStmt = $db->query("SELECT 
                COALESCE(SUM(total_cost), 0) as total_volume,
                COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_cost ELSE 0 END), 0) as collected_revenue
                FROM deliveries");
            $revenue = $revStmt->fetch(PDO::FETCH_ASSOC);

            // Fleet stats
            $driversStmt = $db->query("SELECT 
                COUNT(*) as total_drivers,
                SUM(CASE WHEN is_approved = 1 THEN 1 ELSE 0 END) as active_drivers,
                SUM(CASE WHEN is_approved = 0 THEN 1 ELSE 0 END) as pending_kyc
                FROM users WHERE role = 'delivery'");
            $drivers = $driversStmt->fetch(PDO::FETCH_ASSOC);

                        $availableDrivers = 0;
                        try {
                            $availableDriversStmt = $db->query("SELECT COUNT(*)
                                FROM driver_availability da
                                INNER JOIN drivers d ON d.id = da.driver_id
                                INNER JOIN users u ON u.id = d.user_id
                                WHERE u.role = 'delivery'
                                  AND u.is_approved = 1
                                  AND u.account_status = 'active'
                                  AND d.kyc_status = 'verified'
                                  AND da.availability_status = 'available'");
                            $availableDrivers = (int)$availableDriversStmt->fetchColumn();
                        } catch (Throwable $_) {
                            $availableDrivers = 0;
                        }

                        $delayedStmt = $db->query("SELECT COUNT(*)
                                FROM deliveries
                                WHERE preferred_delivery_time IS NOT NULL
                                    AND preferred_delivery_time < UTC_TIMESTAMP()
                                    AND status NOT IN ('delivered', 'completed', 'cancelled', 'rejected', 'failed')");
                        $delayedShipments = (int)$delayedStmt->fetchColumn();

            // Total clients
            $clientsStmt = $db->query("SELECT COUNT(*) as total_clients FROM users WHERE role = 'client'");
            $totalClients = (int)$clientsStmt->fetchColumn();

            $data = [
                'total_deliveries' => (int)array_sum($statusCounts),
                'pending_review' => (int)($statusCounts['pending'] ?? 0),
                'broadcasted' => (int)($statusCounts['broadcasted'] ?? 0),
                'assigned' => (int)($statusCounts['assigned'] ?? 0),
                'driver_en_route' => (int)($statusCounts['driver_en_route'] ?? 0),
                'picked_up' => (int)($statusCounts['picked_up'] ?? 0),
                'in_transit' => (int)($statusCounts['in_transit'] ?? 0),
                'active_shipments' => (int)array_sum(array_map(fn ($status) => (int)($statusCounts[$status] ?? 0), DeliveryStatusPolicy::ACTIVE)),
                'delivered' => (int)($statusCounts['delivered'] ?? 0),
                'cancelled' => (int)($statusCounts['cancelled'] ?? 0),
                'total_volume' => (float)$revenue['total_volume'],
                'collected_revenue' => (float)$revenue['collected_revenue'],
                'delayed_shipments' => $delayedShipments,
                'fleet' => [
                    'total' => (int)$drivers['total_drivers'],
                    'active' => (int)$drivers['active_drivers'],
                    'pending_kyc' => (int)$drivers['pending_kyc'],
                    'available' => $availableDrivers,
                ],
                'total_clients' => $totalClients
            ];

            // Retain dashboard-friendly summary
            $data['users'] = [
                'total' => $totalClients + (int)$drivers['total_drivers'],
                'clients' => $totalClients,
                'delivery_persons' => (int)$drivers['total_drivers'],
                'pending_approvals' => (int)$drivers['pending_kyc']
            ];
            $data['deliveries'] = [
                'total' => $data['total_deliveries'],
                'pending' => $data['pending_review'],
                'active' => $data['active_shipments'],
                'completed' => $data['delivered'],
                'cancelled' => $data['cancelled'],
                'revenue' => $data['collected_revenue']
            ];

            return $data;
        });

        Response::json($stats, 'Operations statistics retrieved.');
    }

    /**
     * Get all deliveries with filter and pagination support.
     *
     * Supports two pagination modes:
     *   1. Traditional offset: ?page=2&limit=15
     *   2. Keyset cursor:      ?cursor=<last_id>&limit=15  (faster at scale)
     */
    public static function getAllDeliveries(PDO $db): void
    {
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 15)));
        $status = trim($_GET['status'] ?? 'all');
        $cursor = isset($_GET['cursor']) ? (int)$_GET['cursor'] : null;

        $whereClause = "";
        $params = [];

        if ($status !== 'all' && !empty($status)) {
            $whereClause = "WHERE d.status = :status";
            $params['status'] = $status;
        }

        // Selective column projection — omit heavy text blobs on list views
        $columns = "d.id, d.tracking_number, d.status, d.service_type,
                    d.pickup_address, d.pickup_city, d.delivery_address, d.delivery_city,
                    d.pickup_contact_name, d.pickup_contact_phone,
                    d.delivery_contact_name, d.delivery_contact_phone,
                    d.item_description, d.item_category, d.item_quantity, d.item_weight,
                    d.is_fragile, d.is_perishable, d.distance_km,
                    d.base_cost, d.total_cost, d.payment_status,
                    d.request_time, d.pickup_time, d.delivery_time,
                    u1.full_name as client_name, u1.email as client_email, u1.phone as client_phone,
                    u2.full_name as delivery_person_name, u2.phone as delivery_person_phone";

        // Keyset (cursor) pagination — O(1) index seek regardless of depth
        if ($cursor !== null) {
            $cursorWhere = $whereClause ? "{$whereClause} AND d.id < :cursor" : "WHERE d.id < :cursor";
            $params['cursor'] = $cursor;

            $sql = "SELECT {$columns}
                    FROM deliveries d
                    LEFT JOIN users u1 ON d.client_id = u1.id
                    LEFT JOIN users u2 ON d.delivery_person_id = u2.id
                    {$cursorWhere}
                    ORDER BY d.id DESC
                    LIMIT :limit";

            $stmt = $db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(":{$k}", $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $nextCursor = !empty($deliveries) ? (int)end($deliveries)['id'] : null;

            Response::json([
                'deliveries'  => $deliveries,
                'next_cursor' => $nextCursor,
                'has_more'    => count($deliveries) === $limit,
            ], 'Deliveries retrieved.');
            return;
        }

        // Traditional offset pagination (backward compatible)
        $page = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM deliveries d {$whereClause}");
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        $query = "SELECT {$columns}
                  FROM deliveries d
                  LEFT JOIN users u1 ON d.client_id = u1.id
                  LEFT JOIN users u2 ON d.delivery_person_id = u2.id
                  {$whereClause}
                  ORDER BY d.id DESC
                  LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($query);
        foreach ($params as $k => $v) {
            $stmt->bindValue(":{$k}", $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::paginated($deliveries, $totalCount, $page, $limit, 'Deliveries retrieved.');
    }

    /**
     * Admin reviews client request & broadcasts offer to verified drivers
     */
    public static function broadcastDeliveryOffer(PDO $db, int $adminId): void
    {
        $data = json_decode(file_get_contents("php://input"));
        $deliveryId = $data->delivery_id ?? null;

        if (!$deliveryId) {
            Response::error('Delivery ID is required to broadcast offer.');
        }

        $stmt = $db->prepare("SELECT id, status, tracking_number FROM deliveries WHERE id = ?");
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            Response::error('Delivery not found.', 404);
        }

        if (!in_array($delivery['status'], ['under_review', 'broadcasted'], true)) {
            Response::error("Delivery cannot be broadcasted in status '{$delivery['status']}'.", 400);
        }

        OperationsSchema::requireTables($db, ['drivers', 'driver_availability', 'delivery_driver_offers']);
        DatabaseTransaction::run($db, function(PDO $db) use ($delivery, $deliveryId, $adminId) {
            if ($delivery['status'] === 'under_review') $db->prepare("UPDATE deliveries SET status = 'broadcasted' WHERE id = ?")->execute([$deliveryId]);
            self::materializeEligibleOffers($db, (int)$deliveryId, $adminId);
        }, 3);
        if ($delivery['status'] === 'under_review') {
            OperationalRecords::statusTransition($db, (int)$deliveryId, 'under_review', 'broadcasted', $adminId, 'admin');
            NotificationService::deliveryStatusChanged($db, (int)$deliveryId, 'under_review', 'broadcasted');
        } else {
            OperationalRecords::audit($db, $adminId, 'admin', 'delivery.offers_rebroadcast', 'delivery', (int)$deliveryId, null, ['status' => 'broadcasted']);
        }

        Response::json([
            'delivery_id' => (int)$deliveryId,
            'tracking_number' => $delivery['tracking_number'],
            'status' => 'broadcasted'
        ], 'Delivery offer successfully validated and broadcasted to eligible available Kano drivers.');
    }

    /** Admin starts formal review of a client request. */
    public static function reviewDeliveryRequest(PDO $db, int $adminId): void
    {
        self::transitionDelivery($db, 'under_review', $adminId);
    }

    /** Admin closes a delivered order after operational reconciliation. */
    public static function completeDelivery(PDO $db, int $adminId): void
    {
        self::transitionDelivery($db, 'completed', $adminId);
    }

    /** Admin may reject pre-dispatch requests or cancel a live operational exception. */
    public static function resolveDelivery(PDO $db, int $adminId): void
    {
        $data = json_decode(file_get_contents('php://input'));
        $target = $data->status ?? '';
        if (!in_array($target, ['rejected', 'cancelled'], true)) Response::error('Status must be rejected or cancelled.');
        self::transitionDelivery($db, $target, $adminId, $data->status_reason ?? null, (int)($data->delivery_id ?? 0));
    }

    /** Operations reviews a submitted KYC document. */
    public static function reviewDriverDocument(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_documents']);
        $data = json_decode(file_get_contents('php://input'));
        $documentId = (int)($data->document_id ?? 0);
        $decision = $data->decision ?? '';
        $reason = trim((string)($data->reason ?? ''));
        if (!$documentId || !in_array($decision, ['verified', 'rejected'], true)) Response::error('document_id and decision (verified or rejected) are required.');
        if ($decision === 'rejected' && $reason === '') Response::error('A rejection reason is required.', 422);

        $query = $db->prepare('SELECT dd.id, dd.driver_id, dd.document_type, dd.verification_status, u.id AS user_id FROM driver_documents dd JOIN drivers d ON d.id = dd.driver_id JOIN users u ON u.id = d.user_id WHERE dd.id = ?');
        $query->execute([$documentId]);
        $document = $query->fetch(PDO::FETCH_ASSOC);
        if (!$document) Response::notFound('Driver document not found.');
        if ($document['verification_status'] !== 'pending') Response::error('Only pending documents can be reviewed.', 409);

        $update = $db->prepare('UPDATE driver_documents SET verification_status = ?, reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ?');
        $update->execute([$decision, $adminId, $decision === 'rejected' ? $reason : null, $documentId]);
        if ($decision === 'rejected') {
            $db->prepare("UPDATE drivers SET kyc_status = 'rejected', kyc_rejection_reason = ?, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE id = ? AND kyc_status <> 'verified'")->execute([$reason, $adminId, (int)$document['driver_id']]);
        } else {
            $db->prepare("UPDATE drivers SET kyc_status = 'under_review', kyc_rejection_reason = NULL, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE id = ? AND kyc_status IN ('submitted', 'under_review')")->execute([$adminId, (int)$document['driver_id']]);
        }
        OperationalRecords::audit($db, $adminId, 'admin', 'driver.document_reviewed', 'driver_document', $documentId, ['verification_status' => 'pending'], ['verification_status' => $decision], ['reason' => $reason]);
        if ($decision === 'rejected') {
            NotificationService::driverDocumentIssue($db, (int)$document['user_id'], 'Document needs attention', ucfirst(str_replace('_', ' ', $document['document_type'])) . ': ' . $reason, ['document_id' => $documentId]);
        } else {
            NotificationService::publish($db, (int)$document['user_id'], 'driver.document_verified', 'Document verified', 'Your ' . str_replace('_', ' ', $document['document_type']) . ' has been verified.');
        }
        Response::json(['document_id' => $documentId, 'verification_status' => $decision], 'Document review recorded.');
    }

    /** Document queue used by operations before a partner KYC decision. */
    public static function pendingDriverDocuments(PDO $db): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_documents']);
        $page = max(1, (int)($_GET['page'] ?? 1)); $limit = max(1, min(100, (int)($_GET['limit'] ?? 20))); $offset = ($page - 1) * $limit;
        $total = (int)$db->query("SELECT COUNT(*) FROM driver_documents WHERE verification_status = 'pending'")->fetchColumn();
        $statement = $db->prepare("
            SELECT dd.id, dd.document_type, dd.document_number, dd.expires_at, dd.created_at,
                   d.id AS driver_id, d.vehicle_type, d.vehicle_registration, d.kyc_status,
                   u.id AS user_id, u.full_name, u.email, u.phone, u.created_at AS user_created_at,
                   (SELECT COUNT(DISTINCT dd_sub.document_type) FROM driver_documents dd_sub 
                    WHERE dd_sub.driver_id = d.id AND dd_sub.document_type IN ('government_id', 'drivers_license') 
                    AND dd_sub.verification_status = 'verified') AS verified_mandatory_docs
            FROM driver_documents dd 
            JOIN drivers d ON d.id = dd.driver_id 
            JOIN users u ON u.id = d.user_id 
            WHERE dd.verification_status = 'pending' 
            ORDER BY dd.created_at ASC 
            LIMIT :limit OFFSET :offset
        ");
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT); $statement->bindValue(':offset', $offset, PDO::PARAM_INT); $statement->execute();
        Response::paginated($statement->fetchAll(PDO::FETCH_ASSOC), $total, $page, $limit, 'Pending driver documents retrieved.');
    }

    /** Stream a KYC file only after an authenticated operations permission check. */
    public static function driverDocumentFile(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['driver_documents']);
        $documentId = (int)($_GET['document_id'] ?? 0);
        if (!$documentId) Response::error('document_id is required.');
        $statement = $db->prepare('SELECT dd.storage_key, dd.document_type FROM driver_documents dd WHERE dd.id = ?');
        $statement->execute([$documentId]);
        $document = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$document) Response::notFound('Driver document not found.');
        OperationalRecords::audit(
            $db,
            $adminId,
            'admin',
            'admin.driver_kyc_document_downloaded',
            'driver_document',
            $documentId
        );
        SecureDocumentDownload::send($document['storage_key'], 'driver-kyc-' . $documentId . '-' . $document['document_type']);
    }

    /** Release an assignment only before goods are picked up, then return it to dispatch. */
    public static function releaseAssignment(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_availability', 'delivery_assignments', 'delivery_driver_offers']);
        $data = json_decode(file_get_contents('php://input'));
        $deliveryId = (int)($data->delivery_id ?? 0);
        $reason = trim((string)($data->reason ?? ''));
        if (!$deliveryId || $reason === '') Response::error('delivery_id and a release reason are required.');

        $assignmentData = DatabaseTransaction::run($db, function(PDO $db) use ($deliveryId, $reason, $adminId) {
            $deliveryQuery = $db->prepare('SELECT id, client_id, delivery_person_id, status, tracking_number FROM deliveries WHERE id = ? FOR UPDATE');
            $deliveryQuery->execute([$deliveryId]);
            $delivery = $deliveryQuery->fetch(PDO::FETCH_ASSOC);
            if (!$delivery) DatabaseTransaction::fail('Delivery not found.', 404);
            if (!in_array($delivery['status'], ['assigned', 'driver_en_route'], true) || empty($delivery['delivery_person_id'])) {
                DatabaseTransaction::fail('Assignments may be released only before pickup.', 409);
            }

            $assignment = $db->prepare("SELECT id FROM delivery_assignments WHERE delivery_id = ? AND is_current = 1 AND assignment_status = 'locked' FOR UPDATE");
            $assignment->execute([$deliveryId]);
            $assignmentId = (int)$assignment->fetchColumn();
            if (!$assignmentId) DatabaseTransaction::fail('No current assignment record is available for this delivery.', 409);

            $db->prepare("UPDATE delivery_assignments SET assignment_status = 'released', is_current = 0, released_at = NOW(), release_reason = ? WHERE id = ?")->execute([$reason, $assignmentId]);
            $db->prepare("UPDATE deliveries SET delivery_person_id = NULL, status = 'broadcasted', status_reason = ? WHERE id = ?")->execute([$reason, $deliveryId]);
            $driverId = OperationsSchema::driverIdForUser($db, (int)$delivery['delivery_person_id']);
            if ($driverId) $db->prepare("UPDATE driver_availability SET availability_status = 'available', available_since = NOW() WHERE driver_id = ?")->execute([$driverId]);
            self::materializeEligibleOffers($db, $deliveryId, $adminId);

            return ['delivery' => $delivery, 'assignment_id' => $assignmentId];
        }, 3);

        $delivery = $assignmentData['delivery'];
        $assignmentId = $assignmentData['assignment_id'];

        OperationalRecords::statusTransition($db, $deliveryId, $delivery['status'], 'broadcasted', $adminId, 'admin', $reason, ['assignment_released' => true]);
        NotificationService::deliveryStatusChanged($db, $deliveryId, $delivery['status'], 'broadcasted');
        OperationalRecords::audit($db, $adminId, 'admin', 'delivery.assignment_released', 'delivery_assignment', $assignmentId, null, ['reason' => $reason], [], $deliveryId);
        NotificationService::publish($db, (int)$delivery['delivery_person_id'], 'driver.assignment_released', 'Assignment released', "Your assignment for #{$delivery['tracking_number']} was returned to operations: {$reason}", $deliveryId);
        NotificationService::publish($db, (int)$delivery['client_id'], 'client.assignment_reassigned', 'Delivery reassignment in progress', "Operations is arranging another verified partner for #{$delivery['tracking_number']}.", $deliveryId);
        Response::json(['delivery_id' => $deliveryId, 'status' => 'broadcasted'], 'Assignment released and returned to the eligible driver queue.');
    }

    /** Operations can manually lock an available verified partner to a dispatchable delivery. */
    public static function manualAssign(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_availability', 'delivery_assignments']);
        $data = json_decode(file_get_contents('php://input')); $deliveryId = (int)($data->delivery_id ?? 0); $driverUserId = (int)($data->driver_user_id ?? 0);
        if (!$deliveryId || !$driverUserId) Response::error('delivery_id and driver_user_id are required.');

        $delivery = DatabaseTransaction::run($db, function(PDO $db) use ($deliveryId, $driverUserId, $adminId) {
            $deliveryQuery = $db->prepare("SELECT id, client_id, status, tracking_number FROM deliveries WHERE id = ? FOR UPDATE"); 
            $deliveryQuery->execute([$deliveryId]); 
            $delivery = $deliveryQuery->fetch(PDO::FETCH_ASSOC);
            if (!$delivery) DatabaseTransaction::fail('Delivery not found.', 404);
            if (!in_array($delivery['status'], ['under_review', 'broadcasted'], true)) {
                DatabaseTransaction::fail('Only reviewed or broadcasted deliveries can be manually assigned.', 409);
            }

            $driverQuery = $db->prepare("SELECT d.id FROM drivers d JOIN users u ON u.id = d.user_id JOIN driver_availability a ON a.driver_id = d.id WHERE u.id = ? AND u.role = 'delivery' AND u.is_approved = 1 AND u.account_status = 'active' AND d.kyc_status = 'verified' AND d.active_status = 'active' AND a.availability_status = 'available' FOR UPDATE"); 
            $driverQuery->execute([$driverUserId]); 
            $driverId = (int)$driverQuery->fetchColumn();
            if (!$driverId) DatabaseTransaction::fail('The selected driver is not eligible and available for dispatch.', 422);

            $sequence = $db->prepare('SELECT COALESCE(MAX(assignment_sequence), 0) + 1 FROM delivery_assignments WHERE delivery_id = ?'); 
            $sequence->execute([$deliveryId]); 
            $next = (int)$sequence->fetchColumn();

            $db->prepare("INSERT INTO delivery_assignments (delivery_id, driver_id, assigned_by, assignment_sequence, assignment_status, is_current, assignment_method, accepted_at) VALUES (?, ?, ?, ?, 'locked', 1, 'manual', NOW())")->execute([$deliveryId, $driverId, $adminId, $next]);
            $db->prepare("UPDATE deliveries SET delivery_person_id = ?, status = 'assigned', status_reason = NULL WHERE id = ?")->execute([$driverUserId, $deliveryId]);
            $db->prepare("UPDATE delivery_driver_offers SET offer_status = 'withdrawn', responded_at = NOW(), response_reason = 'Manually assigned by operations' WHERE delivery_id = ? AND offer_status = 'offered'")->execute([$deliveryId]);
            $db->prepare("UPDATE driver_availability SET availability_status = 'busy', available_since = NULL WHERE driver_id = ?")->execute([$driverId]);

            return $delivery;
        }, 3);
        OperationalRecords::statusTransition($db, $deliveryId, $delivery['status'], 'assigned', $adminId, 'admin', null, ['assignment_method' => 'manual', 'driver_user_id' => $driverUserId]);
        NotificationService::assignmentAccepted($db, $deliveryId, $driverUserId);
        Response::json(['delivery_id' => $deliveryId, 'driver_user_id' => $driverUserId, 'status' => 'assigned'], 'Delivery manually assigned and locked.');
    }

    /** Paginated operational audit log; raw private states are intentionally not exposed. */
    public static function auditLog(PDO $db): void
    {
        OperationsSchema::requireTables($db, ['audit_logs']);
        $page = max(1, (int)($_GET['page'] ?? 1)); $limit = max(1, min(100, (int)($_GET['limit'] ?? 25))); $offset = ($page - 1) * $limit;
        $where = []; $params = [];
        foreach (['action', 'actor_role'] as $field) if (($value = trim($_GET[$field] ?? '')) !== '') { $where[] = "a.{$field}" . ($field === 'action' ? ' LIKE' : ' =') . " :{$field}"; $params[$field] = $field === 'action' ? "%{$value}%" : $value; }
        if (($deliveryId = (int)($_GET['delivery_id'] ?? 0)) > 0) { $where[] = 'a.delivery_id = :delivery_id'; $params['delivery_id'] = $deliveryId; }
        if (($from = trim($_GET['from'] ?? '')) !== '') { $where[] = 'a.created_at >= :from_date'; $params['from_date'] = $from . ' 00:00:00'; }
        if (($to = trim($_GET['to'] ?? '')) !== '') { $where[] = 'a.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)'; $params['to_date'] = $to; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $base = "SELECT a.id, a.action, a.entity_type, a.entity_id, a.delivery_id, a.actor_role, a.created_at, u.full_name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id {$whereSql} ORDER BY a.created_at DESC";
        if (($_GET['format'] ?? '') === 'csv') { $csv = $db->prepare($base . ' LIMIT 10000'); $csv->execute($params); header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="operations-audit.csv"'); $out = fopen('php://output', 'w'); fputcsv($out, ['Time', 'Action', 'Actor role', 'Actor', 'Record', 'Delivery']); foreach ($csv->fetchAll(PDO::FETCH_ASSOC) as $row) fputcsv($out, [$row['created_at'], $row['action'], $row['actor_role'], $row['actor_name'], $row['entity_type'] . ' #' . $row['entity_id'], $row['delivery_id']]); fclose($out); exit; }
        $count = $db->prepare("SELECT COUNT(*) FROM audit_logs a {$whereSql}"); $count->execute($params); $total = (int)$count->fetchColumn();
        $statement = $db->prepare($base . ' LIMIT :limit OFFSET :offset');
        foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT); $statement->bindValue(':offset', $offset, PDO::PARAM_INT); $statement->execute();
        Response::paginated($statement->fetchAll(PDO::FETCH_ASSOC), $total, $page, $limit, 'Operations audit records retrieved.');
    }

    /** Queue asynchronous audit export via background worker daemon */
    public static function queueAuditExport(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['audit_logs']);
        $data = json_decode(file_get_contents('php://input'), true) ?: [];

        $filters = [
            'action'      => trim((string)($data['action'] ?? '')),
            'actor_role'  => trim((string)($data['actor_role'] ?? '')),
            'delivery_id' => !empty($data['delivery_id']) ? (int)$data['delivery_id'] : null,
            'from_date'   => trim((string)($data['from_date'] ?? '')),
            'to_date'     => trim((string)($data['to_date'] ?? '')),
        ];

        require_once __DIR__ . '/../helpers/job_queue.php';
        JobQueue::setDb($db);
        $exportId = bin2hex(random_bytes(6));
        $success = JobQueue::push('audit.export', [
            'admin_id'  => $adminId,
            'export_id' => $exportId,
            'filters'   => array_filter($filters),
        ]);

        if (!$success) {
            Response::error('Failed to dispatch asynchronous export job.', 500);
        }

        Response::json([
            'export_id' => $exportId,
            'status'    => 'queued',
            'message'   => 'Audit export job queued successfully for background worker processing.'
        ], 'Audit log export queued.', 202);
    }

    /** Generate a manually requested delivery report; never used by live dashboards. */
    public static function deliveryReport(PDO $db): void
    {
        $where = [];
        $params = [];
        $status = trim((string)($_GET['status'] ?? ''));
        $from = trim((string)($_GET['from'] ?? ''));
        $to = trim((string)($_GET['to'] ?? ''));

        if ($status !== '') {
            $where[] = 'd.status = :status';
            $params['status'] = $status;
        }
        if ($from !== '') {
            $where[] = 'd.request_time >= :from_date';
            $params['from_date'] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 'd.request_time < DATE_ADD(:to_date, INTERVAL 1 DAY)';
            $params['to_date'] = $to;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $statement = $db->prepare("SELECT d.tracking_number, d.status, d.service_type,
                d.item_category, d.item_weight, d.total_cost, d.payment_status,
                d.request_time, d.pickup_time, d.delivery_time,
                client.full_name AS client_name, driver.full_name AS driver_name
            FROM deliveries d
            LEFT JOIN users client ON client.id = d.client_id
            LEFT JOIN users driver ON driver.id = d.delivery_person_id
            {$whereSql}
            ORDER BY d.request_time DESC
            LIMIT 10000");
        $statement->execute($params);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="delivery-report.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Tracking number', 'Status', 'Service type', 'Category', 'Weight kg', 'Total cost', 'Payment status', 'Requested', 'Picked up', 'Delivered', 'Client', 'Driver']);
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    /**
     * Set-based atomic batch broadcast to eligible KYC-verified available Kano partners.
     * Executes in a single O(1) query instead of O(N) iterative round-trips.
     */
    private static function materializeEligibleOffers(PDO $db, int $deliveryId, int $adminId): void
    {
        // Retrieve shipment cargo weight and pickup coordinates
        $deliveryStmt = $db->prepare('SELECT COALESCE(weight_kg, 0) AS weight_kg, pickup_latitude, pickup_longitude FROM deliveries WHERE id = ?');
        $deliveryStmt->execute([$deliveryId]);
        $deliveryData = $deliveryStmt->fetch(PDO::FETCH_ASSOC);
        $cargoWeight = (float)($deliveryData['weight_kg'] ?? 0);

        $pickupLat = isset($deliveryData['pickup_latitude']) && $deliveryData['pickup_latitude'] !== null ? (float)$deliveryData['pickup_latitude'] : null;
        $pickupLng = isset($deliveryData['pickup_longitude']) && $deliveryData['pickup_longitude'] !== null ? (float)$deliveryData['pickup_longitude'] : null;

        $orderClause = "";
        $params = [$deliveryId, $adminId, $cargoWeight];

        if ($pickupLat !== null && $pickupLng !== null) {
            // Proximity dispatch: order available drivers by spherical distance in meters via MySQL ST_Distance_Sphere
            $orderClause = " ORDER BY ST_Distance_Sphere(POINT(COALESCE(a.last_longitude, 8.5385), COALESCE(a.last_latitude, 12.0022)), POINT(?, ?)) ASC";
            $params[] = $pickupLng;
            $params[] = $pickupLat;
        }

        $sql = "INSERT INTO delivery_driver_offers (delivery_id, driver_id, offered_by, offer_status, expires_at)
                SELECT ?, d.id, ?, 'offered', DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                FROM drivers d
                JOIN users u ON u.id = d.user_id
                JOIN driver_availability a ON a.driver_id = d.id
                WHERE u.role = 'delivery'
                  AND u.is_approved = 1
                  AND u.account_status = 'active'
                  AND d.kyc_status = 'verified'
                  AND d.active_status = 'active'
                  AND a.availability_status = 'available'
                  AND (d.max_payload_kg IS NULL OR d.max_payload_kg = 0 OR d.max_payload_kg >= ?)
                {$orderClause}
                ON DUPLICATE KEY UPDATE
                  offered_by = VALUES(offered_by),
                  offer_status = IF(offer_status = 'declined', 'declined', 'offered'),
                  expires_at = IF(offer_status = 'declined', expires_at, DATE_ADD(NOW(), INTERVAL 15 MINUTE)),
                  responded_at = IF(offer_status = 'declined', responded_at, NULL),
                  response_reason = IF(offer_status = 'declined', response_reason, NULL)";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }

    private static function transitionDelivery(PDO $db, string $targetStatus, int $adminId, ?string $reason = null, ?int $providedDeliveryId = null): void
    {
        $deliveryId = $providedDeliveryId ?? 0;
        if (!$deliveryId) {
            $data = json_decode(file_get_contents('php://input'));
            $deliveryId = (int)($data->delivery_id ?? 0);
        }
        if (!$deliveryId) Response::error('Delivery ID is required.');
        $stmt = $db->prepare('SELECT id, status, tracking_number FROM deliveries WHERE id = ?');
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$delivery) Response::error('Delivery not found.', 404);
        if (!DeliveryStatusPolicy::adminTransition($delivery['status'], $targetStatus)) {
            Response::error("Admin cannot move a delivery from '{$delivery['status']}' to '{$targetStatus}'.", 409);
        }
        if (in_array($targetStatus, ['rejected', 'cancelled'], true) && trim((string)$reason) === '') {
            Response::error('A reason is required to reject or cancel a delivery.', 422);
        }
        $update = $db->prepare('UPDATE deliveries SET status = ?, status_reason = ? WHERE id = ?');
        $update->execute([$targetStatus, $reason ? trim($reason) : null, $deliveryId]);
        OperationalRecords::statusTransition($db, $deliveryId, $delivery['status'], $targetStatus, $adminId, 'admin', $reason);
        NotificationService::deliveryStatusChanged($db, $deliveryId, $delivery['status'], $targetStatus);
        Response::json(['delivery_id' => $deliveryId, 'tracking_number' => $delivery['tracking_number'], 'status' => $targetStatus], 'Delivery status updated to ' . DeliveryStatusPolicy::label($targetStatus) . '.');
    }
    /**
     * List all clients with KYC status submitted or under_review (pending admin review).
     */
    public static function getPendingClientKyc(PDO $db): void
    {
        OperationsSchema::requireTables($db, ['clients', 'client_kyc_documents']);
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = max(1, min(100, (int)($_GET['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;

        $total = (int)$db->query(
            "SELECT COUNT(*) FROM clients WHERE kyc_status IN ('submitted','under_review')"
        )->fetchColumn();

        $stmt = $db->prepare("
            SELECT c.id AS client_id, c.kyc_status, c.kyc_rejection_reason, c.updated_at,
                   u.id AS user_id, u.full_name, u.email, u.phone, u.created_at AS registered_at,
                   (SELECT COUNT(*) FROM client_kyc_documents ckd WHERE ckd.client_id = c.id) AS doc_count
            FROM clients c
            JOIN users u ON u.id = c.user_id
            WHERE c.kyc_status IN ('submitted','under_review')
            ORDER BY c.updated_at ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        Response::paginated($stmt->fetchAll(PDO::FETCH_ASSOC), $total, $page, $limit, 'Pending client KYC submissions retrieved.');
    }

    /**
     * Full KYC profile for one client — used by admin review panel.
     */
    public static function getClientKycDetails(PDO $db): void
    {
        OperationsSchema::requireTables($db, ['clients', 'client_kyc_documents']);
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) Response::error('user_id is required.');

        $stmt = $db->prepare("
            SELECT c.id AS client_id, c.client_type, c.kyc_status, c.kyc_rejection_reason,
                   c.kyc_reviewed_by, c.kyc_reviewed_at,
                   u.id AS user_id, u.full_name, u.email, u.phone, u.address, u.created_at
            FROM clients c
            JOIN users u ON u.id = c.user_id
            WHERE c.user_id = ? LIMIT 1
        ");
        $stmt->execute([$userId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$profile) Response::notFound('Client not found.');

        if ($profile['kyc_status'] === 'submitted') {
            $db->prepare("UPDATE clients SET kyc_status = 'under_review' WHERE id = ?")->execute([(int)$profile['client_id']]);
            $profile['kyc_status'] = 'under_review';
            NotificationService::publish($db, $userId, 'client.kyc_under_review', 'KYC review started', 'Operations has started reviewing your KYC submission.');
        }

        $docStmt = $db->prepare("
            SELECT id, document_type, document_number, expires_at,
                   verification_status, rejection_reason, created_at, reviewed_at
            FROM client_kyc_documents WHERE client_id = ? ORDER BY created_at DESC
        ");
        $docStmt->execute([(int)$profile['client_id']]);

        Response::json([
            'profile'   => $profile,
            'documents' => $docStmt->fetchAll(PDO::FETCH_ASSOC),
        ], 'Client KYC details retrieved.');
    }

    /**
     * Admin approves a client's KYC submission.
     */
    public static function approveClientKyc(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['clients', 'client_kyc_documents']);
        $data   = json_decode(file_get_contents('php://input'));
        $userId = (int)($data->user_id ?? 0);
        if (!$userId) Response::error('user_id is required.');

        $stmt = $db->prepare("SELECT id, kyc_status FROM clients WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) Response::notFound('Client not found.');
        if (!in_array($client['kyc_status'], ['submitted', 'under_review'], true)) Response::error('Only submitted client KYC records can be approved.', 409);

        $documents = $db->prepare("SELECT
                COUNT(*) AS total,
                SUM(verification_status = 'pending') AS pending,
                SUM(verification_status IN ('rejected', 'expired')) AS invalid,
                SUM(expires_at IS NOT NULL AND expires_at < UTC_DATE()) AS expired
            FROM client_kyc_documents
            WHERE client_id = ?");
        $documents->execute([(int)$client['id']]);
        $documentSummary = $documents->fetch(PDO::FETCH_ASSOC);
        if (!(int)$documentSummary['total']) Response::error('Upload at least one KYC document before approval.', 422);
        if (!(int)$documentSummary['pending']) Response::error('No pending KYC documents are available for approval.', 409);
        if ((int)$documentSummary['invalid'] || (int)$documentSummary['expired']) Response::error('Replace rejected or expired KYC documents before approval.', 422);

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE clients SET kyc_status = 'verified', kyc_rejection_reason = NULL, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE id = ?")->execute([$adminId, (int)$client['id']]);
            $db->prepare("UPDATE client_kyc_documents SET verification_status = 'verified', rejection_reason = NULL, reviewed_by = ?, reviewed_at = NOW() WHERE client_id = ? AND verification_status = 'pending' AND (expires_at IS NULL OR expires_at >= UTC_DATE())")->execute([$adminId, (int)$client['id']]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        OperationalRecords::audit($db, $adminId, 'admin', 'client.kyc_approved', 'client', (int)$client['id'], ['kyc_status' => $client['kyc_status']], ['kyc_status' => 'verified']);
        NotificationService::publish($db, $userId, 'client.kyc_approved',
            '✓ Account Verified',
            'Congratulations! Your identity has been verified. You can now request deliveries and access all client features.'
        );

        Response::json(['user_id' => $userId, 'kyc_status' => 'verified'], 'Client KYC approved successfully.');
    }

    /**
     * Admin rejects a client's KYC submission with a mandatory reason.
     */
    public static function rejectClientKyc(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['clients', 'client_kyc_documents']);
        $data   = json_decode(file_get_contents('php://input'));
        $userId = (int)($data->user_id ?? 0);
        $reason = trim((string)($data->reason ?? ''));
        if (!$userId) Response::error('user_id is required.');
        if ($reason === '') Response::error('A rejection reason is required.', 422);

        $stmt = $db->prepare("SELECT id, kyc_status FROM clients WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) Response::notFound('Client not found.');
        if (!in_array($client['kyc_status'], ['submitted', 'under_review'], true)) Response::error('Only submitted client KYC records can be rejected.', 409);

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE clients SET kyc_status = 'rejected', kyc_rejection_reason = ?, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE id = ?")->execute([$reason, $adminId, (int)$client['id']]);
            $db->prepare("UPDATE client_kyc_documents SET verification_status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE client_id = ? AND verification_status = 'pending'")->execute([$reason, $adminId, (int)$client['id']]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            throw $exception;
        }

        OperationalRecords::audit($db, $adminId, 'admin', 'client.kyc_rejected', 'client', (int)$client['id'], ['kyc_status' => $client['kyc_status']], ['kyc_status' => 'rejected', 'reason' => $reason]);
        NotificationService::publish($db, $userId, 'client.kyc_rejected',
            'KYC Verification: Action Required',
            'Your KYC submission requires attention: ' . $reason . '. Please update and resubmit your documents.'
        );

        Response::json(['user_id' => $userId, 'kyc_status' => 'rejected', 'reason' => $reason], 'Client KYC rejected.');
    }

    /**
     * Stream a client KYC document for admin review.
     */
    public static function adminClientDocumentFile(PDO $db, int $adminId): void
    {
        ClientKycController::getDocumentFileAdmin($db, $adminId);
    }
}
