<?php
declare(strict_types=1);
require_once __DIR__ . '/transactional_schema.php';
require_once __DIR__ . '/operational_records.php';
require_once __DIR__ . '/http_input.php';

/** Profile rows serialize uploads and reviews; all decisions retain a mandatory audit record. */
final class KycWorkflow
{
    public static function uploadMetadata(array $input): array
    {
        $number = HttpInput::text($input, 'document_number', 160, false);
        $expiry = HttpInput::text($input, 'expires_at', 10, false);
        if ($expiry !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expiry, new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $expiry || $expiry < gmdate('Y-m-d')) throw new TransactionBusinessException('Expiry date must be today or later in YYYY-MM-DD format.', 422);
        }
        return ['document_number' => $number ?: null, 'expires_at' => $expiry ?: null];
    }

    /** Called after storage succeeds; the caller removes the new object on any failure. */
    public static function recordUpload(PDO $db, int $userId, string $role, string $type, string $storageKey, array $metadata, string $retentionUntil): array
    {
        if (!in_array($role, ['client', 'delivery'], true)) throw new LogicException('Unsupported document owner.');
        $client = $role === 'client';
        $profileTable = $client ? 'clients' : 'drivers';
        $documentTable = $client ? 'client_kyc_documents' : 'driver_documents';
        $ownerColumn = $client ? 'client_id' : 'driver_id';
        TransactionalSchema::requireInnoDB($db, [$profileTable, $documentTable, 'audit_logs']);
        return DatabaseTransaction::run($db, static function (PDO $db) use ($userId, $role, $type, $storageKey, $metadata, $retentionUntil, $client, $profileTable, $documentTable, $ownerColumn): array {
            $query = $db->prepare("SELECT id, kyc_status FROM {$profileTable} WHERE user_id = ? FOR UPDATE");
            $query->execute([$userId]); $profile = $query->fetch(PDO::FETCH_ASSOC);
            if (!$profile) DatabaseTransaction::fail('KYC profile not found.', 404);
            if ($client && $profile['kyc_status'] === 'verified') DatabaseTransaction::fail('Your KYC is already verified. Contact operations to update identity details.', 409);
            $previous = false;
            if ($client) {
                $query = $db->prepare('SELECT id, storage_key FROM client_kyc_documents WHERE client_id = ? AND document_type = ? FOR UPDATE');
                $query->execute([$profile['id'], $type]); $previous = $query->fetch(PDO::FETCH_ASSOC);
            }
            if ($previous) {
                $db->prepare("UPDATE client_kyc_documents SET storage_key = ?, document_number = ?, expires_at = ?, retention_until = ?, verification_status = 'pending', rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL WHERE id = ?")->execute([$storageKey, $metadata['document_number'], $metadata['expires_at'], $retentionUntil, $previous['id']]);
                $documentId = (int)$previous['id'];
            } else {
                $db->prepare("INSERT INTO {$documentTable} ({$ownerColumn}, document_type, document_number, storage_key, expires_at, retention_until, verification_status) VALUES (?, ?, ?, ?, ?, ?, 'pending')")->execute([$profile['id'], $type, $metadata['document_number'], $storageKey, $metadata['expires_at'], $retentionUntil]);
                $documentId = (int)$db->lastInsertId();
            }
            $db->prepare("UPDATE {$profileTable} SET kyc_status = 'submitted', kyc_rejection_reason = NULL, kyc_reviewed_by = NULL, kyc_reviewed_at = NULL WHERE id = ? AND kyc_status <> 'verified'")->execute([$profile['id']]);
            OperationalRecords::audit($db, $userId, $role, $client ? 'client.kyc_document_submitted' : 'driver.document_submitted', $client ? 'client_kyc_document' : 'driver_document', $documentId, null, ['document_type' => $type], [], null, true);
            return ['document_id' => $documentId, 'previous_storage_key' => $previous['storage_key'] ?? null];
        }, 3, false);
    }

    public static function clientDecision(PDO $db, int $userId, int $adminId, bool $approve, string $reason = ''): void
    {
        self::reason($approve, $reason);
        TransactionalSchema::requireInnoDB($db, ['clients', 'client_kyc_documents', 'audit_logs']);
        DatabaseTransaction::run($db, static function (PDO $db) use ($userId, $adminId, $approve, $reason): void {
            $query = $db->prepare('SELECT id, kyc_status FROM clients WHERE user_id = ? FOR UPDATE');
            $query->execute([$userId]); $client = $query->fetch(PDO::FETCH_ASSOC);
            if (!$client) DatabaseTransaction::fail('Client not found.', 404);
            if (!in_array($client['kyc_status'], ['submitted', 'under_review'], true)) DatabaseTransaction::fail('Only submitted client KYC records can be reviewed.', 409);
            $query = $db->prepare('SELECT verification_status, expires_at FROM client_kyc_documents WHERE client_id = ? FOR UPDATE');
            $query->execute([$client['id']]); $documents = $query->fetchAll(PDO::FETCH_ASSOC);
            if ($approve) {
                if (!$documents) DatabaseTransaction::fail('Upload at least one KYC document before approval.', 422);
                $pending = 0;
                foreach ($documents as $document) {
                    if (in_array($document['verification_status'], ['rejected', 'expired'], true) || self::expired($document)) DatabaseTransaction::fail('Replace rejected or expired KYC documents before approval.', 422);
                    if ($document['verification_status'] === 'pending') $pending++;
                }
                if (!$pending) DatabaseTransaction::fail('No pending documents are available for approval.', 409);
            }
            $status = $approve ? 'verified' : 'rejected';
            $db->prepare('UPDATE clients SET kyc_status = ?, kyc_rejection_reason = ?, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE id = ?')->execute([$status, $approve ? null : $reason, $adminId, $client['id']]);
            $db->prepare("UPDATE client_kyc_documents SET verification_status = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE client_id = ? AND verification_status = 'pending'")->execute([$status, $approve ? null : $reason, $adminId, $client['id']]);
            OperationalRecords::audit($db, $adminId, 'admin', 'client.kyc_' . ($approve ? 'approved' : 'rejected'), 'client', (int)$client['id'], ['kyc_status' => $client['kyc_status']], ['kyc_status' => $status], ['reason' => $approve ? null : $reason], null, true);
        }, 3, false);
    }

    public static function driverDecision(PDO $db, int $userId, int $adminId, bool $approve, string $reason = ''): void
    {
        self::reason($approve, $reason);
        TransactionalSchema::requireInnoDB($db, ['users', 'drivers', 'driver_documents', 'driver_availability', 'audit_logs']);
        DatabaseTransaction::run($db, static function (PDO $db) use ($userId, $adminId, $approve, $reason): void {
            $query = $db->prepare("SELECT id, account_status, is_approved FROM users WHERE id = ? AND role = 'delivery' FOR UPDATE");
            $query->execute([$userId]); $account = $query->fetch(PDO::FETCH_ASSOC);
            if (!$account) DatabaseTransaction::fail('Delivery partner not found.', 404);
            $query = $db->prepare('SELECT id, kyc_status, active_status FROM drivers WHERE user_id = ? FOR UPDATE');
            $query->execute([$userId]); $driver = $query->fetch(PDO::FETCH_ASSOC);
            if (!$driver) DatabaseTransaction::fail('Driver profile is unavailable.', 409);
            if ((int)$account['is_approved'] === 1 || !in_array($driver['kyc_status'], ['submitted', 'under_review'], true)) DatabaseTransaction::fail('Only pending partner KYC can be reviewed.', 409);
            if ($approve && ($account['account_status'] !== 'active' || $driver['active_status'] === 'suspended')) DatabaseTransaction::fail('Resolve the account suspension separately before KYC approval.', 409);
            $query = $db->prepare('SELECT document_type, verification_status, expires_at FROM driver_documents WHERE driver_id = ? FOR UPDATE');
            $query->execute([$driver['id']]); $documents = $query->fetchAll(PDO::FETCH_ASSOC);
            if ($approve) {
                $verified = [];
                foreach ($documents as $document) {
                    if ($document['verification_status'] === 'verified' && !self::expired($document)) $verified[$document['document_type']] = true;
                }
                if (empty($verified['government_id']) || empty($verified['drivers_license'])) DatabaseTransaction::fail('Verify an unexpired government ID and driver licence before approving this partner.', 422);
            }
            $status = $approve ? 'verified' : 'rejected';
            $db->prepare('UPDATE users SET is_approved = ? WHERE id = ?')->execute([$approve ? 1 : 0, $userId]);
            $active = $approve ? 'active' : ($driver['active_status'] === 'suspended' ? 'suspended' : 'inactive');
            $db->prepare('UPDATE drivers SET kyc_status = ?, active_status = ?, kyc_rejection_reason = ?, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE id = ?')->execute([$status, $active, $approve ? null : $reason, $adminId, $driver['id']]);
            if (!$approve) $db->prepare("UPDATE driver_documents SET verification_status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE driver_id = ? AND verification_status = 'pending'")->execute([$reason, $adminId, $driver['id']]);
            $db->prepare("INSERT IGNORE INTO driver_availability (driver_id, availability_status) VALUES (?, 'offline')")->execute([$driver['id']]);
            $db->prepare("UPDATE driver_availability SET availability_status = 'offline' WHERE driver_id = ?")->execute([$driver['id']]);
            OperationalRecords::audit($db, $adminId, 'admin', 'driver.kyc_' . ($approve ? 'approved' : 'rejected'), 'driver', (int)$driver['id'], ['kyc_status' => $driver['kyc_status']], ['kyc_status' => $status], ['reason' => $approve ? null : $reason], null, true);
        }, 3, false);
    }

    public static function reviewDriverDocument(PDO $db, int $documentId, int $adminId, string $decision, string $reason): array
    {
        if (!in_array($decision, ['verified', 'rejected'], true)) throw new TransactionBusinessException('Decision must be verified or rejected.', 422);
        self::reason($decision === 'verified', $reason);
        TransactionalSchema::requireInnoDB($db, ['users', 'drivers', 'driver_documents', 'audit_logs']);
        // Resolve the immutable parent ID first; locked reads below re-check the document.
        $query = $db->prepare('SELECT d.user_id FROM driver_documents dd JOIN drivers d ON d.id = dd.driver_id WHERE dd.id = ?');
        $query->execute([$documentId]); $userId = (int)$query->fetchColumn();
        if (!$userId) throw new TransactionBusinessException('Driver document not found.', 404);
        return DatabaseTransaction::run($db, static function (PDO $db) use ($documentId, $adminId, $decision, $reason, $userId): array {
            $query = $db->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE'); $query->execute([$userId]); $query->fetchColumn();
            $query = $db->prepare('SELECT id FROM drivers WHERE user_id = ? FOR UPDATE'); $query->execute([$userId]); $driverId = (int)$query->fetchColumn();
            $query = $db->prepare('SELECT id, document_type, verification_status, expires_at FROM driver_documents WHERE id = ? AND driver_id = ? FOR UPDATE');
            $query->execute([$documentId, $driverId]); $document = $query->fetch(PDO::FETCH_ASSOC);
            if (!$document) DatabaseTransaction::fail('Driver document not found.', 404);
            if ($document['verification_status'] !== 'pending') DatabaseTransaction::fail('Only pending documents can be reviewed.', 409);
            if ($decision === 'verified' && self::expired($document)) DatabaseTransaction::fail('An expired document cannot be verified.', 422);
            $db->prepare('UPDATE driver_documents SET verification_status = ?, reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ?')->execute([$decision, $adminId, $decision === 'rejected' ? $reason : null, $documentId]);
            $status = $decision === 'rejected' ? 'rejected' : 'under_review';
            $db->prepare("UPDATE drivers SET kyc_status = ?, kyc_rejection_reason = ?, kyc_reviewed_by = ?, kyc_reviewed_at = NOW() WHERE id = ? AND kyc_status <> 'verified'")->execute([$status, $decision === 'rejected' ? $reason : null, $adminId, $driverId]);
            OperationalRecords::audit($db, $adminId, 'admin', 'driver.document_reviewed', 'driver_document', $documentId, ['verification_status' => 'pending'], ['verification_status' => $decision], ['reason' => $reason], null, true);
            return $document + ['user_id' => $userId];
        }, 3, false);
    }

    private static function expired(array $document): bool { return !empty($document['expires_at']) && $document['expires_at'] < gmdate('Y-m-d'); }
    private static function reason(bool $approve, string $reason): void
    {
        if ((!$approve && trim($reason) === '') || mb_strlen($reason) > 500) throw new TransactionBusinessException('Provide a reason of at most 500 characters.', 422);
    }
}
