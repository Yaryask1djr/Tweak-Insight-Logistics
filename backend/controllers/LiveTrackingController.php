<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/cache_helper.php';
require_once __DIR__ . '/../helpers/monitoring.php';

/** Role-scoped live GPS read model. Exact coordinates are never public. */
final class LiveTrackingController
{
    private const STALE_AFTER_SECONDS = 120;

    /**
     * Poll live location: Serves directly from fast multi-tier cache (Redis / APCu / Memory)
     * avoiding disk-backed MySQL table reads on high-frequency requests.
     */
    public static function delivery(PDO $db, array $user): void
    {
        $reference = trim($_GET['tracking_number'] ?? $_GET['id'] ?? '');
        if ($reference === '') Response::error('tracking_number or delivery ID is required.');
        $id = ctype_digit($reference) ? (int)$reference : 0;

        $query = $db->prepare("
            SELECT d.id, d.tracking_number, d.client_id, d.delivery_person_id, d.status, d.tracking_started_at,
                   u.full_name AS driver_name
            FROM deliveries d
            LEFT JOIN users u ON u.id = d.delivery_person_id
            WHERE (d.tracking_number = ? OR d.id = ?)
              AND d.pickup_city = 'Kano' AND d.delivery_city = 'Kano'
        ");
        $query->execute([$reference, $id]);
        $delivery = $query->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) Response::notFound('Delivery not found.');
        if ($user['role'] === 'client' && (int)$delivery['client_id'] !== (int)$user['id']) {
            Response::forbidden('You may view live location only for your own deliveries.');
        }
        if ($user['role'] === 'delivery' && (int)$delivery['delivery_person_id'] !== (int)$user['id']) {
            Response::forbidden('You may view live location only for your assigned delivery.');
        }

        $delivery['last_location_latitude'] = null;
        $delivery['last_location_longitude'] = null;
        $delivery['last_location_accuracy_m'] = null;
        $delivery['last_location_heading_degrees'] = null;
        $delivery['last_location_at'] = null;

        // 1. Check multi-tier fast cache first (Redis, APCu, Memory)
        $cachedGeo = CacheHelper::getDriverLocation($delivery['id']);
        if ($cachedGeo !== null) {
            $delivery['last_location_latitude'] = $cachedGeo['latitude'] ?? null;
            $delivery['last_location_longitude'] = $cachedGeo['longitude'] ?? null;
            $delivery['last_location_accuracy_m'] = $cachedGeo['accuracy_m'] ?? null;
            $delivery['last_location_heading_degrees'] = $cachedGeo['heading_degrees'] ?? null;
            $delivery['last_location_at'] = $cachedGeo['updated_at'] ?? null;
        }

        // 2. Query isolated telemetry event store ONLY on cold cache miss
        if ($delivery['last_location_latitude'] === null) {
            $eventStmt = $db->prepare('
                SELECT latitude, longitude, accuracy_m, heading_degrees, recorded_at
                FROM delivery_location_events
                WHERE delivery_id = ?
                ORDER BY id DESC
                LIMIT 1
            ');
            $eventStmt->execute([$delivery['id']]);
            $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
            if ($event) {
                $delivery['last_location_latitude'] = $event['latitude'];
                $delivery['last_location_longitude'] = $event['longitude'];
                $delivery['last_location_accuracy_m'] = $event['accuracy_m'];
                $delivery['last_location_heading_degrees'] = $event['heading_degrees'];
                $delivery['last_location_at'] = $event['recorded_at'];

                // Warm the cache so subsequent high-frequency polls don't touch MySQL
                CacheHelper::setDriverLocation($delivery['id'], [
                    'latitude' => $event['latitude'],
                    'longitude' => $event['longitude'],
                    'accuracy_m' => $event['accuracy_m'],
                    'heading_degrees' => $event['heading_degrees'],
                    'updated_at' => $event['recorded_at'],
                ], 300);
            }
        }

        self::respond($delivery);
    }

    /**
     * Admin overview: Caches aggregated fleet locations for 5 seconds to prevent
     * redundant subquery execution during multi-user admin dashboard polling.
     */
    public static function activeForAdmin(PDO $db): void
    {
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
        $cursor = isset($_GET['cursor']) ? (int)$_GET['cursor'] : null;

        if ($cursor === null) {
            $cached = CacheHelper::getActiveAdminLocations();
            if ($cached !== null) {
                $cachedPage = isset($cached['items']) ? $cached : [
                    'items' => $cached,
                    'next_cursor' => null,
                    'has_more' => false,
                ];
                Response::json($cachedPage, 'Active Kano delivery locations retrieved (cached).');
                return;
            }
        }

        $cursorClause = $cursor === null ? '' : ' AND d.id < :cursor';
        $query = $db->prepare(" 
            SELECT d.id, d.tracking_number, d.status, d.tracking_started_at, d.request_time,
                   u.full_name AS driver_name,
                   e.latitude AS last_location_latitude,
                   e.longitude AS last_location_longitude,
                   e.accuracy_m AS last_location_accuracy_m,
                   e.heading_degrees AS last_location_heading_degrees,
                   e.recorded_at AS last_location_at
            FROM deliveries d
            LEFT JOIN users u ON u.id = d.delivery_person_id
            LEFT JOIN delivery_location_events e ON e.id = (
                SELECT latest.id
                FROM delivery_location_events latest
                WHERE latest.delivery_id = d.id
                ORDER BY latest.id DESC
                LIMIT 1
            )
            WHERE d.status IN ('driver_en_route', 'picked_up', 'in_transit', 'arrived')
              AND d.pickup_city = 'Kano' AND d.delivery_city = 'Kano'{$cursorClause}
            ORDER BY d.id DESC
            LIMIT :limit
        ");
        if ($cursor !== null) $query->bindValue(':cursor', $cursor, PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $queryStarted = microtime(true);
        $query->execute();
        $queryDurationMs = (microtime(true) - $queryStarted) * 1000;
        $slowQueryThreshold = max(1, (float)(getenv('SLOW_QUERY_MS') ?: 500));
        if ($queryDurationMs >= $slowQueryThreshold) {
            Monitoring::slowQuery('admin.live_tracking', $queryDurationMs, [
                'limit' => $limit,
                'cursor' => $cursor,
            ]);
        }
        $items = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $delivery) $items[] = self::format($delivery);

        $nextCursor = count($items) === $limit && !empty($items)
            ? (int)end($items)['delivery_id']
            : null;

        if ($cursor === null) {
            // Cache only the first page; cursor pages must reflect their own position.
            CacheHelper::setActiveAdminLocations([
                'items' => $items,
                'next_cursor' => $nextCursor,
                'has_more' => $nextCursor !== null,
            ], 5);
        }

        Response::json([
            'items' => $items,
            'next_cursor' => $nextCursor,
            'has_more' => $nextCursor !== null,
        ], 'Active Kano delivery locations retrieved.');
    }

    /**
     * Real-time Server-Sent Events (SSE) broadcast for live driver GPS coordinates.
     * Clients open a persistent HTTP stream and receive push updates as soon as
     * driver coordinates change, eliminating client HTTP polling overhead entirely.
     *
     * Behaviour:
     *   - Emits a connected event immediately with current delivery status
     *   - Polls the 10 s TTL geo cache every 2 seconds
     *   - Emits location event IFF coordinates timestamp advanced since last emission
     *   - Additionally emits status_changed event if delivery status advanced during
     *     the lifetime of this stream (for in-transit → arrived notifications)
     *   - Gracefully terminates after $maxSeconds so the browser auto-reconnects
     *     with the native EventSource reconnection logic.
     *   - Sends a comment-line ": ping" every 2 seconds in between payloads to
     *     prevent intermediate proxies from closing idle connections.
     */
    public static function streamDeliveryLocation(PDO $db, array $user): void
    {
        $reference = trim($_GET['tracking_number'] ?? $_GET['id'] ?? '');
        if ($reference === '') {
            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'tracking_number or delivery ID is required.']);
            exit;
        }
        $id = ctype_digit($reference) ? (int)$reference : 0;

        $deliveryQuery = $db->prepare("
            SELECT d.id, d.tracking_number, d.client_id, d.delivery_person_id, d.status
            FROM deliveries d
            WHERE (d.tracking_number = ? OR d.id = ?)
              AND d.pickup_city = 'Kano' AND d.delivery_city = 'Kano'
        ");
        $deliveryQuery->execute([$reference, $id]);
        $delivery = $deliveryQuery->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'Delivery not found.']);
            exit;
        }

        if ($user['role'] === 'client' && (int)$delivery['client_id'] !== (int)$user['id']) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
            exit;
        }
        if ($user['role'] === 'delivery' && (int)$delivery['delivery_person_id'] !== (int)$user['id']) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
            exit;
        }

        // Prevent PHP session locks from blocking the user's other browser tabs while
        // the SSE connection is open. Safe because we never write to $_SESSION inside.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Disable PHP time limit for SSE long-poll (60 s max anyway)
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        @ob_implicit_flush(true);

        // Establish Server-Sent Events (SSE) stream headers
        header('Content-Type: text/event-stream; charset=UTF-8');
        header('Cache-Control: no-cache, no-store, no-transform, must-revalidate, max-age=0');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Flush and disable every layer of PHP output buffering so each event reaches
        // the client socket immediately.
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $deliveryId = (int)$delivery['id'];
        $lastGeoTimestamp = null;
        $lastStatus       = (string)$delivery['status'];
        $maxSeconds       = 60;        // Browser will reconnect after — that's fine, it's SSE's design
        $pollIntervalUsec = 2_000_000; // 2 s between polls
        $startTime        = time();

        // Initial handshake event
        echo "event: connected\n";
        echo "data: " . json_encode([
            'delivery_id'     => $deliveryId,
            'tracking_number' => $delivery['tracking_number'],
            'status'          => $lastStatus,
            'stream_ttl_s'    => $maxSeconds,
            'poll_interval_s' => 2,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();

        $statusLookupStmt = $db->prepare('SELECT status FROM deliveries WHERE id = ? LIMIT 1');

        while (time() - $startTime < $maxSeconds) {
            if (function_exists('connection_status') && (connection_status() !== CONNECTION_NORMAL)) {
                break;
            }
            if (connection_aborted()) {
                break;
            }

            // ---- (A) Geo update from cache (10 s TTL, warmed by driver POST location) ----
            $geo = CacheHelper::getDriverLocation($deliveryId);
            if ($geo !== null) {
                $currentTimestamp = (string)($geo['updated_at'] ?? '');
                if ($currentTimestamp !== '' && $currentTimestamp !== $lastGeoTimestamp) {
                    $payload = [
                        'delivery_id'     => $deliveryId,
                        'tracking_number' => $delivery['tracking_number'],
                        'status'          => $lastStatus,
                        'latitude'        => isset($geo['latitude']) ? (float)$geo['latitude'] : null,
                        'longitude'       => isset($geo['longitude']) ? (float)$geo['longitude'] : null,
                        'accuracy_m'      => isset($geo['accuracy_m']) ? (float)$geo['accuracy_m'] : null,
                        'heading_degrees' => isset($geo['heading_degrees']) ? (float)$geo['heading_degrees'] : null,
                        'recorded_at'     => $currentTimestamp,
                        'is_live'         => true,
                        'server_time'     => gmdate('c'),
                    ];
                    echo "event: location\n";
                    echo "id: "  . $deliveryId . '-' . substr(sha1($currentTimestamp), 0, 8) . "\n";
                    echo "data: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                    flush();
                    $lastGeoTimestamp = $currentTimestamp;
                }
            }

            // ---- (B) Status change detection (e.g., in_transit -> arrived) ----
            $statusLookupStmt->execute([$deliveryId]);
            $currentStatus = (string)($statusLookupStmt->fetchColumn() ?: $lastStatus);
            if ($currentStatus !== '' && $currentStatus !== $lastStatus) {
                echo "event: status_changed\n";
                echo "data: " . json_encode([
                    'delivery_id'     => $deliveryId,
                    'tracking_number' => $delivery['tracking_number'],
                    'previous_status' => $lastStatus,
                    'new_status'      => $currentStatus,
                    'server_time'     => gmdate('c'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                flush();
                $lastStatus = $currentStatus;
            }

            // ---- (C) Heartbeat ping to keep HTTP proxy tunnels open ----
            echo ": sse-ping server_time=" . gmdate('c') . " stream_ttl_remaining_s=" . ($maxSeconds - (time() - $startTime)) . "\n\n";
            flush();

            // ---- (D) Non-blocking sleep so abort()/connection_status() are reactive ----
            usleep($pollIntervalUsec);
        }

        // Final "goodbye" event so clients can show a friendly "reconnecting..." spinner
        echo "event: stream_ended\n";
        echo "data: " . json_encode([
            'delivery_id'     => $deliveryId,
            'tracking_number' => $delivery['tracking_number'],
            'reason'          => 'stream_ttl_reached',
            'reconnect_hint'  => 'EventSource will reconnect automatically',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        flush();

        exit(0);
    }

    private static function respond(array $delivery): void { Response::json(self::format($delivery), 'Live delivery location retrieved.'); }
    private static function format(array $delivery): array
    {
        $fresh = $delivery['last_location_at'] && strtotime($delivery['last_location_at']) >= time() - self::STALE_AFTER_SECONDS;
        if (!$fresh && !empty($delivery['last_location_at'])) {
            Monitoring::staleGps([
                'delivery_id' => (int)$delivery['id'],
                'last_location_at' => $delivery['last_location_at'],
                'stale_after_seconds' => self::STALE_AFTER_SECONDS,
            ]);
        }
        return [
            'delivery_id' => (int)$delivery['id'], 'tracking_number' => $delivery['tracking_number'], 'status' => $delivery['status'], 'driver_name' => $delivery['driver_name'],
            'latitude' => $delivery['last_location_latitude'] === null ? null : (float)$delivery['last_location_latitude'],
            'longitude' => $delivery['last_location_longitude'] === null ? null : (float)$delivery['last_location_longitude'],
            'accuracy_m' => $delivery['last_location_accuracy_m'] === null ? null : (float)$delivery['last_location_accuracy_m'],
            'heading_degrees' => $delivery['last_location_heading_degrees'] === null ? null : (float)$delivery['last_location_heading_degrees'],
            'recorded_at' => $delivery['last_location_at'], 'is_live' => (bool)$fresh,
            'stale_after_seconds' => self::STALE_AFTER_SECONDS,
        ];
    }
}
