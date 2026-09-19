<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/operations_schema.php';
require_once __DIR__ . '/../helpers/operational_records.php';
require_once __DIR__ . '/../helpers/storage_adapter.php';
require_once __DIR__ . '/../helpers/auth_middleware.php';
require_once __DIR__ . '/../helpers/secure_document_download.php';

/** Driver KYC, availability, and document workflow. */
final class DriverOperationsController
{
    private const DOCUMENT_TYPES = ['government_id', 'drivers_license', 'vehicle_registration', 'proof_of_address', 'profile_photo', 'other'];
    private const AVAILABILITY = ['offline', 'available', 'paused'];
    private const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024;

    public static function profile(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_availability', 'driver_documents']);
        $driverId = OperationsSchema::ensureDriverProfile($db, $userId);
        $profile = $db->prepare(
            'SELECT d.id, d.kyc_status, d.kyc_rejection_reason, d.vehicle_type, d.vehicle_registration, d.max_payload_kg, d.active_status,
                    a.availability_status, a.last_location_at, a.updated_at AS availability_updated_at
             FROM drivers d LEFT JOIN driver_availability a ON a.driver_id = d.id WHERE d.id = ?'
        );
        $profile->execute([$driverId]);
        $documents = $db->prepare('SELECT id, document_type, document_number, expires_at, verification_status, rejection_reason, created_at, reviewed_at FROM driver_documents WHERE driver_id = ? ORDER BY created_at DESC');
        $documents->execute([$driverId]);
        $docsList = $documents->fetchAll(PDO::FETCH_ASSOC);

        $zones = OperationsSchema::hasTable($db, 'service_zones') ? $db->query("SELECT id, name FROM service_zones WHERE city = 'Kano' AND is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
        Response::json(['profile' => $profile->fetch(PDO::FETCH_ASSOC), 'documents' => $docsList, 'zones' => $zones], 'Driver operations profile retrieved.');
    }

    public static function updateProfile(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['drivers']);
        $data = json_decode(file_get_contents('php://input')); $driverId = OperationsSchema::ensureDriverProfile($db, $userId);
        $vehicleType = trim((string)($data->vehicle_type ?? '')) ?: null; $registration = trim((string)($data->vehicle_registration ?? '')) ?: null; $payload = isset($data->max_payload_kg) && $data->max_payload_kg !== '' ? max(0, (float)$data->max_payload_kg) : null;
        $db->prepare('UPDATE drivers SET vehicle_type = ?, vehicle_registration = ?, max_payload_kg = ? WHERE id = ?')->execute([$vehicleType, $registration, $payload, $driverId]);
        OperationalRecords::audit($db, $userId, 'delivery', 'driver.vehicle_profile_updated', 'driver', $driverId, null, ['vehicle_type' => $vehicleType]);
        Response::json(['driver_id' => $driverId], 'Vehicle profile updated.');
    }

