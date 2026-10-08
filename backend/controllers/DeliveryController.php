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
require_once __DIR__ . '/../helpers/cursor_pagination.php';
require_once __DIR__ . '/../helpers/delivery_booking.php';
require_once __DIR__ . '/../helpers/booking_input.php';
require_once __DIR__ . '/../helpers/booking_quote_inputs.php';
require_once __DIR__ . '/../helpers/listing_query.php';

class DeliveryController
{
    /**
     * Calculate upfront transparent price for Kano State delivery.
     * Automatically derives distance from coordinates/landmarks and weight from package format.
     */
    public static function calculatePrice(PDO $db): void
    {
        try {
            $data = BookingInput::normalize(BookingInput::decode(file_get_contents('php://input', false, null, 0, 65537)), false);
            $distance = self::resolveDistance($data);
            $weight = self::resolveWeight($data);
        } catch (TransactionBusinessException $error) {
            Response::error($error->getMessage(), $error->getStatusCode());
        }

        KanoServiceArea::assertCity($data->pickup_city ?? KanoServiceArea::city(), 'Pickup city');
        KanoServiceArea::assertCity($data->delivery_city ?? KanoServiceArea::city(), 'Delivery city');

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
        AuthMiddleware::requireClientKyc($db, $clientId);
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if ($raw === false || strlen($raw) > 65536) Response::error('Booking request is too large.', 413);
        try {
            $data = BookingInput::decode($raw);
            $key = DeliveryBooking::validateKey($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null);
            $requestHash = DeliveryBooking::requestHash($data);
            $data = BookingInput::normalize($data, true, false);
        } catch (JsonException $error) {
            Response::error('A valid JSON booking object is required.', 422);
        } catch (TransactionBusinessException $error) {
            Response::error($error->getMessage(), $error->getStatusCode());
        }
        $required = [
            'pickup_address', 'pickup_contact_name', 'pickup_contact_phone',
            'delivery_address', 'delivery_contact_name', 'delivery_contact_phone', 'item_description',
        ];
        foreach ($required as $field) {
            if (!isset($data->$field) || !is_string($data->$field) || trim($data->$field) === '') {
                Response::error("The field '{$field}' must be a non-empty string.", 422);
            }
        }
        $pickupAddress = trim($data->pickup_address);
        $deliveryAddress = trim($data->delivery_address);
        KanoServiceArea::assertDelivery(
            $data->pickup_city ?? KanoServiceArea::city(), $data->delivery_city ?? KanoServiceArea::city(),
            $pickupAddress, $deliveryAddress
        );
        $data->pickup_address = $pickupAddress;
        $data->delivery_address = $deliveryAddress;
        $serviceType = self::serviceType($data->service_type ?? 'same_day');

        $result = DatabaseTransaction::run($db, static function (PDO $db) use ($clientId, $key, $requestHash, $data, $serviceType): array {
            return DeliveryBooking::apply($db, $clientId, $key, $requestHash, static function (PDO $db) use ($data, $serviceType): array {
                $data = clone $data;
                BookingInput::schedule($data);
                $distance = self::resolveDistance($data);
                $weight = self::resolveWeight($data);
                $quantity = max(1, (int)($data->item_quantity ?? $data->quantity ?? 1));
                $isFragile = !empty($data->is_fragile) ? 1 : 0;
                $isPerishable = !empty($data->is_perishable) ? 1 : 0;
                [$pickupLat, $pickupLng, $deliveryLat, $deliveryLng] = self::resolveCoordinates($data);
                [$pricing, $rateCardId] = self::rateCardPricing($db, $serviceType);
                $quote = self::pricingBreakdown($pricing, $distance, $weight, (bool)$isFragile, (bool)$isPerishable, $quantity);
                return ['quote' => $quote, 'rate_card_id' => $rateCardId, 'fields' => [
                    'pickup_address' => $data->pickup_address, 'pickup_city' => 'Kano',
                    'pickup_contact_name' => trim($data->pickup_contact_name), 'pickup_contact_phone' => trim($data->pickup_contact_phone),
                    'delivery_address' => $data->delivery_address, 'delivery_city' => 'Kano',
                    'delivery_contact_name' => trim($data->delivery_contact_name), 'delivery_contact_phone' => trim($data->delivery_contact_phone),
                    'service_type' => $serviceType, 'item_description' => trim($data->item_description),
                    'item_category' => $data->item_category ?: $data->package_size,
                    'item_quantity' => $quantity, 'item_weight' => $weight, 'item_dimensions' => $data->item_dimensions ?? '',
                    'preferred_pickup_time' => $data->preferred_pickup_time ?? null, 'preferred_delivery_time' => $data->preferred_delivery_time ?? null,
                    'special_instructions' => $data->special_instructions ?? '', 'is_fragile' => $isFragile, 'is_perishable' => $isPerishable,
                    'distance_km' => $distance, 'base_cost' => $quote['base_cost'] + $quote['distance_charge'],
                    'weight_charge' => $quote['weight_charge'], 'fragile_charge' => $quote['fragile_charge'], 'total_cost' => $quote['total_cost'],
                    'pickup_latitude' => $pickupLat, 'pickup_longitude' => $pickupLng, 'delivery_latitude' => $deliveryLat, 'delivery_longitude' => $deliveryLng,
                ]];
            });
        });
        header('Idempotency-Replayed: ' . ($result['replayed'] ? 'true' : 'false'));
        Response::json($result['data'], 'Delivery request created successfully.', 201);
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
        try {
            [$page, $limit, $offset] = ListingQuery::page($_GET);
            $order = ListingQuery::order($_GET, ['newest' => 'd.id DESC', 'oldest' => 'd.id ASC', 'cost_desc' => 'd.total_cost DESC, d.id DESC', 'cost_asc' => 'd.total_cost ASC, d.id DESC', 'status' => 'd.status ASC, d.id DESC'], 'newest');
        } catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        $status = trim($_GET['status'] ?? 'all');
        $cursor = CursorPagination::fromQuery($_GET);
        if ($cursor !== null && ($_GET['sort'] ?? 'newest') !== 'newest') Response::error('Cursor mode supports newest-first ordering. Use page mode for other sorts.', 422);

        $whereClause = "WHERE d.client_id = :client_id";
        $params = ['client_id' => $clientId];

        try { $search = ListingQuery::search($_GET); }
        catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        if ($search !== '') {
            $whereClause .= ' AND (d.tracking_number LIKE :search_reference OR d.item_description LIKE :search_description OR d.pickup_address LIKE :search_pickup OR d.delivery_address LIKE :search_delivery)';
            foreach (['reference','description','pickup','delivery'] as $field) $params['search_' . $field] = '%' . $search . '%';
        }
        $payment = $_GET['payment'] ?? 'all';
        if ($payment === 'paid') $whereClause .= " AND d.payment_status = 'paid'";
        elseif ($payment === 'pending') $whereClause .= " AND d.payment_status IN ('unpaid','pending') AND d.status NOT IN ('cancelled','rejected','failed')";
        elseif ($payment !== 'all') Response::error('Unsupported payment filter.', 422);

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
            if ($cursor > 0) {
                $whereClause .= " AND d.id < :cursor";
                $params['cursor'] = $cursor;
            }

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
            $stmt->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
            $stmt->execute();
            $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $pageData = CursorPagination::page($deliveries, $limit);

            Response::json([
                'deliveries'  => $pageData['items'],
                'next_cursor' => $pageData['next_cursor'],
                'has_more'    => $pageData['has_more'],
            ], 'Client deliveries retrieved.');
            return;
        }

