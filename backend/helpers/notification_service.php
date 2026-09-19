<?php

require_once __DIR__ . '/job_queue.php';

/**
 * In-app notification publisher for the delivery workflow.
 *
 * Notifications are deliberately persisted independently of controllers. This
 * keeps delivery changes resilient: a notification failure is logged but never
 * rolls back or falsifies a completed operational transition. Other channels
 * (email, SMS, WhatsApp, push) are enqueued to JobQueue for background worker execution.
 */
final class NotificationService
{
    /** @var array<string, bool> */
    private static array $tableAvailability = [];

    /** @var array<string, bool> */
    private static array $columnAvailability = [];

    /** @return array<string, array{audience: string, description: string}> */
    public static function eventCatalog(): array
    {
        return [
            'client.request_submitted'     => ['audience' => 'client', 'description' => 'A delivery request was submitted.'],
            'client.request_reviewed'      => ['audience' => 'client', 'description' => 'Operations started reviewing a request.'],
            'client.driver_assigned'       => ['audience' => 'client', 'description' => 'A verified driver accepted the request.'],
            'client.delivery_status_changed' => ['audience' => 'client', 'description' => 'A driver reached the next delivery milestone.'],
            'client.pickup_completed'      => ['audience' => 'client', 'description' => 'The goods were collected.'],
            'client.delivery_in_progress'  => ['audience' => 'client', 'description' => 'The goods are in transit.'],
            'client.delivery_completed'    => ['audience' => 'client', 'description' => 'Digital delivery confirmation was recorded.'],
            'client.request_cancelled'     => ['audience' => 'client', 'description' => 'A request was cancelled, rejected, or failed.'],
            'client.kyc_submitted'         => ['audience' => 'client', 'description' => 'A client KYC document was received for review.'],
            'client.kyc_under_review'      => ['audience' => 'client', 'description' => 'Operations has started reviewing the client KYC submission.'],
            'client.kyc_approved'          => ['audience' => 'client', 'description' => 'Client KYC was approved by operations.'],
            'client.kyc_rejected'          => ['audience' => 'client', 'description' => 'Client KYC was rejected; re-upload required.'],
            'admin.client_kyc_submitted'   => ['audience' => 'admin',  'description' => 'A client KYC submission is awaiting operations review.'],
            'driver.new_delivery_offer'    => ['audience' => 'driver', 'description' => 'An eligible driver can review a broadcast offer.'],
            'driver.assignment_confirmed'  => ['audience' => 'driver', 'description' => 'The driver won the first-accept assignment lock.'],
            'driver.delivery_change'       => ['audience' => 'driver', 'description' => 'An assigned delivery was changed or cancelled.'],
            'driver.document_verified'     => ['audience' => 'driver', 'description' => 'A driver KYC document was verified by operations.'],
            'driver.document_issue'        => ['audience' => 'driver', 'description' => 'KYC/document review requires action.'],
            'driver.kyc_approved'          => ['audience' => 'driver', 'description' => 'Driver KYC was fully approved by operations.'],
            'driver.kyc_rejected'          => ['audience' => 'driver', 'description' => 'Driver KYC was rejected; re-upload required.'],
            'admin.new_delivery_request'   => ['audience' => 'admin',  'description' => 'Operations needs to review a new request.'],
            'admin.driver_accepted'        => ['audience' => 'admin',  'description' => 'A driver accepted and locked an offer.'],
            'admin.delivery_completed'     => ['audience' => 'admin',  'description' => 'Digital proof of delivery was recorded.'],
            'admin.driver_document_issue'  => ['audience' => 'admin',  'description' => 'A driver or document requires operations attention.'],
        ];
    }

    /** Whether the operational migration has created the in-app inbox. */
    public static function isAvailable(PDO $db): bool
    {
        return self::hasTable($db, 'notifications');
    }

