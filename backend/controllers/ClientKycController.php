<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/kyc_workflow.php';
require_once __DIR__ . '/../helpers/operations_schema.php';
require_once __DIR__ . '/../helpers/operational_records.php';
require_once __DIR__ . '/../helpers/notification_service.php';
require_once __DIR__ . '/../helpers/storage_adapter.php';
require_once __DIR__ . '/../helpers/secure_document_download.php';
require_once __DIR__ . '/../helpers/job_queue.php';
require_once __DIR__ . '/../helpers/monitoring.php';

/** Client-owned KYC status, upload, and securely authorised document access. */
final class ClientKycController
{
    private const DOCUMENT_TYPES = ['national_id', 'passport', 'utility_bill', 'business_registration', 'other'];
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public static function getKycStatus(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['clients', 'client_kyc_documents']);
        $clientId = OperationsSchema::ensureClientProfile($db, $userId);
        if (!$clientId) Response::serverError('Unable to initialise the client KYC profile.');

        $profile = $db->prepare('SELECT kyc_status, kyc_rejection_reason, kyc_reviewed_at FROM clients WHERE id = ?');
        $profile->execute([$clientId]);
        $documents = $db->prepare('SELECT id, document_type, document_number, expires_at, verification_status, rejection_reason, created_at, reviewed_at FROM client_kyc_documents WHERE client_id = ? ORDER BY created_at DESC');
        $documents->execute([$clientId]);

        Response::json([
            'client_id' => $clientId,
            ...($profile->fetch(PDO::FETCH_ASSOC) ?: ['kyc_status' => 'not_submitted', 'kyc_rejection_reason' => null, 'kyc_reviewed_at' => null]),
            'documents' => $documents->fetchAll(PDO::FETCH_ASSOC),
        ], 'Client KYC status retrieved.');
    }

    /** Accept one document per type; a re-upload safely replaces the previous version. */
    public static function uploadDocument(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['clients', 'client_kyc_documents']);
        $clientId = OperationsSchema::ensureClientProfile($db, $userId);
        if (!$clientId) Response::serverError('Unable to initialise the client KYC profile.');

        $documentType = trim((string)($_POST['document_type'] ?? ''));
        if (!in_array($documentType, self::DOCUMENT_TYPES, true)) {
            Response::error('Choose a supported KYC document type.', 422);
        }
        try {
            $upload = UploadSecurity::validateUploadedFile($_FILES['document'] ?? [], self::MAX_FILE_BYTES);
        } catch (UploadSecurityException $exception) {
            Response::error($exception->getMessage(), $exception->httpStatus());
        }
        try { $metadata = KycWorkflow::uploadMetadata($_POST); }
        catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        $file = $_FILES['document'];
        $mime = $upload['mime'];


        $status = $db->prepare('SELECT kyc_status FROM clients WHERE id = ?');
        $status->execute([$clientId]);
        if ($status->fetchColumn() === 'verified') {
            Response::error('Your KYC is already verified. Contact operations to update verified identity details.', 409);
        }

        $storageKey = sprintf('client-kyc/%d/%s.%s', $clientId, UploadSecurity::uuidV4(), $upload['extension']);
        try {
            $retentionUntil = UploadSecurity::retentionUntil()->format('Y-m-d H:i:s');
        } catch (RuntimeException $exception) {
            Logger::error('KYC retention policy is unavailable', ['exception' => $exception->getMessage()]);
            Response::error('Secure document retention is not configured. Please try again later.', 503);
        }
        try {
            $stored = Storage::adapter()->put($storageKey, $file['tmp_name'], $mime, self::MAX_FILE_BYTES);
        } catch (UploadSecurityException $exception) {
            Response::error($exception->getMessage(), $exception->httpStatus());
        } catch (Throwable $exception) {
            Monitoring::storageFailure('kyc.upload', ['exception' => $exception->getMessage(), 'client_id' => $clientId]);
            Logger::error('KYC document storage is unavailable', ['exception' => $exception->getMessage()]);
            Response::error('Secure document storage is temporarily unavailable. Please try again later.', 503);
        }
        if (!$stored) {
            Response::error('Secure document storage is temporarily unavailable. Please try again later.', 503);
        }

        try {
            $result = KycWorkflow::recordUpload($db, $userId, 'client', $documentType, $storageKey, $metadata, $retentionUntil);
            $documentId = $result['document_id'];
            $previous = ['storage_key' => $result['previous_storage_key']];
        } catch (Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            try { Storage::adapter()->delete($storageKey); }
            catch (Throwable $cleanup) { Logger::error('Uncommitted KYC object cleanup failed.'); }
            if ($exception instanceof TransactionBusinessException) Response::error($exception->getMessage(), $exception->getStatusCode());
            Logger::exception($exception, 'Client KYC metadata could not be saved');
            Response::serverError('Unable to save the KYC document submission.');
        }

        if (!empty($previous['storage_key']) && $previous['storage_key'] !== $storageKey) {
            JobQueue::setDb($db);
            $queued = JobQueue::push('storage.delete', [
                'storage_key' => $previous['storage_key'],
                'reason' => 'client_kyc_document_replaced',
                'document_id' => $documentId,
            ]);
            if (!$queued) {
                Monitoring::storageFailure('kyc.replaced_document_queue', ['document_id' => $documentId]);
                Logger::error('Could not queue replaced KYC document deletion.', [
                    'storage_key' => $previous['storage_key'],
                    'document_id' => $documentId,
                ]);
            }
        }
        NotificationService::publishToRole($db, 'admin', 'admin.client_kyc_submitted', 'Client KYC awaiting review', 'A client has submitted or updated KYC documents for review.');
        NotificationService::publish($db, $userId, 'client.kyc_submitted', 'KYC submitted', 'Your KYC document was received and is awaiting operations review.');

        Response::json(['document_id' => $documentId, 'kyc_status' => 'submitted'], 'KYC document submitted for review.', 201);
    }

    public static function getDocumentFile(PDO $db, int $userId): void
    {
        OperationsSchema::requireTables($db, ['clients', 'client_kyc_documents']);
        $documentId = (int)($_GET['document_id'] ?? 0);
        if (!$documentId) Response::error('document_id is required.');
        $document = $db->prepare('SELECT d.storage_key, d.document_type FROM client_kyc_documents d JOIN clients c ON c.id = d.client_id WHERE d.id = ? AND c.user_id = ?');
        $document->execute([$documentId, $userId]);
        $row = $document->fetch(PDO::FETCH_ASSOC);
        if (!$row) Response::notFound('KYC document not found.');
        OperationalRecords::audit(
            $db,
            $userId,
            'client',
            'client.kyc_document_downloaded',
            'client_kyc_document',
            $documentId
        );
        SecureDocumentDownload::send($row['storage_key'], 'client-kyc-' . $documentId . '-' . $row['document_type']);
    }

    public static function getDocumentFileAdmin(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['client_kyc_documents']);
        $documentId = (int)($_GET['document_id'] ?? 0);
        if (!$documentId) Response::error('document_id is required.');
        $document = $db->prepare('SELECT storage_key, document_type FROM client_kyc_documents WHERE id = ?');
        $document->execute([$documentId]);
        $row = $document->fetch(PDO::FETCH_ASSOC);
        if (!$row) Response::notFound('KYC document not found.');
        OperationalRecords::audit(
            $db,
            $adminId,
            'admin',
            'admin.client_kyc_document_downloaded',
            'client_kyc_document',
            $documentId
        );
        SecureDocumentDownload::send($row['storage_key'], 'client-kyc-' . $documentId . '-' . $row['document_type']);
    }
}