        // Traditional offset pagination (backward compatible)


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
                ORDER BY {$order}
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
        $isTrackingNumber = (bool) preg_match('/^TIL-\d{4}-[2-9A-HJKMNP-Z]{4}-[2-9A-HJKMNP-Z]{4}$/', $ref);

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
        unset($delivery['delivery_otp'], $delivery['otp_failed_attempts'], $delivery['otp_locked_until']);
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

        // Anonymous tracking exposes progress and city-level locations only.
        if (!$isAuthorizedParty) {
            // An allowlist protects new database columns automatically. Regex address
            // masking cannot reliably remove landmarks, names, or nested plot numbers.
            $delivery = TrackerHelper::publicView($delivery);
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
     * Assigned driver records recipient OTP proof. Payment and payout are separate events.
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
        if ($deliveryId < 1 || !preg_match('/^\d{6}$/D', $otp)) Response::error('A valid delivery ID and six-digit OTP are required.', 422);
        OperationsSchema::requireTables($db, ['drivers', 'delivery_proofs', 'delivery_earnings']);

        $result = DatabaseTransaction::run($db, function (PDO $db) use ($deliveryId, $driverId, $otp) {
            // Lock before checking the OTP, attempt counter, ownership, or delivery state.
            $stmt = $db->prepare("SELECT * FROM deliveries WHERE id = ? FOR UPDATE");
            $stmt->execute([$deliveryId]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$delivery) {
                DatabaseTransaction::fail('Delivery record not found.', 404);
            }

            if (empty($delivery['delivery_person_id']) || (int)$delivery['delivery_person_id'] !== $driverId) {
                DatabaseTransaction::fail('Only the delivery partner assigned to this shipment can record confirmation.', 403);
            }

            if ($delivery['status'] !== 'arrived') {
                DatabaseTransaction::fail('Delivery confirmation is available only after arrival at the destination.', 409);
            }

            // 1. Check if OTP confirmation is temporarily locked due to brute-force protection
            if (!empty($delivery['otp_locked_until']) && strtotime($delivery['otp_locked_until']) > time()) {
                $remainingSeconds = strtotime($delivery['otp_locked_until']) - time();
                $remainingMinutes = (int)ceil($remainingSeconds / 60);
                DatabaseTransaction::fail("OTP confirmation is locked. Try again in {$remainingMinutes} minute(s) or contact operations.", 429);
            }

            // 2. Validate confirmation OTP with attempt counting and lockout
            if (!hash_equals((string)$delivery['delivery_otp'], $otp)) {
                $failedAttempts = (!empty($delivery['otp_locked_until']) ? 0 : (int)($delivery['otp_failed_attempts'] ?? 0)) + 1;
                $maxAttempts = 5;

                if ($failedAttempts >= $maxAttempts) {
                    // Lock OTP confirmation for 30 minutes
                    $db->prepare("UPDATE deliveries SET otp_failed_attempts = ?, otp_locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?")
                       ->execute([$failedAttempts, $deliveryId]);

                    OperationalRecords::audit($db, $driverId, 'delivery', 'delivery.otp_lockout_triggered', 'delivery', $deliveryId, null, [
                        'failed_attempts' => $failedAttempts,
                        'locked_for_minutes' => 30
                    ], [], $deliveryId);

                    // Return normally to commit the failed-attempt counter before sending an error.
                    return ['error' => 'Too many incorrect OTP attempts. Confirmation is locked for 30 minutes.', 'code' => 429, 'locked' => true];
                } else {
                    $db->prepare("UPDATE deliveries SET otp_failed_attempts = ?, otp_locked_until = NULL WHERE id = ?")
                       ->execute([$failedAttempts, $deliveryId]);

                    $remaining = $maxAttempts - $failedAttempts;
                    return ['error' => "Invalid confirmation OTP. {$remaining} attempt(s) remain before lockout.", 'code' => 400];
                }
            }

            // 3. Proof of delivery accrues an earning; it cannot confirm collection or disbursement of money.
            $currentDelivery = $delivery;

            $update = $db->prepare("UPDATE deliveries SET 
                status = 'delivered',
                delivery_time = NOW(),
                otp_failed_attempts = 0,
                otp_locked_until = NULL
                WHERE id = ? AND status = 'arrived' AND delivery_person_id = ?");
            $update->execute([$deliveryId, $driverId]);
            if ($update->rowCount() !== 1) {
                DatabaseTransaction::fail('Delivery could not be confirmed because its state changed.', 409);
            }

            OperationalRecords::statusTransition($db, $deliveryId, 'arrived', 'delivered', $driverId, 'delivery', null, [], true);
            OperationalRecords::otpProofCaptured($db, $deliveryId, $driverId, true);
            self::releaseDriverAvailabilityIfIdle($db, $driverId);

            // Accrue a pending earning. A separate verified payout must settle it.
            if (!empty($currentDelivery['delivery_person_id'])) {
                $pricing = self::pricingConfig();
                $driverEarning = round((float)$currentDelivery['total_cost'] * (float)$pricing['driver_commission_pct'], 2);
                
                // Check if already recorded with pessimistic lock
                $earnCheck = $db->prepare("SELECT id FROM delivery_earnings WHERE delivery_id = ? AND earning_type = 'delivery_fee' FOR UPDATE");
                $earnCheck->execute([$deliveryId]);
                if (!$earnCheck->fetch()) {
                    $earnInsert = $db->prepare("INSERT INTO delivery_earnings (delivery_person_id, delivery_id, amount, earning_type, status) VALUES (?, ?, ?, 'delivery_fee', 'pending')");
                    $earnInsert->execute([
                        $currentDelivery['delivery_person_id'],
                        $deliveryId,
                        $driverEarning
                    ]);
                }
            }
            return ['tracking_number' => $delivery['tracking_number'], 'payment_status' => $delivery['payment_status']];
        }, 3);

        if (isset($result['error'])) {
            if (!empty($result['locked'])) {
                NotificationService::publishToRole($db, 'admin', 'admin.delivery_otp_locked', 'Delivery OTP locked', "Delivery #{$deliveryId} was locked after repeated incorrect OTP attempts.", $deliveryId);
            }
            Response::error($result['error'], $result['code']);
        }
        // Post-transaction notifications and response
        try { NotificationService::deliveryStatusChanged($db, $deliveryId, 'arrived', 'delivered'); }
        catch (Throwable $exception) { Logger::exception($exception, 'Delivery committed; completion notification failed'); }

        Response::json([
            'delivery_id' => $deliveryId,
            'tracking_number' => $result['tracking_number'],
            'status' => 'delivered',
            'payment_status' => $result['payment_status'],
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
            // A failed rate-card query is an outage, not permission to charge fallback rates.
            throw $exception;
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
        $driver = $db->prepare('SELECT id FROM drivers WHERE user_id = ? FOR UPDATE');
        $driver->execute([$userId]);
        $driverId = (int)$driver->fetchColumn();
        $active = $db->prepare("SELECT id FROM deliveries WHERE delivery_person_id = ? AND (status IN ('assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived') OR (pickup_time IS NOT NULL AND status NOT IN ('delivered', 'completed'))) LIMIT 1 FOR UPDATE");
        $active->execute([$userId]);
        if ((int)$active->fetchColumn() !== 0) return;
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
        foreach ($pricing as $name => $value) {
            if (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > 10000000) throw new RuntimeException('Invalid pricing rule: ' . $name);
        }
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

        foreach ([$baseCost, $distanceCharge, $weightCharge, $fragileCharge, $perishableCharge, $serviceCharge, $quantityCharge, $total] as $amount) {
            if (!is_finite($amount) || $amount < 0 || $amount > 10000000) throw new RuntimeException('Pricing configuration is outside supported bounds.');
        }

        return [
            'is_provisional' => true,
            'distance_source' => 'coordinate_estimate',
            'requires_operations_review' => true,
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

    public static function resolveCoordinates(mixed $data): array { return BookingQuoteInputs::resolveCoordinates($data); }
    public static function resolveDistance(mixed $data): float { return BookingQuoteInputs::resolveDistance($data); }
    public static function resolveWeight(mixed $data): float { return BookingQuoteInputs::resolveWeight($data); }
}