    /** Publish an in-app notification and enqueue external channel dispatch. */
    public static function publish(
        PDO $db,
        int $userId,
        string $type,
        string $title,
        string $body,
        ?int $deliveryId = null,
        array $payload = []
    ): void {
        if ($userId <= 0 || !self::isAvailable($db)) {
            return;
        }

        try {
            $statement = $db->prepare(
                "INSERT INTO notifications
                 (user_id, delivery_id, channel, notification_type, title, body, payload, delivery_status, sent_at)
                 VALUES (?, ?, 'in_app', ?, ?, ?, ?, 'sent', NOW())"
            );
            $statement->execute([
                $userId,
                $deliveryId,
                $type,
                self::truncate($title, 180),
                $body,
                $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);

            // Asynchronously dispatch external channels (SMS, WhatsApp, FCM Push, Webhook) via JobQueue
            JobQueue::setDb($db);
            JobQueue::push('notification.external_dispatch', [
                'user_id'     => $userId,
                'delivery_id' => $deliveryId,
                'type'        => $type,
                'title'       => $title,
                'body'        => $body,
                'payload'     => $payload,
            ]);
        } catch (Throwable $exception) {
            self::logFailure('Unable to persist in-app notification', $exception);
        }
    }

    /**
     * Publish the same event to active accounts in a role via single set-based query.
     * Prevents synchronous loop timeouts on large user sets.
     */
    public static function publishToRole(
        PDO $db,
        string $role,
        string $type,
        string $title,
        string $body,
        ?int $deliveryId = null,
        array $payload = []
    ): void {
        if (!self::isAvailable($db)) {
            return;
        }

        try {
            $jsonPayload = $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
            $truncatedTitle = self::truncate($title, 180);

            $sql = "INSERT INTO notifications
                    (user_id, delivery_id, channel, notification_type, title, body, payload, delivery_status, sent_at)
                    SELECT id, ?, 'in_app', ?, ?, ?, ?, 'sent', NOW()
                    FROM users
                    WHERE role = ?";

            if ($role === 'delivery') {
                $sql .= " AND is_approved = 1";
            }
            if (self::hasColumn($db, 'users', 'account_status')) {
                $sql .= " AND account_status = 'active'";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $deliveryId,
                $type,
                $truncatedTitle,
                $body,
                $jsonPayload,
                $role,
            ]);
        } catch (Throwable $exception) {
            self::logFailure('Unable to batch publish role notifications', $exception);
        }
    }

    /** Notify the client and operations when a delivery request is created. */
    public static function deliveryCreated(PDO $db, int $deliveryId): void
    {
        $delivery = self::findDelivery($db, $deliveryId);
        if (!$delivery) {
            return;
        }

        $reference = self::reference($delivery);
        $payload = self::deliveryPayload($delivery, 'pending');
        self::publish(
            $db,
            (int)$delivery['client_id'],
            'client.request_submitted',
            'Delivery request submitted',
            "Your delivery request {$reference} is awaiting operations review.",
            $deliveryId,
            $payload
        );
        self::publishToRole(
            $db,
            'admin',
            'admin.new_delivery_request',
            'New delivery request',
            "{$reference} is awaiting operations review.",
            $deliveryId,
            $payload
        );
    }

    /**
     * Emit notifications for a verified lifecycle transition. Controllers call
     * this only after their ownership and status-policy checks have succeeded.
     */
    public static function deliveryStatusChanged(PDO $db, int $deliveryId, string $fromStatus, string $toStatus): void
    {
        $delivery = self::findDelivery($db, $deliveryId);
        if (!$delivery) {
            return;
        }

        $reference = self::reference($delivery);
        $payload = self::deliveryPayload($delivery, $toStatus, $fromStatus);
        $clientId = (int)$delivery['client_id'];
        $driverId = (int)($delivery['delivery_person_id'] ?? 0);

        switch ($toStatus) {
            case 'under_review':
                self::publish($db, $clientId, 'client.request_reviewed', 'Request under review', "Operations is reviewing {$reference}.", $deliveryId, $payload);
                break;

            case 'broadcasted':
                self::publishToRole($db, 'delivery', 'driver.new_delivery_offer', 'New delivery offer', "A Kano delivery offer is ready to review: {$reference}.", $deliveryId, $payload);
                break;

            case 'driver_en_route':
                self::publish($db, $clientId, 'client.delivery_status_changed', 'Driver en route', "Your driver is heading to the pickup point for {$reference}.", $deliveryId, $payload);
                break;

            case 'picked_up':
                self::publish($db, $clientId, 'client.pickup_completed', 'Pickup completed', "Your goods have been collected for {$reference}.", $deliveryId, $payload);
                break;

            case 'in_transit':
                self::publish($db, $clientId, 'client.delivery_in_progress', 'Delivery in progress', "Your goods are in transit for {$reference}.", $deliveryId, $payload);
                break;

            case 'arrived':
                self::publish($db, $clientId, 'client.delivery_status_changed', 'Driver arrived', "Your driver has arrived at the destination for {$reference}.", $deliveryId, $payload);
                break;

            case 'delivered':
                self::publish($db, $clientId, 'client.delivery_completed', 'Delivery completed', "Digital delivery confirmation was recorded for {$reference}.", $deliveryId, $payload);
                if ($driverId) {
                    self::publish($db, $driverId, 'driver.delivery_change', 'Delivery completed', "Digital delivery confirmation was recorded for {$reference}.", $deliveryId, $payload);
                }
                self::publishToRole($db, 'admin', 'admin.delivery_completed', 'Delivery completed', "Digital proof of delivery was recorded for {$reference}.", $deliveryId, $payload);
                break;

            case 'cancelled':
            case 'rejected':
            case 'failed':
                self::publish($db, $clientId, 'client.request_cancelled', 'Delivery request updated', "{$reference} was " . str_replace('_', ' ', $toStatus) . '. Please review your delivery record for details.', $deliveryId, $payload);
                if ($driverId) {
                    self::publish($db, $driverId, 'driver.delivery_change', 'Delivery assignment updated', "{$reference} was " . str_replace('_', ' ', $toStatus) . '.', $deliveryId, $payload);
                }
                break;
        }
    }

