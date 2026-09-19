<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/tracker_helper.php';
require_once __DIR__ . '/../helpers/kano_service_area.php';
require_once __DIR__ . '/../helpers/delivery_status_policy.php';
require_once __DIR__ . '/../helpers/operational_records.php';
require_once __DIR__ . '/../helpers/notification_service.php';
require_once __DIR__ . '/../helpers/operations_schema.php';
require_once __DIR__ . '/../helpers/auth_middleware.php';
require_once __DIR__ . '/../helpers/database_transaction.php';
require_once __DIR__ . '/../helpers/spatial_helper.php';

class DeliveryController
{
    /**
     * Calculate upfront transparent price for Kano State delivery.
     * Automatically derives distance from coordinates/landmarks and weight from package format.
     */
    public static function calculatePrice(PDO $db): void
    {
        $data = json_decode(file_get_contents("php://input"));

        KanoServiceArea::assertCity($data->pickup_city ?? KanoServiceArea::city(), 'Pickup city');
        KanoServiceArea::assertCity($data->delivery_city ?? KanoServiceArea::city(), 'Delivery city');

        $distance = self::resolveDistance($data);
        $weight = self::resolveWeight($data);
        $quantity = max(1, (int)($data->item_quantity ?? $data->quantity ?? 1));
        $isFragile = !empty($data->is_fragile);
        $isPerishable = !empty($data->is_perishable);

        $serviceType = self::serviceType($data->service_type ?? 'same_day');
        [$pricing, $rateCardId] = self::rateCardPricing($db, $serviceType);
        $quote = self::pricingBreakdown(
            $pricing,
            $distance,
            $weight,
            $isFragile,
            $isPerishable,
            $quantity
        );

        Response::json([
            ...$quote,
            'distance_km' => $distance,
            'weight_kg' => $weight,
            'quantity' => $quantity,
            'currency' => 'NGN',
            'currency_symbol' => '₦',
            'service_type' => $serviceType,
            'rate_card_id' => $rateCardId,
        ], 'Fare calculated successfully.');
    }

