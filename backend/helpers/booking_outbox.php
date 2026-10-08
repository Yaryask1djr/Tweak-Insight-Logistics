<?php

declare(strict_types=1);

require_once __DIR__ . '/database_transaction.php';
require_once __DIR__ . '/delivery_booking.php';

/**
 * Projects committed booking events to the in-app inbox. The event lock, inbox
 * inserts and processed marker share a second transaction; crashes cannot create
 * duplicate inbox messages. No Redis hand-off/dual write is involved.
 * External delivery remains explicitly unconfigured until a provider is built.
 */
final class BookingOutbox
{
    public static function processNext(PDO $db): bool
    {
        if ($db->inTransaction()) throw new LogicException('Outbox dispatch must run after booking commit.');
        DeliveryBooking::assertTransactionalSchema($db);
        $eventId = null;
        try {
            return DatabaseTransaction::run($db, static function (PDO $db) use (&$eventId): bool {
                $event = $db->query("SELECT id, delivery_id, event_type, payload FROM booking_outbox
                    WHERE status = 'pending' AND available_at <= UTC_TIMESTAMP()
                    ORDER BY available_at, id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
                if (!$event) return false;
                $eventId = (int)$event['id'];
                if ($event['event_type'] !== 'delivery.booked') throw new RuntimeException('Unsupported booking event.');
                $payload = json_decode($event['payload'], true, 32, JSON_THROW_ON_ERROR);
                if (empty($payload['client_id']) || empty($payload['tracking_number'])) throw new RuntimeException('Invalid booking event.');
                $reference = $payload['tracking_number'];
                $inboxPayload = json_encode(['delivery_id' => (int)$event['delivery_id'], 'tracking_number' => $reference, 'status' => 'pending'], JSON_THROW_ON_ERROR);
                $db->prepare("INSERT INTO notifications (user_id, delivery_id, channel, notification_type, title, body, payload, delivery_status, sent_at)
                    VALUES (?, ?, 'in_app', 'client.request_submitted', 'Delivery request submitted', ?, ?, 'sent', UTC_TIMESTAMP())")
                    ->execute([$payload['client_id'], $event['delivery_id'], "Your delivery request {$reference} is awaiting operations review.", $inboxPayload]);
                $db->prepare("INSERT INTO notifications (user_id, delivery_id, channel, notification_type, title, body, payload, delivery_status, sent_at)
                    SELECT id, ?, 'in_app', 'admin.new_delivery_request', 'New delivery request', ?, ?, 'sent', UTC_TIMESTAMP()
                    FROM users WHERE role = 'admin' AND account_status = 'active'")
                    ->execute([$event['delivery_id'], "{$reference} is awaiting operations review.", $inboxPayload]);
                $db->prepare("UPDATE booking_outbox SET status = 'processed', processed_at = UTC_TIMESTAMP(), last_error = NULL WHERE id = ?")
                    ->execute([$eventId]);
                return true;
            });
        } catch (Throwable $error) {
            if ($eventId !== null) {
                // Rollback already removed every inbox insert. Bounded retries retain
                // failures for operators rather than blocking every following event.
                $db->prepare("UPDATE booking_outbox SET status = CASE WHEN attempts >= 9 THEN 'failed' ELSE 'pending' END,
                    attempts = attempts + 1, available_at = ?, last_error = ? WHERE id = ? AND status = 'pending'")
                    ->execute([gmdate('Y-m-d H:i:s', time() + 60), 'In-app projection failed; inspect worker logs.', $eventId]);
            }
            throw $error;
        }
    }
}
