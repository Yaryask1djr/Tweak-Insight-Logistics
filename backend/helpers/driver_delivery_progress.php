<?php

declare(strict_types=1);

require_once __DIR__ . '/database_transaction.php';
require_once __DIR__ . '/delivery_status_policy.php';
require_once __DIR__ . '/operational_records.php';
require_once __DIR__ . '/delivery_payments.php';

/** Driver milestones only. Payment recording and provider verification are separate operations. */
final class DriverDeliveryProgress
{
    private static ?PDO $validatedDb = null;

    public static function request(string $raw): array
    {
        if (strlen($raw) > 4096) DatabaseTransaction::fail('Status request is too large.', 413);
        try { $data = json_decode($raw, false, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { DatabaseTransaction::fail('A valid JSON object is required.', 422); }
        if (!$data instanceof stdClass) DatabaseTransaction::fail('A JSON object is required.', 422);
        $id = $data->delivery_id ?? null;
        if ((!is_int($id) && !is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            DatabaseTransaction::fail('A valid delivery_id is required.', 422);
        }
        $target = $data->status ?? null;
        if (!is_string($target) || !in_array($target, ['driver_en_route', 'picked_up', 'in_transit', 'arrived', 'failed'], true)) {
            DatabaseTransaction::fail('Choose a supported driver milestone; delivery completion requires recipient confirmation.', 422);
        }
        $reason = $data->status_reason ?? null;
        if ($reason !== null && !is_string($reason)) DatabaseTransaction::fail('status_reason must be text.', 422);
        // Deliberately discard payment_status, pickup_time and other client-supplied fields.
        return ['delivery_id' => (int)$id, 'status' => $target, 'status_reason' => self::reason($target, $reason)];
    }

    /** Caller owns the transaction and sends notifications only after commit. */
    public static function apply(PDO $db, int $deliveryId, int $driverUserId, string $target, ?string $reason = null): array
    {
        if (!$db->inTransaction()) throw new LogicException('Driver progress requires an active transaction.');
        self::assertTransactionalSchema($db);
        $reason = self::reason($target, $reason);
        $lock = $db->prepare('SELECT * FROM deliveries WHERE id = ? AND delivery_person_id = ? FOR UPDATE');
        $lock->execute([$deliveryId, $driverUserId]);
        $delivery = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$delivery) DatabaseTransaction::fail('Delivery record not found or not assigned to your account.', 403);
        if (!DeliveryStatusPolicy::driverTransition($delivery['status'], $target)) {
            DatabaseTransaction::fail("Invalid delivery workflow transition from '{$delivery['status']}' to '{$target}'.", 409);
        }

        // Recheck eligibility under the same locks used by assignment/cancellation.
        $profile = $db->prepare('SELECT d.id, d.kyc_status, d.active_status, a.availability_status,
            u.is_approved, u.account_status, u.role FROM drivers d
            JOIN users u ON u.id = d.user_id JOIN driver_availability a ON a.driver_id = d.id
            WHERE u.id = ? FOR UPDATE');
        $profile->execute([$driverUserId]);
        $driver = $profile->fetch(PDO::FETCH_ASSOC);
        if (!$driver || $driver['kyc_status'] !== 'verified' || $driver['active_status'] !== 'active'
            || (int)$driver['is_approved'] !== 1 || $driver['account_status'] !== 'active' || $driver['role'] !== 'delivery') {
            DatabaseTransaction::fail('An active, approved delivery partner is required. Contact operations.', 403);
        }

        if ($target === 'picked_up') {
            if ($delivery['payment_status'] !== 'paid') {
                DatabaseTransaction::fail('Fare payment must be recorded as paid before pickup. Do not collect the package; contact operations.', 409);
            }
            if ($delivery['pickup_time'] !== null) DatabaseTransaction::fail('Pickup was already recorded. Operations must review this delivery.', 409);
            DeliveryPayments::requirePickupEvidence($db, $delivery);
        }
        $custody = $delivery['pickup_time'] !== null || in_array($delivery['status'], ['picked_up', 'in_transit', 'arrived'], true);
        if ($custody && $delivery['pickup_time'] === null) {
            DatabaseTransaction::fail('The pickup record is incomplete. Operations must reconcile parcel custody before another status change.', 409);
        }

        $fields = 'status = :target';
        $params = ['target' => $target, 'id' => $deliveryId, 'driver' => $driverUserId, 'previous' => $delivery['status']];
        if ($target === 'picked_up') $fields .= ', pickup_time = UTC_TIMESTAMP()';
        if ($target === 'failed') { $fields .= ', status_reason = :reason'; $params['reason'] = $reason; }
        $paymentGuard = $target === 'picked_up' ? " AND payment_status = 'paid' AND pickup_time IS NULL" : '';
        $update = $db->prepare("UPDATE deliveries SET {$fields} WHERE id = :id AND delivery_person_id = :driver AND status = :previous{$paymentGuard}");
        $update->execute($params);
        if ($update->rowCount() !== 1) DatabaseTransaction::fail('The delivery changed. Refresh before updating its status.', 409);

        $metadata = $target === 'picked_up' ? ['payment_status_at_pickup' => $delivery['payment_status']] : [];
        if ($target === 'failed' && !$custody && $driver['availability_status'] === 'busy') {
            $work = $db->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_person_id = ? AND id <> ?
                AND (status IN ('assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived')
                    OR (pickup_time IS NOT NULL AND status NOT IN ('delivered', 'completed')))");
            $work->execute([$driverUserId, $deliveryId]);
            if ((int)$work->fetchColumn() === 0) {
                $db->prepare("UPDATE driver_availability SET availability_status = 'available', available_since = UTC_TIMESTAMP()
                    WHERE driver_id = ? AND availability_status = 'busy'")->execute([$driver['id']]);
                $metadata['driver_availability'] = 'available';
            }
        }
        OperationalRecords::statusTransition($db, $deliveryId, $delivery['status'], $target, $driverUserId, 'delivery', $reason, $metadata, true);
        return ['delivery_id' => $deliveryId, 'previous_status' => $delivery['status'], 'status' => $target];
    }

    private static function reason(string $target, ?string $reason): ?string
    {
        $reason = trim($reason ?? '');
        if (mb_strlen($reason, 'UTF-8') > 500 || str_contains($reason, "\0") || ($target === 'failed' && $reason === '')) {
            DatabaseTransaction::fail('Provide a failure reason between 1 and 500 characters.', 422);
        }
        return $target === 'failed' ? $reason : null;
    }

    private static function assertTransactionalSchema(PDO $db): void
    {
        if (self::$validatedDb === $db) return;
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $tables = ['deliveries', 'drivers', 'users', 'driver_availability', 'delivery_status_history', 'audit_logs'];
            $stmt = $db->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
            $stmt->execute($tables); $engines = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($tables as $table) {
                if (strcasecmp($engines[$table] ?? '', 'InnoDB') !== 0) throw new RuntimeException('Driver progress requires the InnoDB workflow schema: ' . $table);
            }
        }
        self::$validatedDb = $db;
    }
}