    /**
     * Client creates new delivery request within Kano State
     */
    public static function createDelivery(PDO $db, int $clientId): void
    {
        // Server-side KYC gate — enforced independently of any frontend restriction.
        // A client MUST be KYC-verified before creating a delivery request.
        AuthMiddleware::requireClientKyc($db, $clientId);

        $data = json_decode(file_get_contents("php://input"));

        $required = [
            'pickup_address', 'pickup_contact_name', 'pickup_contact_phone',
            'delivery_address', 'delivery_contact_name', 'delivery_contact_phone',
            'item_description'
        ];

        foreach ($required as $field) {
            if (empty($data->$field)) {
                Response::error("The field '{$field}' is required.");
            }
        }

        $pickupAddress = trim((string)$data->pickup_address);
        $deliveryAddress = trim((string)$data->delivery_address);

        KanoServiceArea::assertDelivery(
            $data->pickup_city ?? KanoServiceArea::city(),
            $data->delivery_city ?? KanoServiceArea::city(),
            $pickupAddress,
            $deliveryAddress
        );

        $data->pickup_address = $pickupAddress;
        $data->delivery_address = $deliveryAddress;

        // Auto-resolve distance, weight, and geographic coordinates
        $distance = self::resolveDistance($data);
        $weight = self::resolveWeight($data);
        $quantity = max(1, (int)($data->item_quantity ?? $data->quantity ?? 1));
        $isFragile = !empty($data->is_fragile) ? 1 : 0;
        $isPerishable = !empty($data->is_perishable) ? 1 : 0;
        $itemCategory = !empty($data->item_category) ? trim((string)$data->item_category) : (!empty($data->package_size) ? trim((string)$data->package_size) : 'general');
        [$pickupLat, $pickupLng, $deliveryLat, $deliveryLng] = self::resolveCoordinates($data);

        $serviceType = self::serviceType($data->service_type ?? 'same_day');
        // Recalculate on the server. Browser-provided quote fields are never trusted.
        [$pricing, $rateCardId] = self::rateCardPricing($db, $serviceType);
        $quote = self::pricingBreakdown(
            $pricing,
            $distance,
            $weight,
            (bool)$isFragile,
            (bool)$isPerishable,
            $quantity
        );

        $otp = TrackerHelper::generateOTP();
        $publicToken = TrackerHelper::generatePublicTrackingToken();
        $officialTracking = TrackerHelper::generateTrackingNumber();

        $stmt = $db->prepare("INSERT INTO deliveries (
            tracking_number, public_tracking_token, client_id, pickup_address, pickup_city, pickup_contact_name, pickup_contact_phone,
            delivery_address, delivery_city, delivery_contact_name, delivery_contact_phone, service_type,
            item_description, item_category, item_quantity, item_weight, item_dimensions,
            preferred_pickup_time, preferred_delivery_time, special_instructions,
            is_fragile, is_perishable, distance_km, base_cost, weight_charge, fragile_charge, total_cost,
            status, delivery_otp, payment_status,
            pickup_latitude, pickup_longitude, delivery_latitude, delivery_longitude
        ) VALUES (
            ?, ?, ?, ?, 'Kano', ?, ?,
            ?, 'Kano', ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            'pending', ?, 'unpaid',
            ?, ?, ?, ?
        )");

        $stmt->execute([
            $officialTracking,
            $publicToken,
            $clientId,
            trim($data->pickup_address),
            trim($data->pickup_contact_name),
            trim($data->pickup_contact_phone),
            trim($data->delivery_address),
            trim($data->delivery_contact_name),
            trim($data->delivery_contact_phone),
            $serviceType,
            trim($data->item_description),
            $itemCategory,
            $quantity,
            $weight,
            $data->item_dimensions ?? '',
            $data->preferred_pickup_time ?? null,
            $data->preferred_delivery_time ?? null,
            $data->special_instructions ?? '',
            $isFragile,
            $isPerishable,
            $distance,
            $quote['base_cost'] + $quote['distance_charge'],
            $quote['weight_charge'],
            $quote['fragile_charge'],
            $quote['total_cost'],
            $otp,
            $pickupLat,
            $pickupLng,
            $deliveryLat,
            $deliveryLng
        ]);

        $deliveryId = (int)$db->lastInsertId();
        if ($rateCardId !== null) {
            $priceQuote = $db->prepare("INSERT INTO delivery_price_quotes (delivery_id, rate_card_id, quote_status, distance_km, weight_kg, breakdown, total_amount, calculated_by) VALUES (?, ?, 'accepted', ?, ?, ?, ?, ?)");
            $priceQuote->execute([$deliveryId, $rateCardId, $distance, $weight, json_encode($quote, JSON_THROW_ON_ERROR), $quote['total_cost'], $clientId]);
        }

        OperationalRecords::statusTransition($db, $deliveryId, null, 'pending', $clientId, 'client', null, [
            'tracking_number' => $officialTracking,
            'service_type' => $serviceType,
        ]);
        NotificationService::deliveryCreated($db, $deliveryId);

        Response::json([
            'delivery_id'          => $deliveryId,
            'tracking_number'      => $officialTracking,
            'public_tracking_token'=> $publicToken,
            'status'               => 'pending',
            'total_cost'           => $quote['total_cost'],
            'delivery_otp'         => $otp, // Returned to client so recipient can confirm on delivery
            'message'              => 'Delivery request submitted. Awaiting central operations review and dispatch.'
        ], 'Delivery request created successfully.', 201);
    }