    /** Notify the customer, winning driver, and operations of the atomic assignment. */
    public static function assignmentAccepted(PDO $db, int $deliveryId, int $driverUserId): void
    {
        $delivery = self::findDelivery($db, $deliveryId);
        if (!$delivery) {
            return;
        }

        $reference = self::reference($delivery);
        $payload = self::deliveryPayload($delivery, 'assigned', 'broadcasted');
        self::publish($db, (int)$delivery['client_id'], 'client.driver_assigned', 'Driver assigned', "A verified delivery partner has been assigned to {$reference}.", $deliveryId, $payload);
        self::publish($db, $driverUserId, 'driver.assignment_confirmed', 'Assignment confirmed', "You are assigned to {$reference}. Proceed through the delivery workflow in the app.", $deliveryId, $payload);
        self::publishToRole($db, 'admin', 'admin.driver_accepted', 'Driver accepted delivery', "A verified delivery partner accepted and locked {$reference}.", $deliveryId, $payload);
    }

    /**
     * KYC/document review code can call this without learning the storage or
     * channel implementation. It is intentionally not coupled to a document
     * endpoint because that review workflow has not yet been implemented.
     */
    public static function driverDocumentIssue(PDO $db, int $driverUserId, string $title, string $body, array $context = []): void
    {
        $payload = ['event' => 'driver_document_issue'] + self::safeContext($context);
        self::publish($db, $driverUserId, 'driver.document_issue', $title, $body, null, $payload);
        self::publishToRole($db, 'admin', 'admin.driver_document_issue', 'Driver document issue', $title, null, $payload + ['driver_user_id' => $driverUserId]);
    }

    /** @return array<string, mixed>|null */
    private static function findDelivery(PDO $db, int $deliveryId): ?array
    {
        if (!self::isAvailable($db)) {
            return null;
        }

        try {
            $statement = $db->prepare('SELECT id, tracking_number, client_id, delivery_person_id, status FROM deliveries WHERE id = ? LIMIT 1');
            $statement->execute([$deliveryId]);
            $delivery = $statement->fetch(PDO::FETCH_ASSOC);
            return $delivery ?: null;
        } catch (Throwable $exception) {
            self::logFailure('Unable to load delivery for notification', $exception);
            return null;
        }
    }

    /** @param array<string, mixed> $delivery @return array<string, mixed> */
    private static function deliveryPayload(array $delivery, string $status, ?string $previousStatus = null): array
    {
        $payload = [
            'event' => 'delivery_status_changed',
            'delivery_id' => (int)$delivery['id'],
            'tracking_number' => (string)$delivery['tracking_number'],
            'status' => $status,
        ];
        if ($previousStatus !== null) {
            $payload['previous_status'] = $previousStatus;
        }
        return $payload;
    }

    /** @param array<string, mixed> $delivery */
    private static function reference(array $delivery): string
    {
        return '#' . ((string)$delivery['tracking_number'] ?: (string)$delivery['id']);
    }

    /** Keep extra payload context small and never accept secrets or recipient data. */
    private static function safeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string)$key), ['otp', 'delivery_otp', 'password', 'token'], true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $safe[(string)$key] = is_string($value) ? self::truncate($value, 200) : $value;
            }
        }
        return $safe;
    }

    private static function hasTable(PDO $db, string $table): bool
    {
        $key = spl_object_id($db) . ':' . $table;
        if (array_key_exists($key, self::$tableAvailability)) {
            return self::$tableAvailability[$key];
        }

        try {
            $statement = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $statement->execute([$table]);
            return self::$tableAvailability[$key] = (bool)$statement->fetchColumn();
        } catch (Throwable $exception) {
            self::logFailure('Unable to inspect notification schema', $exception);
            return self::$tableAvailability[$key] = false;
        }
    }

    private static function hasColumn(PDO $db, string $table, string $column): bool
    {
        $key = spl_object_id($db) . ":{$table}.{$column}";
        if (array_key_exists($key, self::$columnAvailability)) {
            return self::$columnAvailability[$key];
        }

        try {
            $statement = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $statement->execute([$table, $column]);
            return self::$columnAvailability[$key] = (bool)$statement->fetchColumn();
        } catch (Throwable $exception) {
            self::logFailure('Unable to inspect notification recipient schema', $exception);
            return self::$columnAvailability[$key] = false;
        }
    }

    private static function truncate(string $value, int $maximum): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $maximum) : substr($value, 0, $maximum);
    }

    private static function logFailure(string $message, Throwable $exception): void
    {
        if (class_exists('Logger')) {
            Logger::warning($message, ['error' => $exception->getMessage()]);
        }
    }
}
