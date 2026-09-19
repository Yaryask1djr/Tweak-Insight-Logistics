<?php

/**
 * Writes the append-only operational records introduced by the workflow schema.
 *
 * The current application can be deployed before the database migration. In that
 * state these methods safely no-op; once the tables exist, every connected
 * transition begins creating durable history without changing API responses.
 */
final class OperationalRecords
{
    /** @var array<string, bool> */
    private static array $tableAvailability = [];

    public static function statusTransition(
        PDO $db,
        int $deliveryId,
        ?string $fromStatus,
        string $toStatus,
        ?int $actorUserId,
        string $actorRole,
        ?string $reason = null,
        array $metadata = []
    ): void {
        if (!self::hasTable($db, 'delivery_status_history')) {
            return;
        }

        try {
            $statement = $db->prepare(
                'INSERT INTO delivery_status_history
                 (delivery_id, from_status, to_status, changed_by_user_id, changed_by_role, reason, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $deliveryId,
                $fromStatus,
                $toStatus,
                $actorUserId,
                $actorRole,
                $reason,
                $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);

            self::audit(
                $db,
                $actorUserId,
                $actorRole,
                'delivery.status_changed',
                'delivery',
                $deliveryId,
                ['status' => $fromStatus],
                ['status' => $toStatus],
                $metadata + ['reason' => $reason]
            );
        } catch (Throwable $exception) {
            self::logFailure('Unable to write delivery status history', $exception);
        }
    }

    public static function audit(
        PDO $db,
        ?int $actorUserId,
        string $actorRole,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        array $metadata = [],
        ?int $deliveryId = null
    ): void {
        if (!self::hasTable($db, 'audit_logs')) {
            return;
        }

        try {
            $statement = $db->prepare(
                'INSERT INTO audit_logs
                 (actor_user_id, actor_role, action, entity_type, entity_id, delivery_id, before_state, after_state, metadata, request_id, ip_address)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $packedIp = $ip ? @inet_pton($ip) : null;
            $statement->execute([
                $actorUserId,
                $actorRole,
                $action,
                $entityType,
                $entityId,
                $deliveryId,
                $beforeState ? json_encode($beforeState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                $afterState ? json_encode($afterState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
                $packedIp ?: null,
            ]);
        } catch (Throwable $exception) {
            self::logFailure('Unable to write operational audit record', $exception);
        }
    }

    /** Mirror an accepted broadcast into the durable assignment record. */
    public static function assignmentLocked(PDO $db, int $deliveryId, int $driverUserId): void
    {
        if (!self::hasTable($db, 'drivers') || !self::hasTable($db, 'delivery_assignments')) {
            return;
        }

        try {
            $driver = $db->prepare('SELECT id FROM drivers WHERE user_id = ? LIMIT 1');
            $driver->execute([$driverUserId]);
            $driverId = (int)$driver->fetchColumn();
            if (!$driverId) {
                return;
            }

            $existing = $db->prepare('SELECT id FROM delivery_assignments WHERE delivery_id = ? AND is_current = 1 LIMIT 1');
            $existing->execute([$deliveryId]);
            if ($existing->fetchColumn()) {
                return;
            }

            $nextSequence = $db->prepare('SELECT COALESCE(MAX(assignment_sequence), 0) + 1 FROM delivery_assignments WHERE delivery_id = ?');
            $nextSequence->execute([$deliveryId]);
            $sequence = (int)$nextSequence->fetchColumn();
            $acceptedOffer = $db->prepare("SELECT id FROM delivery_driver_offers WHERE delivery_id = ? AND driver_id = ? AND offer_status = 'accepted' ORDER BY responded_at DESC LIMIT 1");
            $acceptedOffer->execute([$deliveryId, $driverId]);
            $offerId = $acceptedOffer->fetchColumn() ?: null;

            $insert = $db->prepare(
                "INSERT INTO delivery_assignments
                 (delivery_id, driver_id, accepted_offer_id, assignment_sequence, assignment_status, is_current, assignment_method, accepted_at)
                 VALUES (?, ?, ?, ?, 'locked', 1, 'broadcast', NOW())"
            );
            $insert->execute([$deliveryId, $driverId, $offerId, $sequence]);

            self::audit(
                $db,
                $driverUserId,
                'delivery',
                'delivery.assignment_locked',
                'delivery_assignment',
                (int)$db->lastInsertId(),
                null,
                ['delivery_id' => $deliveryId, 'driver_id' => $driverId, 'assignment_sequence' => $sequence],
                [],
                $deliveryId
            );
        } catch (Throwable $exception) {
            self::logFailure('Unable to write delivery assignment record', $exception);
        }
    }

    /** Store a proof event without persisting the recipient OTP itself. */
    public static function otpProofCaptured(PDO $db, int $deliveryId, int $driverUserId): void
    {
        if (!self::hasTable($db, 'drivers') || !self::hasTable($db, 'delivery_proofs')) {
            return;
        }

        try {
            $driver = $db->prepare('SELECT id FROM drivers WHERE user_id = ? LIMIT 1');
            $driver->execute([$driverUserId]);
            $driverId = (int)$driver->fetchColumn();
            if (!$driverId) {
                return;
            }

            $insert = $db->prepare(
                "INSERT INTO delivery_proofs
                 (delivery_id, captured_by_driver_id, proof_type, verification_status, metadata)
                 VALUES (?, ?, 'otp', 'verified', ?)"
            );
            $insert->execute([
                $deliveryId,
                $driverId,
                json_encode(['otp_verified' => true], JSON_UNESCAPED_SLASHES),
            ]);

            self::audit(
                $db,
                $driverUserId,
                'delivery',
                'delivery.proof_recorded',
                'delivery_proof',
                (int)$db->lastInsertId(),
                null,
                ['proof_type' => 'otp', 'verification_status' => 'verified'],
                [],
                $deliveryId
            );
        } catch (Throwable $exception) {
            self::logFailure('Unable to write delivery proof record', $exception);
        }
    }

    private static function hasTable(PDO $db, string $table): bool
    {
        $key = spl_object_id($db) . ':' . $table;
        if (array_key_exists($key, self::$tableAvailability)) {
            return self::$tableAvailability[$key];
        }

        try {
            $statement = $db->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $statement->execute([$table]);
            return self::$tableAvailability[$key] = (bool)$statement->fetchColumn();
        } catch (Throwable $exception) {
            self::logFailure('Unable to inspect operational schema', $exception);
            return self::$tableAvailability[$key] = false;
        }
    }

    private static function logFailure(string $message, Throwable $exception): void
    {
        if (class_exists('Logger')) {
            Logger::warning($message, ['error' => $exception->getMessage()]);
        }
    }
}
