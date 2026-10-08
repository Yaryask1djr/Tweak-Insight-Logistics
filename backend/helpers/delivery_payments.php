<?php
declare(strict_types=1);
require_once __DIR__ . '/payment_money.php';
require_once __DIR__ . '/paystack_gateway.php';
require_once __DIR__ . '/operational_records.php';

/** Fare versions, checkout reservations and verified payment evidence. No network calls hold DB locks. */
final class DeliveryPayments
{
    private const BEFORE_PICKUP = ['under_review', 'broadcasted', 'assigned', 'driver_en_route'];
    private static ?PDO $validatedDb = null;
    public static function transaction(PDO $db, callable $work): mixed { return DatabaseTransaction::run($db, $work, 3, false); }
    public static function inputHash(array $delivery): string
    {
        $values = [];
        foreach (['client_id','service_type','pickup_address','pickup_city','pickup_latitude','pickup_longitude',
            'delivery_address','delivery_city','delivery_latitude','delivery_longitude','item_description','item_category',
            'item_quantity','item_weight','item_dimensions','is_fragile','is_perishable','distance_km',
            'preferred_pickup_time','preferred_delivery_time'] as $field) {
            $values[$field] = isset($delivery[$field]) ? (string)$delivery[$field] : null;
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
    public static function approveFare(PDO $db, int $deliveryId, int $adminId, int $expectedVersion, mixed $amount, string $reason): array
    {
        $minor = PaymentMoney::minor($amount); $reason = trim($reason);
        if ($expectedVersion < 0 || $reason === '' || mb_strlen($reason, 'UTF-8') > 500 || str_contains($reason, "\0")) throw new TransactionBusinessException('Supply the current fare version and an approval reason of 1–500 characters.', 422);
        self::assertSchema($db);
        return self::transaction($db, static function (PDO $db) use ($deliveryId, $adminId, $expectedVersion, $minor, $reason): array {
            $delivery = self::delivery($db, $deliveryId, true);
            $admin = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'admin' AND account_status = 'active' FOR UPDATE");
            $admin->execute([$adminId]);
            if (!$admin->fetchColumn()) throw new TransactionBusinessException('Only active operations administrators may approve fares.', 403);
            $fare = self::latestFare($db, $deliveryId, true);
            $version = (int)($fare['version'] ?? 0); $hash = self::inputHash($delivery);
            // A lost response can be retried without adding another identical approval.
            if ($fare && $version === $expectedVersion + 1 && (int)$fare['amount_minor'] === $minor
                && (int)$fare['approved_by'] === $adminId && $fare['approval_reason'] === $reason && hash_equals($fare['input_hash'], $hash)) return self::fareView($fare);
            if ($version !== $expectedVersion) throw new TransactionBusinessException('The fare changed. Refresh and review the current version.', 409);
            self::beforePickup($delivery);
            if ($delivery['payment_status'] !== 'unpaid') throw new TransactionBusinessException('Payment has already started or needs reconciliation; the fare cannot be replaced.', 409);
            $payments = $db->prepare('SELECT COUNT(*) FROM delivery_payment_attempts WHERE delivery_id = ?'); $payments->execute([$deliveryId]);
            if ((int)$payments->fetchColumn()) throw new TransactionBusinessException('A checkout already exists. Reconcile it before any fare change.', 409);
            $db->prepare('INSERT INTO delivery_fare_approvals (delivery_id, version, amount_minor, currency, input_hash, approved_by, approval_reason) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$deliveryId, $version + 1, $minor, 'NGN', $hash, $adminId, $reason]);
            $db->prepare('UPDATE deliveries SET total_cost = ? WHERE id = ?')->execute([PaymentMoney::decimal($minor), $deliveryId]);
            OperationalRecords::audit($db, $adminId, 'admin', 'delivery.fare_approved', 'delivery', $deliveryId,
                ['total_cost' => $delivery['total_cost'], 'fare_version' => $version], ['amount_minor' => $minor, 'fare_version' => $version + 1], ['reason' => $reason], $deliveryId, true);
            return self::fareView(self::latestFare($db, $deliveryId));
        });
    }
    public static function initialize(PDO $db, int $deliveryId, int $clientId, int $fareVersion, PaymentGateway $gateway): array
    {
        self::assertSchema($db); $mode = $gateway->mode();
        $attempt = self::transaction($db, static function (PDO $db) use ($deliveryId, $clientId, $fareVersion, $mode): array {
            $delivery = self::delivery($db, $deliveryId, true); self::owner($delivery, $clientId); self::beforePickup($delivery);
            $fare = self::latestFare($db, $deliveryId, true);
            if (!$fare || (int)$fare['version'] !== $fareVersion || !self::fareMatches($fare, $delivery)) throw new TransactionBusinessException('Operations must approve the current final fare before payment. Refresh to review it.', 409);
            if (!in_array($delivery['payment_status'], ['unpaid', 'pending'], true)) throw new TransactionBusinessException('This delivery already has a payment state requiring review; do not pay again.', 409);
            $user = $db->prepare("SELECT email FROM users WHERE id = ? AND role = 'client' AND account_status = 'active' FOR UPDATE"); $user->execute([$clientId]);
            $email = $user->fetchColumn();
            if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new TransactionBusinessException('A valid active client account is required for payment.', 403);
            $find = $db->prepare('SELECT * FROM delivery_payment_attempts WHERE fare_approval_id = ? AND environment = ? FOR UPDATE'); $find->execute([$fare['id'], $mode]);
            $attempt = $find->fetch(PDO::FETCH_ASSOC);
            if (!$attempt) {
                $other = $db->prepare('SELECT COUNT(*) FROM delivery_payment_attempts WHERE delivery_id = ?'); $other->execute([$deliveryId]);
                if ((int)$other->fetchColumn()) throw new TransactionBusinessException('An earlier checkout needs reconciliation. Do not mix payment environments.', 409);
                $id = bin2hex(random_bytes(16));
                $db->prepare('INSERT INTO delivery_payment_attempts (id, reference, delivery_id, client_id, fare_approval_id, provider, environment, amount_minor, currency, payer_email)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$id, 'TILPAY-' . $id, $deliveryId, $clientId, $fare['id'], 'paystack', $mode, $fare['amount_minor'], 'NGN', strtolower($email)]);
                $attempt = self::attempt($db, $id, true);
                OperationalRecords::audit($db, $clientId, 'client', 'payment.checkout_requested', 'delivery', $deliveryId, null,
                    ['reference' => $attempt['reference'], 'fare_version' => $fareVersion, 'amount_minor' => (int)$fare['amount_minor'], 'environment' => $mode], [], $deliveryId, true);
            }
            if (in_array($attempt['status'], ['verified', 'review_required'], true)) throw new TransactionBusinessException('Check the recorded payment with operations; do not start another payment.', 409);
            if ($attempt['authorization_url']) return $attempt + ['initialize' => false];
            if ($attempt['status'] === 'initializing' && strtotime($attempt['initializing_until'] . ' UTC') > time()) throw new TransactionBusinessException('Checkout is being prepared. Refresh its status in a moment.', 409);
            $token = bin2hex(random_bytes(16));
            $db->prepare("UPDATE delivery_payment_attempts SET status = 'initializing', initialization_token = ?, initializing_until = ?, attention_reason = NULL WHERE id = ?")
                ->execute([$token, gmdate('Y-m-d H:i:s', time() + 60), $attempt['id']]);
            $db->prepare("UPDATE deliveries SET payment_status = 'pending' WHERE id = ?")->execute([$deliveryId]);
            return array_replace($attempt, ['initialize' => true, 'initialization_token' => $token]);
        });
        if ($attempt['initialize']) {
            try {
                $checkout = $gateway->initialize($attempt); // Never inside a DB transaction.
                if (($checkout['reference'] ?? '') !== $attempt['reference']) throw new PaymentGatewayException('Payment provider reference mismatch.');
                $url = PaystackGateway::checkoutUrl($checkout['authorization_url'] ?? null);
                $db->prepare("UPDATE delivery_payment_attempts SET authorization_url = ?, status = 'pending', initialization_token = NULL, initializing_until = NULL, attention_reason = NULL
                    WHERE id = ? AND initialization_token = ? AND status = 'initializing' AND initializing_until >= UTC_TIMESTAMP()")
                    ->execute([$url, $attempt['id'], $attempt['initialization_token']]);
            } catch (PaymentGatewayException $e) {
                $db->prepare("UPDATE delivery_payment_attempts SET status = 'pending', initialization_token = NULL, initializing_until = NULL, attention_reason = 'initialization_unconfirmed'
                    WHERE id = ? AND initialization_token = ? AND status = 'initializing'")->execute([$attempt['id'], $attempt['initialization_token']]);
                throw $e; // Keep this reference; never issue another one after an uncertain response.
            }
        }
        return self::view($db, $deliveryId, $clientId);
    }
    public static function verifyOwned(PDO $db, string $reference, int $clientId, PaymentGateway $gateway): array
    {
        PaystackGateway::reference($reference);
        $find = $db->prepare('SELECT id, delivery_id, environment FROM delivery_payment_attempts WHERE reference = ? AND client_id = ?'); $find->execute([$reference, $clientId]);
        $attempt = $find->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) throw new TransactionBusinessException('Payment not found.', 404);
        if ($attempt['environment'] !== $gateway->mode()) throw new TransactionBusinessException('This payment belongs to another payment environment.', 409);
        self::reconcile($db, $attempt['id'], $gateway->verify($reference));
        return self::view($db, (int)$attempt['delivery_id'], $clientId);
    }
    /** Receives only a server Verify API result, never a browser or webhook success claim. */
    public static function reconcile(PDO $db, string $attemptId, array $data): void
    {
        self::assertSchema($db); $known = self::attempt($db, $attemptId);
        self::transaction($db, static function (PDO $db) use ($attemptId, $data, $known): void {
            $delivery = self::delivery($db, (int)$known['delivery_id'], true); $attempt = self::attempt($db, $attemptId, true);
            if (($data['reference'] ?? null) !== $attempt['reference'] || ($data['domain'] ?? null) !== $attempt['environment']) {
                self::holdLocked($db, $attempt, 'verification_binding_mismatch'); return;
            }
            $providerStatus = $data['status'] ?? '';
            if (!is_string($providerStatus) || !in_array($providerStatus, ['success','failed','abandoned','ongoing','pending','processing','queued','reversed'], true)) {
                self::holdLocked($db, $attempt, 'invalid_provider_status'); return;
            }
            $db->prepare('UPDATE delivery_payment_attempts SET last_provider_status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$providerStatus, $attemptId]);
            if ($providerStatus === 'reversed') { self::holdLocked($db, $attempt, 'provider_reversed'); return; }
            if ($providerStatus !== 'success') return; // A pending/stale response cannot undo an earlier receipt.
            $problem = self::verificationProblem($attempt, $data);
            if ($problem !== null) { self::holdLocked($db, $attempt, $problem); return; }
            $existing = $db->prepare('SELECT * FROM delivery_payment_receipts WHERE attempt_id = ?'); $existing->execute([$attemptId]); $receipt = $existing->fetch(PDO::FETCH_ASSOC);
            if ($receipt) {
                if ($receipt['provider_transaction_id'] !== (string)$data['id']) self::holdLocked($db, $attempt, 'provider_transaction_changed');
                return; // Includes held receipts: a replay must never remove a refund/dispute hold.
            }
            $used = $db->prepare('SELECT COUNT(*) FROM delivery_payment_receipts WHERE provider = ? AND environment = ? AND provider_transaction_id = ?');
            $used->execute(['paystack', $attempt['environment'], (string)$data['id']]);
            if ((int)$used->fetchColumn()) { self::holdLocked($db, $attempt, 'provider_transaction_reused'); return; }
            $evidence = ['id' => (string)$data['id'], 'reference' => $data['reference'], 'domain' => $data['domain'], 'status' => 'success',
                'amount' => $data['amount'], 'currency' => $data['currency'], 'metadata' => PaystackGateway::metadata($attempt),
                'payer_email_hash' => hash('sha256', $attempt['payer_email']), 'paid_at' => $data['paid_at']];
            $paidAt = (new DateTimeImmutable($data['paid_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $db->prepare('INSERT INTO delivery_payment_receipts (attempt_id, delivery_id, fare_approval_id, client_id, provider, environment,
                reference, provider_transaction_id, amount_minor, currency, provider_paid_at, evidence_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$attemptId, $attempt['delivery_id'], $attempt['fare_approval_id'], $attempt['client_id'], 'paystack', $attempt['environment'],
                    $attempt['reference'], (string)$data['id'], $attempt['amount_minor'], $attempt['currency'], $paidAt, hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR))]);
            $db->prepare("UPDATE delivery_payment_attempts SET status = CASE WHEN status = 'review_required' THEN status ELSE 'verified' END,
                initialization_token = NULL, initializing_until = NULL WHERE id = ?")->execute([$attemptId]);
            $db->prepare("UPDATE deliveries SET payment_status = 'paid' WHERE id = ?")->execute([$delivery['id']]);
            OperationalRecords::audit($db, null, 'system', 'payment.provider_verified', 'delivery', (int)$delivery['id'], null,
                ['reference' => $attempt['reference'], 'amount_minor' => (int)$attempt['amount_minor'], 'currency' => $attempt['currency'], 'environment' => $attempt['environment']],
                ['provider_transaction_id' => (string)$data['id'], 'verification_source' => 'paystack_verify_api'], (int)$delivery['id'], true);
            $fare = self::latestFare($db, (int)$delivery['id']);
            if (!$fare || (int)$fare['id'] !== (int)$attempt['fare_approval_id'] || !self::fareMatches($fare, $delivery)) self::holdLocked($db, $attempt, 'fare_or_delivery_changed');
            elseif (in_array($delivery['status'], ['cancelled','rejected','failed'], true)) self::holdLocked($db, $attempt, 'closed_delivery_payment');
        });
    }
    private static function verificationProblem(array $attempt, array $data): ?string
    {
        if (!is_int($data['amount'] ?? null) || $data['amount'] !== (int)$attempt['amount_minor'] || ($data['currency'] ?? null) !== $attempt['currency']) return 'amount_or_currency_mismatch';
        $id = $data['id'] ?? null;
        if ((!is_string($id) && !is_int($id)) || !preg_match('/\A[1-9][0-9]{0,19}\z/', (string)$id)
            || (strlen((string)$id) === 20 && strcmp((string)$id, '18446744073709551615') > 0)) return 'invalid_provider_transaction';
        $email = $data['customer']['email'] ?? null;
        if (!is_string($email) || strtolower($email) !== strtolower($attempt['payer_email'])) return 'payer_mismatch';
        $metadata = $data['metadata'] ?? null;
        if (is_string($metadata)) { try { $metadata = json_decode($metadata, true, 8, JSON_THROW_ON_ERROR); } catch (JsonException $e) { return 'metadata_mismatch'; } }
        if (!is_array($metadata)) return 'metadata_mismatch';
        foreach (PaystackGateway::metadata($attempt) as $key => $value) {
            if ((!is_string($metadata[$key] ?? null) && !is_int($metadata[$key] ?? null)) || (string)$metadata[$key] !== $value) return 'metadata_mismatch';
        }
        if (!is_string($data['paid_at'] ?? null) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $data['paid_at'])) return 'invalid_payment_time';
        try { $date = new DateTimeImmutable($data['paid_at']); $errors = DateTimeImmutable::getLastErrors(); }
        catch (Exception $e) { return 'invalid_payment_time'; }
        if (($errors && ($errors['error_count'] || $errors['warning_count'])) || $date->getTimestamp() > time() + 300) return 'invalid_payment_time';
        return null;
    }
    public static function hold(PDO $db, string $attemptId, string $reason): void
    {
        self::transaction($db, static fn(PDO $db) => self::holdInTransaction($db, $attemptId, $reason));
    }
    public static function holdInTransaction(PDO $db, string $attemptId, string $reason): void
    {
        if (!$db->inTransaction()) throw new LogicException('A payment hold requires a transaction.');
        $known = self::attempt($db, $attemptId);
        self::delivery($db, (int)$known['delivery_id'], true);
        self::holdLocked($db, self::attempt($db, $attemptId, true), $reason);
    }
    private static function holdLocked(PDO $db, array $attempt, string $reason): void
    {
        if ($attempt['status'] === 'review_required' && $attempt['attention_reason'] === $reason) return;
        $db->prepare("UPDATE delivery_payment_attempts SET status = 'review_required', attention_reason = ?, initialization_token = NULL, initializing_until = NULL WHERE id = ?")
            ->execute([$reason, $attempt['id']]);
        OperationalRecords::audit($db, null, 'system', 'payment.review_required', 'delivery', (int)$attempt['delivery_id'], null,
            ['reference' => $attempt['reference'], 'reason' => $reason], [], (int)$attempt['delivery_id'], true);
    }
    public static function view(PDO $db, int $deliveryId, ?int $clientId): array
    {
        $delivery = self::delivery($db, $deliveryId); if ($clientId !== null) self::owner($delivery, $clientId);
        $fare = self::latestFare($db, $deliveryId);
        $find = $db->prepare('SELECT * FROM delivery_payment_attempts WHERE delivery_id = ? ORDER BY created_at DESC, id DESC LIMIT 1'); $find->execute([$deliveryId]); $attempt = $find->fetch(PDO::FETCH_ASSOC);
        $receipt = null;
        if ($attempt) { $r = $db->prepare('SELECT reference, amount_minor, currency, environment, provider_paid_at, verified_at FROM delivery_payment_receipts WHERE attempt_id = ?'); $r->execute([$attempt['id']]); $receipt = $r->fetch(PDO::FETCH_ASSOC) ?: null; }
        $ready = $fare && self::fareMatches($fare, $delivery);
        $available = true; try { $gateway = new PaystackGateway(); if ($attempt && $attempt['environment'] !== $gateway->mode()) $available = false; } catch (PaymentConfigurationException $e) { $available = false; }
        return ['delivery_id' => $deliveryId, 'tracking_number' => $delivery['tracking_number'], 'status' => $delivery['status'],
            'fare' => $fare ? self::fareView($fare) : null, 'fare_current' => (bool)$ready, 'payments_available' => $available,
            'can_pay' => $available && $ready && in_array($delivery['status'], self::BEFORE_PICKUP, true) && !$delivery['pickup_time']
                && in_array($delivery['payment_status'], ['unpaid','pending'], true) && (!$attempt || !in_array($attempt['status'], ['verified','review_required'], true)),
            'can_approve' => !$attempt && $delivery['payment_status'] === 'unpaid' && !$delivery['pickup_time'] && in_array($delivery['status'], self::BEFORE_PICKUP, true),
            'attempt' => $attempt ? ['reference' => $attempt['reference'], 'status' => $attempt['status'], 'environment' => $attempt['environment'],
                'provider_status' => $attempt['last_provider_status'], 'attention_reason' => $attempt['attention_reason'],
                'authorization_url' => $available && $ready && !$delivery['pickup_time'] && $attempt['status'] === 'pending' && in_array($delivery['status'], self::BEFORE_PICKUP, true) ? $attempt['authorization_url'] : null] : null,
            'receipt' => $receipt];
    }
    /** Single bounded query for driver-list flags; no payment references or URLs are returned to drivers. */
    public static function pickupFlags(PDO $db, array $deliveries, bool $lock = false): array
    {
        if (!$deliveries) return [];
        $flags = array_fill_keys(array_column($deliveries, 'id'), false);
        try { $mode = PaystackGateway::configuredMode(); } catch (PaymentConfigurationException $e) { return $flags; }
        $ids = array_column($deliveries, 'id');
        $query = $db->prepare('SELECT f.*, a.status AS attempt_status, a.attention_reason, r.id AS receipt_id,
            r.amount_minor AS received_minor, r.currency AS received_currency, r.client_id AS payer_client_id,
            r.delivery_id AS paid_delivery_id, r.fare_approval_id AS paid_fare_id, r.environment AS receipt_environment
            FROM delivery_fare_approvals f LEFT JOIN delivery_payment_attempts a ON a.fare_approval_id = f.id AND a.environment = ? AND a.provider = \'paystack\'
            LEFT JOIN delivery_payment_receipts r ON r.attempt_id = a.id AND r.provider = \'paystack\'
            WHERE f.delivery_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
            AND NOT EXISTS (SELECT 1 FROM delivery_fare_approvals newer WHERE newer.delivery_id = f.delivery_id AND newer.version > f.version)' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$mode, ...$ids]); $proof = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $proof[$row['delivery_id']] = $row;
        foreach ($deliveries as $delivery) {
            $f = $proof[$delivery['id']] ?? null;
            $flags[$delivery['id']] = $delivery['payment_status'] === 'paid' && $f && self::fareMatches($f, $delivery)
                && $f['attempt_status'] === 'verified' && $f['receipt_id'] !== null && $f['receipt_environment'] === $mode
                && (int)$f['received_minor'] === (int)$f['amount_minor'] && $f['received_currency'] === 'NGN'
                && (int)$f['payer_client_id'] === (int)$delivery['client_id'] && (int)$f['paid_delivery_id'] === (int)$delivery['id'] && (int)$f['paid_fare_id'] === (int)$f['id'];
        }
        return $flags;
    }
    public static function requirePickupEvidence(PDO $db, array $delivery): void
    {
        if (!$db->inTransaction()) throw new LogicException('Payment evidence must be checked inside the pickup transaction.');
        self::assertSchema($db);
        if (!(self::pickupFlags($db, [$delivery], true)[$delivery['id']] ?? false)) throw new TransactionBusinessException('Pickup requires a current approved fare and a matching provider-verified payment. Do not collect the package; contact operations.', 409);
    }
    public static function attempt(PDO $db, string $id, bool $lock = false): array
    {
        $stmt = $db->prepare('SELECT * FROM delivery_payment_attempts WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')); $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!$row) throw new TransactionBusinessException('Payment not found.', 404); return $row;
    }
    private static function delivery(PDO $db, int $id, bool $lock = false): array
    {
        $stmt = $db->prepare('SELECT * FROM deliveries WHERE id = ?' . ($lock ? ' FOR UPDATE' : '')); $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!$row) throw new TransactionBusinessException('Delivery not found.', 404); return $row;
    }
    private static function latestFare(PDO $db, int $id, bool $lock = false): array|false
    {
        $stmt = $db->prepare('SELECT * FROM delivery_fare_approvals WHERE delivery_id = ? ORDER BY version DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : '')); $stmt->execute([$id]); return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    private static function fareView(array $fare): array
    {
        return ['version' => (int)$fare['version'], 'amount_minor' => (int)$fare['amount_minor'], 'currency' => $fare['currency'], 'approved_at' => $fare['approved_at'], 'reason' => $fare['approval_reason']];
    }
    private static function owner(array $delivery, int $clientId): void { if ((int)$delivery['client_id'] !== $clientId) throw new TransactionBusinessException('Payment not found.', 404); }
    private static function beforePickup(array $delivery): void
    {
        if (!in_array($delivery['status'], self::BEFORE_PICKUP, true) || $delivery['pickup_time'] !== null) throw new TransactionBusinessException('Fare approval and checkout are available after review and before pickup.', 409);
    }
    private static function fareMatches(array $fare, array $delivery): bool
    {
        try { $minor = PaymentMoney::minor($delivery['total_cost']); } catch (TransactionBusinessException $e) { return false; }
        return $fare['currency'] === 'NGN' && (int)$fare['amount_minor'] === $minor && hash_equals($fare['input_hash'], self::inputHash($delivery));
    }
    public static function assertSchema(PDO $db): void
    {
        if (self::$validatedDb === $db) return;
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $tables = ['deliveries','users','audit_logs','delivery_fare_approvals','delivery_payment_attempts','delivery_payment_receipts','payment_webhook_events'];
            $stmt = $db->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')');
            $stmt->execute($tables); $engines = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($tables as $table) if (strcasecmp($engines[$table] ?? '', 'InnoDB') !== 0) throw new RuntimeException('Payments require the migrated InnoDB schema: ' . $table);
        }
        self::$validatedDb = $db;
    }
}