    /**
     * Get list of deliveries booked by client (with pagination and filter support).
     *
     * Supports two pagination modes:
     *   1. Traditional offset: ?page=2&limit=10
     *   2. Keyset cursor:      ?cursor=<last_id>&limit=10  (faster at scale)
     */
    public static function getClientDeliveries(PDO $db, int $clientId): void
    {
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
        $status = trim($_GET['status'] ?? 'all');
        $cursor = isset($_GET['cursor']) ? (int)$_GET['cursor'] : null;

        $whereClause = "WHERE d.client_id = :client_id";
        $params = ['client_id' => $clientId];

        if ($status !== 'all' && !empty($status)) {
            $whereClause .= " AND d.status = :status";
            $params['status'] = $status;
        }

        // Selective column projection — omit heavy text fields on list views
        $columns = "d.id, d.tracking_number, d.status, d.service_type,
                    d.pickup_address, d.pickup_city, d.delivery_address, d.delivery_city,
                    d.pickup_contact_name, d.delivery_contact_name,
                    d.item_description, d.item_category, d.item_quantity, d.item_weight,
                    d.is_fragile, d.is_perishable, d.distance_km,
                    d.base_cost, d.weight_charge, d.fragile_charge, d.total_cost,
                    d.payment_status, d.request_time, d.pickup_time, d.delivery_time,
                    d.delivery_otp, d.public_tracking_token,
                    u.full_name as delivery_person_name,
                    u.phone as delivery_person_phone";

        // Keyset (cursor) pagination — O(1) index seek regardless of depth
        if ($cursor !== null) {
            $whereClause .= " AND d.id < :cursor";
            $params['cursor'] = $cursor;

            $sql = "SELECT {$columns}
                    FROM deliveries d
                    LEFT JOIN users u ON d.delivery_person_id = u.id
                    {$whereClause}
                    ORDER BY d.id DESC
                    LIMIT :limit";

            $stmt = $db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue(":{$key}", $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $nextCursor = !empty($deliveries) ? (int)end($deliveries)['id'] : null;

            Response::json([
                'deliveries'  => $deliveries,
                'next_cursor' => $nextCursor,
                'has_more'    => count($deliveries) === $limit,
            ], 'Client deliveries retrieved.');
            return;
        }

        // Traditional offset pagination (backward compatible)
        $page = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $countStmt = $db->prepare("SELECT COUNT(*) FROM deliveries d {$whereClause}");
        foreach ($params as $key => $val) {
            $countStmt->bindValue(":{$key}", $val);
        }
        $countStmt->execute();
        $totalCount = (int)$countStmt->fetchColumn();

        $sql = "SELECT {$columns}
                FROM deliveries d
                LEFT JOIN users u ON d.delivery_person_id = u.id
                {$whereClause}
                ORDER BY d.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":{$key}", $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::paginated($deliveries, $totalCount, $page, $limit, 'Client deliveries retrieved.');
    }

    /**
     * Public shipment tracking lookup.
     *
     * Accepts ONLY:
     *   - High-entropy tracking number e.g. TIL-2026-X8K9-M4PQ (or legacy TIL-2026-00042)
     *   - 32-char public_tracking_token (hex, never sequential)
     *
     * Bare numeric IDs and order numbers (#1, 100) are explicitly rejected with 400 Bad Request
     * to eliminate sequential ID enumeration attacks.
     */
    public static function trackDelivery(PDO $db): void
    {
        $queryParam = $_GET['id'] ?? $_GET['ref'] ?? $_GET['tracking_number'] ?? $_GET['token'] ?? null;

        if (empty($queryParam)) {
            Response::error('Please provide a tracking number or tracking token.', 400);
        }

        $rawQuery = trim((string)$queryParam);
        $ref = TrackerHelper::parseReference($rawQuery);

        // Reject bare integer inputs and hash-prefixed order numbers (#1, 100) immediately
        if (ctype_digit($ref) || preg_match('/^#?\d+$/', $rawQuery)) {
            Response::error('Direct numeric order IDs cannot be used for public tracking. Please provide your secure tracking number (e.g. TIL-2026-X8K9-M4PQ) or 32-character tracking token.', 400);
        }

        // Determine whether ref is a public_tracking_token (32 hex chars) or a tracking_number
        $isToken = (bool) preg_match('/^[0-9a-f]{32}$/', $ref);
        $isTrackingNumber = (bool) preg_match('/^TIL-\d{4}-[A-Z0-9]+(-[A-Z0-9]+)?$/i', $ref);

        if (!$isToken && !$isTrackingNumber) {
            Response::error('Invalid tracking reference format. Please provide a valid tracking number (e.g. TIL-2026-X8K9-M4PQ) or your 32-character tracking token.', 400);
        }

        if ($isToken) {
            $whereClause = 'd.public_tracking_token = ?';
        } else {
            $whereClause = 'd.tracking_number = ?';
        }

        $stmt = $db->prepare("SELECT d.*,
                              u1.full_name as client_name,
                              u2.full_name as delivery_person_name,
                              u2.phone as delivery_person_phone
                              FROM deliveries d
                              LEFT JOIN users u1 ON d.client_id = u1.id
                              LEFT JOIN users u2 ON d.delivery_person_id = u2.id
                              WHERE {$whereClause}
                              AND d.pickup_city = 'Kano' AND d.delivery_city = 'Kano'");
        $stmt->execute([$ref]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            Response::error('No shipment record found for the provided reference number.', 404);
        }

        // Always strip internal OTP and raw GPS coordinates from tracking lookup
        unset($delivery['delivery_otp']);
        unset($delivery['last_location_latitude'], $delivery['last_location_longitude'], $delivery['last_location_accuracy_m']);

        // Check if the viewer is an authorized stakeholder (client owner, assigned driver, or admin)
        $isAuthorizedParty = false;
        $currentUser = AuthMiddleware::getAuthenticatedUser($db);
        if ($currentUser !== null) {
            if ($currentUser['role'] === 'admin') {
                $isAuthorizedParty = true;
            } elseif ($currentUser['role'] === 'client' && (int)$currentUser['id'] === (int)$delivery['client_id']) {
                $isAuthorizedParty = true;
            } elseif ($currentUser['role'] === 'delivery' && (int)$currentUser['id'] === (int)($delivery['delivery_person_id'] ?? 0)) {
                $isAuthorizedParty = true;
            }
        }

        // Retrieve stage transition timestamps from history or operational assignments
        $deliveryId = (int)($delivery['id'] ?? 0);
        $statusTimestamps = [];
        if ($deliveryId > 0) {
            try {
                $histStmt = $db->prepare("SELECT to_status, created_at FROM delivery_status_history WHERE delivery_id = ? ORDER BY id ASC");
                $histStmt->execute([$deliveryId]);
                while ($histRow = $histStmt->fetch(PDO::FETCH_ASSOC)) {
                    $statusTimestamps[$histRow['to_status']] = $histRow['created_at'];
                }
            } catch (Throwable $_) {}
        }

        $assignedAt = null;
        if ($deliveryId > 0) {
            try {
                $assignStmt = $db->prepare("SELECT assigned_at, accepted_at FROM delivery_assignments WHERE delivery_id = ? AND is_current = 1 LIMIT 1");
                $assignStmt->execute([$deliveryId]);
                if ($assignRow = $assignStmt->fetch(PDO::FETCH_ASSOC)) {
                    $assignedAt = $assignRow['accepted_at'] ?: $assignRow['assigned_at'];
                }
            } catch (Throwable $_) {}
        }

        $assignedTime = $assignedAt ?: ($statusTimestamps['assigned'] ?? ($statusTimestamps['driver_en_route'] ?? null));
        $pickedUpTime = $delivery['pickup_time'] ?: ($statusTimestamps['picked_up'] ?? null);
        $inTransitTime = $delivery['tracking_started_at'] ?: ($statusTimestamps['in_transit'] ?? null);
        $deliveredTime = $delivery['delivery_time'] ?: ($statusTimestamps['delivered'] ?? ($statusTimestamps['completed'] ?? null));

        $delivery['assigned_at'] = $assignedTime;
        $delivery['picked_up_at'] = $pickedUpTime;
        $delivery['in_transit_at'] = $inTransitTime;
        $delivery['delivered_at'] = $deliveredTime;

        // For public / anonymous tracking queries, mask PII and omit internal financial and sequential ID data
        if (!$isAuthorizedParty) {
            unset(
                $delivery['id'],
                $delivery['client_id'],
                $delivery['delivery_person_id'],
                $delivery['pickup_hub_id'],
                $delivery['delivery_hub_id'],
                $delivery['business_account_id'],
                $delivery['base_cost'],
                $delivery['weight_charge'],
                $delivery['fragile_charge'],
                $delivery['total_cost'],
                $delivery['payment_status'],
                $delivery['special_instructions'],
                $delivery['pickup_latitude'],
                $delivery['pickup_longitude'],
                $delivery['delivery_latitude'],
                $delivery['delivery_longitude']
            );

            // Apply PII masking
            $delivery['client_name'] = TrackerHelper::maskName($delivery['client_name'] ?? '');
            $delivery['pickup_contact_name'] = TrackerHelper::maskName($delivery['pickup_contact_name'] ?? '');
            $delivery['pickup_contact_phone'] = TrackerHelper::maskPhone($delivery['pickup_contact_phone'] ?? '');
            $delivery['pickup_address'] = TrackerHelper::maskAddress($delivery['pickup_address'] ?? '');
            $delivery['delivery_contact_name'] = TrackerHelper::maskName($delivery['delivery_contact_name'] ?? '');
            $delivery['delivery_contact_phone'] = TrackerHelper::maskPhone($delivery['delivery_contact_phone'] ?? '');
            $delivery['delivery_address'] = TrackerHelper::maskAddress($delivery['delivery_address'] ?? '');
            $delivery['delivery_person_name'] = !empty($delivery['delivery_person_name']) ? TrackerHelper::maskName($delivery['delivery_person_name']) : null;
            $delivery['delivery_person_phone'] = !empty($delivery['delivery_person_phone']) ? TrackerHelper::maskPhone($delivery['delivery_person_phone']) : null;
            $delivery['is_verified_viewer'] = false;
        } else {
            $delivery['is_verified_viewer'] = true;
        }

        // Milestones
        $statusOrder = ['pending', 'under_review', 'broadcasted', 'assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived', 'delivered', 'completed'];
        $currentStage = array_search($delivery['status'], $statusOrder, true);
        if ($currentStage === false) {
            $currentStage = 0;
        }

        $delivery['milestones'] = [
            ['stage' => 'Order Received', 'completed' => $currentStage >= 0, 'time' => $delivery['request_time']],
            ['stage' => 'Driver Assigned', 'completed' => $currentStage >= 3, 'time' => $assignedTime],
            ['stage' => 'Picked Up', 'completed' => $currentStage >= 5, 'time' => $pickedUpTime],
            ['stage' => 'In Transit', 'completed' => $currentStage >= 6, 'time' => $inTransitTime],
            ['stage' => 'Delivered & Confirmed', 'completed' => $currentStage >= 8, 'time' => $deliveredTime],
        ];

        Response::json($delivery, 'Shipment tracking information retrieved.');
    }

    /**
     * Recipient or Driver submits OTP to confirm delivery and trigger settlement
     */
    public static function confirmReceipt(PDO $db, int $driverId): void
    {
        AuthMiddleware::requireDriverKyc($db, $driverId);
        $data = json_decode(file_get_contents("php://input"));

        if (empty($data->delivery_id) || empty($data->otp)) {
            Response::error('Both delivery_id and otp code are required.');
        }

        $deliveryId = (int)$data->delivery_id;
        $otp = trim((string)$data->otp);

        $stmt = $db->prepare("SELECT * FROM deliveries WHERE id = ?");
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            Response::error('Delivery record not found.', 404);
        }

        if (empty($delivery['delivery_person_id']) || (int)$delivery['delivery_person_id'] !== $driverId) {
            Response::forbidden('Only the delivery partner assigned to this shipment can record confirmation.');
        }

        if ($delivery['status'] !== 'arrived') {
            Response::error('Delivery confirmation is available only after an assigned driver has arrived at the destination.', 409);
        }

        if (in_array($delivery['status'], ['delivered', 'completed', 'cancelled', 'rejected', 'failed'], true)) {
            Response::error("Delivery is already in '{$delivery['status']}' state.", 400);
        }

        // 1. Check if OTP confirmation is temporarily locked due to brute-force protection
        if (!empty($delivery['otp_locked_until']) && strtotime($delivery['otp_locked_until']) > time()) {
            $remainingSeconds = strtotime($delivery['otp_locked_until']) - time();
            $remainingMinutes = (int)ceil($remainingSeconds / 60);
            Response::error("OTP confirmation is temporarily locked due to too many failed attempts. Please try again in {$remainingMinutes} minute(s) or contact central operations.", 429);
        }

        // 2. Validate confirmation OTP with attempt counting and lockout
        if ($delivery['delivery_otp'] !== $otp) {
            $failedAttempts = ((int)($delivery['otp_failed_attempts'] ?? 0)) + 1;
            $maxAttempts = 5;

            if ($failedAttempts >= $maxAttempts) {
                // Lock OTP confirmation for 30 minutes
                $db->prepare("UPDATE deliveries SET otp_failed_attempts = ?, otp_locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?")
                   ->execute([$failedAttempts, $deliveryId]);

                OperationalRecords::audit($db, $driverId, 'delivery', 'delivery.otp_lockout_triggered', 'delivery', $deliveryId, null, [
                    'failed_attempts' => $failedAttempts,
                    'locked_for_minutes' => 30
                ], [], $deliveryId);

                NotificationService::publishToRole($db, 'admin', 'admin.delivery_otp_locked', 'Security Alert: Delivery OTP Locked', "Too many failed confirmation OTP attempts on delivery #{$delivery['tracking_number']}. Confirmation locked for 30 minutes.", $deliveryId);

                Response::error('Too many incorrect OTP attempts. Delivery confirmation has been locked for 30 minutes. Please contact central operations support.', 429);
            } else {
                $db->prepare("UPDATE deliveries SET otp_failed_attempts = ? WHERE id = ?")
                   ->execute([$failedAttempts, $deliveryId]);

                $remaining = $maxAttempts - $failedAttempts;
                Response::error("Invalid 6-digit confirmation OTP code. {$remaining} attempt(s) remaining before temporary lockout.", 400);
            }
        }

        // 3. OTP verified successfully: Reset failed attempts & complete delivery with wallet disbursement
        DatabaseTransaction::run($db, function(PDO $db) use ($deliveryId, $driverId, $delivery) {
            // Lock the delivery row for settlement
            $lockStmt = $db->prepare("SELECT id, status, delivery_person_id, total_cost FROM deliveries WHERE id = ? FOR UPDATE");
            $lockStmt->execute([$deliveryId]);
            $currentDelivery = $lockStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentDelivery) {
                DatabaseTransaction::fail('Delivery record not found.', 404);
            }

            if ($currentDelivery['status'] !== 'arrived' || (int)$currentDelivery['delivery_person_id'] !== $driverId) {
                DatabaseTransaction::fail('Delivery state or assigned driver changed before confirmation.', 409);
            }

            $update = $db->prepare("UPDATE deliveries SET 
                status = 'delivered',
                payment_status = 'paid',
                delivery_time = NOW(),
                otp_failed_attempts = 0,
                otp_locked_until = NULL
                WHERE id = ? AND status = 'arrived' AND delivery_person_id = ?");
            $update->execute([$deliveryId, $driverId]);
            if ($update->rowCount() !== 1) {
                DatabaseTransaction::fail('Delivery could not be confirmed because its state changed.', 409);
            }

            OperationalRecords::statusTransition($db, $deliveryId, 'arrived', 'delivered', $driverId, 'delivery');
            OperationalRecords::otpProofCaptured($db, $deliveryId, $driverId);
            self::releaseDriverAvailabilityIfIdle($db, $driverId);

            // Calculate and record driver earnings if driver assigned (wallet disbursement)
            if (!empty($currentDelivery['delivery_person_id'])) {
                $pricing = self::pricingConfig();
                $driverEarning = round((float)$currentDelivery['total_cost'] * (float)$pricing['driver_commission_pct'], 2);
                
                // Check if already recorded with pessimistic lock
                $earnCheck = $db->prepare("SELECT id FROM delivery_earnings WHERE delivery_id = ? FOR UPDATE");
                $earnCheck->execute([$deliveryId]);
                if (!$earnCheck->fetch()) {
                    $earnInsert = $db->prepare("INSERT INTO delivery_earnings (delivery_person_id, delivery_id, amount, earning_type, status, paid_at) VALUES (?, ?, ?, 'delivery_fee', 'paid', NOW())");
                    $earnInsert->execute([
                        $currentDelivery['delivery_person_id'],
                        $deliveryId,
                        $driverEarning
                    ]);
                }
            }
        }, 3);

        // Post-transaction notifications and response
        NotificationService::deliveryStatusChanged($db, $deliveryId, 'arrived', 'delivered');

        Response::json([
            'delivery_id' => $deliveryId,
            'tracking_number' => $delivery['tracking_number'],
            'status' => 'delivered',
            'payment_status' => 'paid',
            'delivery_time' => date('Y-m-d H:i:s')
        ], 'Delivery confirmed successfully with digital proof of receipt.');
    }

    /** Load the one authoritative set of backend pricing rules. */
    private static function pricingConfig(): array
    {
        $config = file_exists(__DIR__ . '/../config/config.php') ? require __DIR__ . '/../config/config.php' : [];
        return ($config['pricing'] ?? []) + [
            'base_fare' => 500.00,
            'base_km' => 5.0,
            'per_km_rate' => 50.00,
            'base_weight_kg' => 3.0,
            'per_kg_rate' => 50.00,
            'fragile_surcharge' => 100.00,
            'perishable_surcharge' => 100.00,
            'driver_commission_pct' => 0.65,
        ];
    }

    /** Select the effective Kano rate card, while retaining a safe config fallback during migration. */
    private static function rateCardPricing(PDO $db, string $serviceType): array
    {
        $pricing = self::pricingConfig();
        try {
            $card = $db->prepare("SELECT id FROM rate_cards WHERE city = 'Kano' AND service_type = ? AND business_account_id IS NULL AND is_active = 1 AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to > NOW()) ORDER BY effective_from DESC, id DESC LIMIT 1");
            $card->execute([$serviceType]);
            $cardId = $card->fetchColumn();
            if (!$cardId) return [$pricing, null];
            $rules = $db->prepare('SELECT rule_code, amount, threshold_value FROM rate_rules WHERE rate_card_id = ?');
            $rules->execute([$cardId]);
            $values = [];
            foreach ($rules->fetchAll(PDO::FETCH_ASSOC) as $rule) $values[$rule['rule_code']] = $rule;
            $number = static fn (string $code, float $fallback): float => isset($values[$code]) ? (float)$values[$code]['amount'] : $fallback;
            $threshold = static fn (string $code, float $fallback): float => isset($values[$code]) && $values[$code]['threshold_value'] !== null ? (float)$values[$code]['threshold_value'] : (isset($values[$code]) ? (float)$values[$code]['amount'] : $fallback);
            $pricing['base_fare'] = $number('base_fare', (float)$pricing['base_fare']);
            $pricing['base_km'] = $threshold('included_distance_km', (float)$pricing['base_km']);
            $pricing['per_km_rate'] = $number('distance_per_km', (float)$pricing['per_km_rate']);
            $pricing['base_weight_kg'] = $threshold('included_weight_kg', (float)$pricing['base_weight_kg']);
            $pricing['per_kg_rate'] = $number('weight_per_kg', (float)$pricing['per_kg_rate']);
            $pricing['fragile_surcharge'] = $number('fragile_surcharge', (float)$pricing['fragile_surcharge']);
            $pricing['perishable_surcharge'] = $number('perishable_surcharge', (float)$pricing['perishable_surcharge']);
            $pricing['service_surcharge'] = $serviceType === 'same_day' ? $number('same_day_surcharge', 0) : ($serviceType === 'scheduled' ? $number('scheduled_surcharge', 0) : 0);
            return [$pricing, (int)$cardId];
        } catch (Throwable $exception) {
            return [$pricing, null];
        }
    }

    private static function serviceType(mixed $serviceType): string
    {
        if (!in_array($serviceType, ['same_day', 'scheduled', 'business'], true)) Response::error('service_type must be same_day, scheduled, or business.', 422);
        return $serviceType;
    }

    private static function releaseDriverAvailabilityIfIdle(PDO $db, int $userId): void
    {
        if (!OperationsSchema::hasTable($db, 'drivers') || !OperationsSchema::hasTable($db, 'driver_availability')) return;
        $active = $db->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_person_id = ? AND status IN ('assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived')");
        $active->execute([$userId]);
        if ((int)$active->fetchColumn() !== 0) return;
        $driverId = OperationsSchema::driverIdForUser($db, $userId);
        if ($driverId) $db->prepare("UPDATE driver_availability SET availability_status = 'available', available_since = NOW() WHERE driver_id = ? AND availability_status = 'busy'")->execute([$driverId]);
    }

    /** Calculate a server-side quote shared by estimation and delivery creation. */
    private static function pricingBreakdown(
        array $pricing,
        float $distance,
        float $weight,
        bool $isFragile,
        bool $isPerishable,
        int $quantity = 1
    ): array {
        $baseCost = (float)$pricing['base_fare'];
        $distanceCharge = $distance > (float)$pricing['base_km']
            ? ($distance - (float)$pricing['base_km']) * (float)$pricing['per_km_rate']
            : 0.0;
        $weightCharge = $weight > (float)$pricing['base_weight_kg']
            ? ($weight - (float)$pricing['base_weight_kg']) * (float)$pricing['per_kg_rate']
            : 0.0;
        $fragileCharge = $isFragile ? (float)$pricing['fragile_surcharge'] : 0.0;
        $perishableCharge = $isPerishable ? (float)$pricing['perishable_surcharge'] : 0.0;
        $serviceCharge = (float)($pricing['service_surcharge'] ?? 0);
        $quantityCharge = ($quantity > 1) ? ($quantity - 1) * 150.0 : 0.0;

        $total = $baseCost + $distanceCharge + $weightCharge + $fragileCharge + $perishableCharge + $serviceCharge + $quantityCharge;

        return [
            'base_cost' => round($baseCost, 2),
            'distance_charge' => round($distanceCharge, 2),
            'weight_charge' => round($weightCharge, 2),
            'fragile_charge' => round($fragileCharge, 2),
            'perishable_charge' => round($perishableCharge, 2),
            'service_charge' => round($serviceCharge, 2),
            'quantity_charge' => round($quantityCharge, 2),
            'total_cost' => round($total, 2),
        ];
    }

    /**
     * Resolves geographic coordinates from payload or address landmark recognition.
     */
    public static function resolveCoordinates(mixed $data): array
    {
        $pLat = isset($data->pickup_latitude) ? (float)$data->pickup_latitude : (isset($data->pickup_lat) ? (float)$data->pickup_lat : null);
        $pLng = isset($data->pickup_longitude) ? (float)$data->pickup_longitude : (isset($data->pickup_lng) ? (float)$data->pickup_lng : null);
        $dLat = isset($data->delivery_latitude) ? (float)$data->delivery_latitude : (isset($data->delivery_lat) ? (float)$data->delivery_lat : null);
        $dLng = isset($data->delivery_longitude) ? (float)$data->delivery_longitude : (isset($data->delivery_lng) ? (float)$data->delivery_lng : null);

        $hubCoords = [
            'sabon gari'   => ['lat' => 12.0022, 'lng' => 8.5385],
            'kantin kwari' => ['lat' => 11.9961, 'lng' => 8.5274],
            'farm centre'  => ['lat' => 11.9752, 'lng' => 8.5492],
            'challawa'     => ['lat' => 11.8954, 'lng' => 8.4821],
            'bompai'       => ['lat' => 12.0150, 'lng' => 8.5550],
            'buk'          => ['lat' => 11.9780, 'lng' => 8.4230],
            'sharada'      => ['lat' => 11.9650, 'lng' => 8.4950],
            'dawanau'      => ['lat' => 12.0850, 'lng' => 8.4450],
            'nasarawa'     => ['lat' => 11.9890, 'lng' => 8.5520],
            'hotoro'       => ['lat' => 11.9610, 'lng' => 8.5830],
            'trade fair'   => ['lat' => 11.9950, 'lng' => 8.5450],
            'zoo road'     => ['lat' => 11.9730, 'lng' => 8.5250],
            'tarauni'      => ['lat' => 11.9680, 'lng' => 8.5420],
            'fagge'        => ['lat' => 12.0100, 'lng' => 8.5250],
        ];

        $pAddr = strtolower((string)($data->pickup_address ?? ''));
        $dAddr = strtolower((string)($data->delivery_address ?? ''));

        if (($pLat === null || $pLat == 0) && $pAddr !== '') {
            foreach ($hubCoords as $key => $coords) {
                if (str_contains($pAddr, $key)) {
                    $pLat = $coords['lat'];
                    $pLng = $coords['lng'];
                    break;
                }
            }
        }

        if (($dLat === null || $dLat == 0) && $dAddr !== '') {
            foreach ($hubCoords as $key => $coords) {
                if (str_contains($dAddr, $key)) {
                    $dLat = $coords['lat'];
                    $dLng = $coords['lng'];
                    break;
                }
            }
        }

        return [$pLat, $pLng, $dLat, $dLng];
    }

    /**
     * Resolves trip distance automatically from coordinates or fallback address logic.
     */
    public static function resolveDistance(mixed $data): float
    {
        $explicitDist = isset($data->distance_km) ? (float)$data->distance_km : 0.0;

        [$pLat, $pLng, $dLat, $dLng] = self::resolveCoordinates($data);

        if ($pLat !== null && $pLng !== null && $dLat !== null && $dLng !== null && $pLat > 0 && $dLat > 0) {
            $meters = SpatialHelper::haversineDistanceMeters($pLat, $pLng, $dLat, $dLng);
            $km = ($meters * 1.25) / 1000.0;
            return max(1.5, round($km, 1));
        }

        if ($explicitDist > 0) {
            return max(0.5, $explicitDist);
        }

        return 6.5;
    }

    /**
     * Resolves package weight in kg without forcing client input.
     */
    public static function resolveWeight(mixed $data): float
    {
        if (!empty($data->weight_kg) && (float)$data->weight_kg > 0) {
            return max(0.1, (float)$data->weight_kg);
        }
        if (!empty($data->item_weight) && (float)$data->item_weight > 0) {
            return max(0.1, (float)$data->item_weight);
        }

        $size = strtolower(trim((string)($data->package_size ?? $data->item_category ?? '')));
        if ($size === 'envelope' || str_contains($size, 'envelope') || str_contains($size, 'doc')) {
            return 0.5;
        }
        if ($size === 'large_package' || $size === 'heavy_cargo' || str_contains($size, 'large') || str_contains($size, 'heavy')) {
            return 10.0;
        }

        return 2.5;
    }
}
