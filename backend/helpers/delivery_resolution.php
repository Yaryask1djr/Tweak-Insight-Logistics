<?php

require_once __DIR__ . '/database_transaction.php';
require_once __DIR__ . '/delivery_status_policy.php';
require_once __DIR__ . '/operational_records.php';

/** Admin lifecycle changes. The caller owns the transaction and sends notifications after commit. */
final class DeliveryResolution
{
    public static function apply(PDO $db, int $deliveryId, string $target, int $adminId, ?string $reason): array
    {
        if (!$db->inTransaction()) throw new LogicException('Delivery resolution requires an active transaction.');
        $reason = trim((string)$reason);
        $reasonLength = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
        if (in_array($target, ['rejected', 'cancelled'], true) && ($reasonLength < 1 || $reasonLength > 500)) {
            DatabaseTransaction::fail('Provide a reason between 1 and 500 characters.', 422);
        }
        $query = $db->prepare('SELECT id, status, tracking_number, delivery_person_id, pickup_time FROM deliveries WHERE id = ? FOR UPDATE');
        $query->execute([$deliveryId]);
        $delivery = $query->fetch(PDO::FETCH_ASSOC);
        if (!$delivery) DatabaseTransaction::fail('Delivery not found.', 404);
        if (in_array($target, ['cancelled', 'rejected'], true) && (!empty($delivery['pickup_time'])
            || in_array($delivery['status'], ['picked_up', 'in_transit', 'arrived'], true))) {
            DatabaseTransaction::fail('This parcel has been picked up. Operations must arrange and record custody or return resolution before closing it.', 409);
        }
        if (!DeliveryStatusPolicy::adminTransition($delivery['status'], $target)) {
            DatabaseTransaction::fail("Admin cannot move a delivery from '{$delivery['status']}' to '{$target}'.", 409);
        }

        $metadata = [];
        if ($target === 'cancelled') {
            // Match assignment lock order: delivery, driver/availability, assignment/offers.
            $driver = null;
            if (!empty($delivery['delivery_person_id'])) {
                $lock = $db->prepare('SELECT d.id, d.kyc_status, d.active_status, a.availability_status,
                    u.is_approved, u.account_status, u.role FROM drivers d
                    JOIN driver_availability a ON a.driver_id = d.id JOIN users u ON u.id = d.user_id
                    WHERE u.id = ? FOR UPDATE');
                $lock->execute([$delivery['delivery_person_id']]);
                $driver = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$driver) DatabaseTransaction::fail('Assigned driver records are incomplete; operations must repair them before cancellation.', 409);
            }
            $assignments = $db->prepare('SELECT id, driver_id FROM delivery_assignments WHERE delivery_id = ? AND is_current = 1 FOR UPDATE');
            $assignments->execute([$deliveryId]);
            foreach ($assignments->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
                if (!$driver || (int)$assignment['driver_id'] !== (int)$driver['id']) {
                    DatabaseTransaction::fail('Current assignment records disagree with the delivery driver; operations review is required.', 409);
                }
            }
            $close = $db->prepare("UPDATE delivery_assignments SET assignment_status = 'cancelled', is_current = 0,
                released_at = UTC_TIMESTAMP(), release_reason = ? WHERE delivery_id = ? AND is_current = 1");
            $close->execute([$reason, $deliveryId]);
            $metadata['assignments_closed'] = $close->rowCount();
            $withdraw = $db->prepare("UPDATE delivery_driver_offers SET offer_status = 'withdrawn',
                responded_at = UTC_TIMESTAMP(), response_reason = ? WHERE delivery_id = ? AND offer_status = 'offered'");
            $withdraw->execute([$reason, $deliveryId]);
            $metadata['offers_withdrawn'] = $withdraw->rowCount();

            if ($driver && $driver['availability_status'] === 'busy') {
                // A failed/cancelled legacy order may still represent goods in the driver's custody.
                $work = $db->prepare("SELECT COUNT(*) FROM deliveries WHERE delivery_person_id = ? AND id <> ?
                    AND (status IN ('assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived')
                        OR (pickup_time IS NOT NULL AND status NOT IN ('delivered', 'completed')))");
                $work->execute([$delivery['delivery_person_id'], $deliveryId]);
                if ((int)$work->fetchColumn() === 0) {
                    $eligible = $driver['kyc_status'] === 'verified' && $driver['active_status'] === 'active'
                        && (int)$driver['is_approved'] === 1 && $driver['account_status'] === 'active' && $driver['role'] === 'delivery';
                    $availability = $eligible ? 'available' : 'offline';
                    $update = $db->prepare("UPDATE driver_availability SET availability_status = ?,
                        available_since = CASE WHEN ? = 'available' THEN UTC_TIMESTAMP() ELSE NULL END
                        WHERE driver_id = ? AND availability_status = 'busy'");
                    $update->execute([$availability, $availability, $driver['id']]);
                    $metadata['driver_availability'] = $availability;
                }
            }
        }
        $update = $db->prepare('UPDATE deliveries SET status = ?, status_reason = ? WHERE id = ? AND status = ?');
        $update->execute([$target, $reason === '' ? null : $reason, $deliveryId, $delivery['status']]);
        if ($update->rowCount() !== 1) DatabaseTransaction::fail('The delivery changed. Refresh before updating its status.', 409);
        // Required history and audit writes must fail the whole change, not silently disappear.
        OperationalRecords::statusTransition($db, $deliveryId, $delivery['status'], $target, $adminId, 'admin', $reason, $metadata, true);
        return $delivery;
    }
}