    public static function setAvailability(PDO $db, int $userId): void
    {
        AuthMiddleware::requireDriverKyc($db, $userId);
        OperationsSchema::requireTables($db, ['drivers', 'driver_availability']);
        $data = json_decode(file_get_contents('php://input'));
        $status = $data->availability_status ?? '';
        if (!in_array($status, self::AVAILABILITY, true)) Response::error('availability_status must be offline, available, or paused.');

        $driverId = OperationsSchema::ensureDriverProfile($db, $userId);
        $profile = $db->prepare('SELECT kyc_status, active_status FROM drivers WHERE id = ?');
        $profile->execute([$driverId]);
        $driver = $profile->fetch(PDO::FETCH_ASSOC);
        if ($status === 'available' && (($driver['kyc_status'] ?? '') !== 'verified' || ($driver['active_status'] ?? '') !== 'active')) {
            Response::forbidden('Only an active, KYC-verified delivery partner can become available.');
        }

        $zoneId = isset($data->service_zone_id) && $data->service_zone_id !== '' ? (int)$data->service_zone_id : null;
        if ($zoneId && OperationsSchema::hasTable($db, 'service_zones')) { $zone = $db->prepare("SELECT id FROM service_zones WHERE id = ? AND city = 'Kano' AND is_active = 1"); $zone->execute([$zoneId]); if (!$zone->fetchColumn()) Response::error('Choose an active Kano service zone.', 422); }
        $latitude = isset($data->latitude) ? (float)$data->latitude : null; $longitude = isset($data->longitude) ? (float)$data->longitude : null;
        if (($latitude !== null && ($latitude < 11.85 || $latitude > 12.25)) || ($longitude !== null && ($longitude < 8.25 || $longitude > 8.85))) Response::error('Availability location is outside the Kano service area.', 422);
        $update = $db->prepare('UPDATE driver_availability SET availability_status = ?, service_zone_id = COALESCE(?, service_zone_id), last_latitude = COALESCE(?, last_latitude), last_longitude = COALESCE(?, last_longitude), last_location_at = CASE WHEN ? IS NULL OR ? IS NULL THEN last_location_at ELSE NOW() END, available_since = CASE WHEN ? = \'available\' THEN COALESCE(available_since, NOW()) ELSE NULL END WHERE driver_id = ?');
        $update->execute([$status, $zoneId, $latitude, $longitude, $latitude, $longitude, $status, $driverId]);
        OperationalRecords::audit($db, $userId, 'delivery', 'driver.availability_changed', 'driver', $driverId, null, ['availability_status' => $status]);
        Response::json(['availability_status' => $status], 'Availability updated.');
    }

