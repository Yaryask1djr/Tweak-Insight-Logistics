<?php

declare(strict_types=1);

require_once __DIR__ . '/database_transaction.php';
require_once __DIR__ . '/operational_records.php';
require_once __DIR__ . '/tracker_helper.php';

/** Booking writes only. Network calls and notification dispatch never run here. */
final class DeliveryBooking
{
    public const OPERATION = 'delivery.create.v1';
    private static ?PDO $validatedDb = null;

    public static function assertTransactionalSchema(PDO $db): void
    {
        if (self::$validatedDb === $db) return;
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $required = ['deliveries','booking_requests','delivery_items','delivery_price_quotes','delivery_booking_snapshots','delivery_status_history','audit_logs','booking_outbox','notifications'];
            $stmt = $db->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($required), '?')) . ')');
            $stmt->execute($required); $engines = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($required as $table) {
                if (strcasecmp($engines[$table] ?? '', 'InnoDB') !== 0) throw new RuntimeException('Atomic booking requires the migrated InnoDB schema: ' . $table);
            }
        }
        self::$validatedDb = $db;
    }

    public static function validateKey(mixed $key): string
    {
        if (!is_string($key) || !preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $key)) {
            throw new TransactionBusinessException('A 16–128 character Idempotency-Key header is required (letters, digits, hyphen or underscore).', 422);
        }
        return $key;
    }

    /** Object order is irrelevant; array order, values and JSON types are significant. */
    public static function requestHash(stdClass $request): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if ($value instanceof stdClass) {
                $fields = get_object_vars($value);
                ksort($fields, SORT_STRING);
                return (object)array_map($canonical, $fields);
            }
            return is_array($value) ? array_map($canonical, $value) : $value;
        };
        return hash('sha256', json_encode($canonical($request), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Caller owns the transaction. The unique insert locks this client's key until
     * commit/rollback, including competing first requests. Never overwrite its hash.
     * The quote builder is invoked only for a new request, so retries cannot reprice.
     * @param callable(PDO): array{fields: array, quote: array, rate_card_id: ?int} $build
     */
    public static function apply(PDO $db, int $clientId, string $key, string $requestHash, callable $build): array
    {
        if (!$db->inTransaction()) throw new LogicException('Booking requires a transaction.');
        self::assertTransactionalSchema($db);
        self::validateKey($key);
        $keyHash = hash('sha256', $key);
        $reserve = $db->prepare('INSERT INTO booking_requests (client_id, operation, key_hash, request_hash)
            VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE key_hash = VALUES(key_hash)');
        $reserve->execute([$clientId, self::OPERATION, $keyHash, $requestHash]);
        $lock = $db->prepare('SELECT id, request_hash, delivery_id, response_json FROM booking_requests
            WHERE client_id = ? AND operation = ? AND key_hash = ? FOR UPDATE');
        $lock->execute([$clientId, self::OPERATION, $keyHash]);
        $request = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$request) throw new RuntimeException('Booking reservation was not found.');
        if (!hash_equals($request['request_hash'], $requestHash)) {
            throw new TransactionBusinessException('This booking key was already used with different data. Check the original booking before starting a new request.', 409);
        }
        if ($request['delivery_id'] !== null) {
            if (!$request['response_json']) throw new RuntimeException('Booking response is missing.');
            return ['data' => json_decode($request['response_json'], true, 32, JSON_THROW_ON_ERROR), 'replayed' => true];
        }

        ['fields' => $fields, 'quote' => $quote, 'rate_card_id' => $rateCardId] = $build($db);
        $fields = [
            'client_id' => $clientId,
            'tracking_number' => TrackerHelper::generateTrackingNumber(),
            'public_tracking_token' => TrackerHelper::generatePublicTrackingToken(),
            'delivery_otp' => TrackerHelper::generateOTP(),
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ] + $fields;
        // Column identifiers originate only in our server-side booking builder.
        foreach (array_keys($fields) as $column) {
            if (!preg_match('/\A[a-z_]+\z/', $column)) throw new LogicException('Invalid booking column.');
        }
        $insert = $db->prepare('INSERT INTO deliveries (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')');
        $insert->execute(array_values($fields));
        $deliveryId = (int)$db->lastInsertId();

        $item = [
            'description' => $fields['item_description'], 'category' => $fields['item_category'],
            'quantity' => $fields['item_quantity'], 'weight_kg' => $fields['item_weight'],
            'dimensions' => $fields['item_dimensions'], 'is_fragile' => $fields['is_fragile'],
            'is_perishable' => $fields['is_perishable'],
        ];
        $db->prepare('INSERT INTO delivery_items (delivery_id, description, category, quantity, weight_kg, dimensions, is_fragile, is_perishable)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$deliveryId, ...array_values($item)]);
        $quoteSnapshot = $quote + [
            'currency' => 'NGN', 'rate_card_id' => $rateCardId,
            'distance_km' => $fields['distance_km'], 'weight_kg' => $fields['item_weight'],
            'quantity' => $fields['item_quantity'], 'service_type' => $fields['service_type'],
        ];
        $quoteJson = json_encode($quoteSnapshot, JSON_THROW_ON_ERROR);
        // Also persist a quote when configuration supplies pricing (no rate-card row).
        $db->prepare("INSERT INTO delivery_price_quotes (delivery_id, rate_card_id, quote_status, distance_km, weight_kg, breakdown, total_amount, calculated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$deliveryId, $rateCardId, !empty($quote['is_provisional']) ? 'estimated' : 'accepted', $fields['distance_km'], $fields['item_weight'], $quoteJson, $quote['total_cost'], $clientId]);
        $snapshot = json_encode(['version' => 1, 'booking' => $fields, 'item' => $item, 'quote' => $quoteSnapshot], JSON_THROW_ON_ERROR);
        $db->prepare('INSERT INTO delivery_booking_snapshots (delivery_id, snapshot_json, snapshot_hash) VALUES (?, ?, ?)')
            ->execute([$deliveryId, $snapshot, hash('sha256', $snapshot)]);

        OperationalRecords::statusTransition($db, $deliveryId, null, 'pending', $clientId, 'client', null, [
            'tracking_number' => $fields['tracking_number'], 'service_type' => $fields['service_type'],
        ], true);
        $event = json_encode(['client_id' => $clientId, 'tracking_number' => $fields['tracking_number'], 'status' => 'pending'], JSON_THROW_ON_ERROR);
        $db->prepare("INSERT INTO booking_outbox (delivery_id, event_type, payload) VALUES (?, 'delivery.booked', ?)")
            ->execute([$deliveryId, $event]);

        $response = [
            'delivery_id' => $deliveryId, 'tracking_number' => $fields['tracking_number'],
            'public_tracking_token' => $fields['public_tracking_token'], 'status' => 'pending',
            'total_cost' => $quote['total_cost'], 'delivery_otp' => $fields['delivery_otp'],
            'message' => 'Delivery request submitted. Awaiting central operations review and dispatch.',
            'is_provisional' => (bool)($quote['is_provisional'] ?? false),
        ];
        $db->prepare('UPDATE booking_requests SET delivery_id = ?, response_json = ? WHERE id = ?')
            ->execute([$deliveryId, json_encode($response, JSON_THROW_ON_ERROR), $request['id']]);
        return ['data' => $response, 'replayed' => false];
    }
}