    /** Upload a document using the decoupled Storage adapter (Local or S3/R2/MinIO). */
    public static function uploadDocument(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_documents']);
        $type = $_POST['document_type'] ?? '';
        if (!in_array($type, self::DOCUMENT_TYPES, true)) Response::error('A supported document_type is required.');
        try {
            $upload = UploadSecurity::validateUploadedFile($_FILES['document'] ?? [], self::MAX_DOCUMENT_BYTES);
        } catch (UploadSecurityException $exception) {
            Response::error($exception->getMessage(), $exception->httpStatus());
        }
        $file = $_FILES['document'];
        $mime = $upload['mime'];

        $driverId = OperationsSchema::ensureDriverProfile($db, $userId);
        $key = UploadSecurity::uuidV4() . '.' . $upload['extension'];
        $storageKey = 'driver-documents/' . $driverId . '/' . $key;
        try {
            $retentionUntil = UploadSecurity::retentionUntil()->format('Y-m-d H:i:s');
        } catch (RuntimeException $exception) {
            Logger::error('Driver KYC retention policy is unavailable', ['exception' => $exception->getMessage()]);
            Response::error('Secure document retention is not configured. Please try again later.', 503);
        }

        try {
            $stored = Storage::adapter()->put($storageKey, $file['tmp_name'], $mime, self::MAX_DOCUMENT_BYTES);
        } catch (UploadSecurityException $exception) {
            Response::error($exception->getMessage(), $exception->httpStatus());
        } catch (Throwable $exception) {
            Logger::error('Driver KYC document storage is unavailable', ['exception' => $exception->getMessage()]);
            Response::error('Secure document storage is temporarily unavailable. Please try again later.', 503);
        }
        if (!$stored) {
            Response::error('Secure document storage is temporarily unavailable. Please try again later.', 503);
        }

        $expiresAt = trim((string)($_POST['expires_at'] ?? '')) ?: null;
        if ($expiresAt !== null) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expiresAt);
            if (!$date || $date->format('Y-m-d') !== $expiresAt || $date < new DateTimeImmutable('today')) {
                Response::error('Expiry date must be today or later in YYYY-MM-DD format.', 422);
            }
        }
        $number = trim((string)($_POST['document_number'] ?? '')) ?: null;
        try {
            $db->beginTransaction();
            $insert = $db->prepare("INSERT INTO driver_documents (driver_id, document_type, document_number, storage_key, expires_at, retention_until, verification_status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $insert->execute([$driverId, $type, $number, $storageKey, $expiresAt, $retentionUntil]);
            $documentId = (int)$db->lastInsertId();
            $db->prepare("UPDATE drivers SET kyc_status = 'submitted', kyc_rejection_reason = NULL, kyc_reviewed_by = NULL, kyc_reviewed_at = NULL WHERE id = ? AND kyc_status <> 'verified'")->execute([$driverId]);
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            try {
                Storage::adapter()->delete($storageKey);
            } catch (Throwable) {
                // The scheduled retention/lifecycle policy remains a backstop if deletion is unavailable.
            }
            Logger::error('Driver KYC document metadata could not be saved', ['exception' => $exception->getMessage()]);
            Response::serverError('Unable to save the document submission. Please try again.');
        }
        OperationalRecords::audit($db, $userId, 'delivery', 'driver.document_submitted', 'driver_document', $documentId, null, ['document_type' => $type]);
        Response::json(['document_id' => $documentId, 'verification_status' => 'pending'], 'Document submitted for operations review.', 201);
    }

    /** Stream a driver's own KYC document without exposing its storage key. */
    public static function documentFile(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_documents']);
        $documentId = (int)($_GET['document_id'] ?? 0);
        if (!$documentId) Response::error('document_id is required.');

        $statement = $db->prepare(
            'SELECT dd.storage_key, dd.document_type
             FROM driver_documents dd
             JOIN drivers d ON d.id = dd.driver_id
             WHERE dd.id = ? AND d.user_id = ?'
        );
        $statement->execute([$documentId, $userId]);
        $document = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$document) Response::notFound('KYC document not found.');

        OperationalRecords::audit(
            $db,
            $userId,
            'delivery',
            'driver.kyc_document_downloaded',
            'driver_document',
            $documentId
        );
        SecureDocumentDownload::send($document['storage_key'], 'driver-kyc-' . $documentId . '-' . $document['document_type']);
    }

    public static function declineOffer(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'delivery_driver_offers']);
        $data = json_decode(file_get_contents('php://input')); $deliveryId = (int)($data->delivery_id ?? 0); $reason = trim((string)($data->reason ?? ''));
        if (!$deliveryId || $reason === '') Response::error('delivery_id and a decline reason are required.');
        $driverId = OperationsSchema::driverIdForUser($db, $userId); if (!$driverId) Response::forbidden('Driver profile not found.');
        $statement = $db->prepare("UPDATE delivery_driver_offers SET offer_status = 'declined', responded_at = NOW(), response_reason = ? WHERE delivery_id = ? AND driver_id = ? AND offer_status = 'offered'");
        $statement->execute([$reason, $deliveryId, $driverId]);
        if (!$statement->rowCount()) Response::error('No open offer for this delivery is available to decline.', 409);
        OperationalRecords::audit($db, $userId, 'delivery', 'driver.offer_declined', 'delivery', $deliveryId, null, ['reason' => $reason], [], $deliveryId);
        NotificationService::publishToRole($db, 'admin', 'admin.driver_declined_offer', 'Driver declined offer', "A driver declined delivery #{$deliveryId}: {$reason}", $deliveryId);
        Response::json(['delivery_id' => $deliveryId], 'Offer declined. Operations has been notified.');
    }

    public static function reportAssignmentIssue(PDO $db, int $userId): void
    {
        $data = json_decode(file_get_contents('php://input')); $deliveryId = (int)($data->delivery_id ?? 0); $reason = trim((string)($data->reason ?? ''));
        if (!$deliveryId || $reason === '') Response::error('delivery_id and an issue description are required.');
        $assignment = $db->prepare("SELECT id, tracking_number FROM deliveries WHERE id = ? AND delivery_person_id = ? AND status IN ('assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived')");
        $assignment->execute([$deliveryId, $userId]); $delivery = $assignment->fetch(PDO::FETCH_ASSOC); if (!$delivery) Response::forbidden('You may report an issue only for your current active assignment.');
        OperationalRecords::audit($db, $userId, 'delivery', 'driver.assignment_issue_reported', 'delivery', $deliveryId, null, ['reason' => $reason], [], $deliveryId);
        NotificationService::publishToRole($db, 'admin', 'admin.driver_assignment_issue', 'Driver needs operations support', "Issue reported for #{$delivery['tracking_number']}: {$reason}", $deliveryId);
        Response::json(['delivery_id' => $deliveryId], 'Issue sent to operations. Continue safely and await instructions.');
    }
}
