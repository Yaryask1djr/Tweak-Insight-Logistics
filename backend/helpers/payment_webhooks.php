<?php
declare(strict_types=1);
require_once __DIR__ . '/delivery_payments.php';

/** Authenticated durable inbox; provider verification runs outside the webhook request. */
final class PaymentWebhooks
{
    public const MAX_BODY = 262144;
    public static function ingest(PDO $db, string $raw, mixed $signature, PaystackGateway $gateway): void
    {
        if (strlen($raw) > self::MAX_BODY) throw new TransactionBusinessException('Webhook exceeds the body limit.', 413);
        if (!$gateway->authenticateWebhook($raw, $signature)) throw new TransactionBusinessException('Invalid webhook signature.', 401);
        try { $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (JsonException $e) { throw new TransactionBusinessException('Invalid webhook JSON.', 422); }
        $type = $event['event'] ?? null; $data = $event['data'] ?? null;
        if (!is_string($type) || !is_array($data)) throw new TransactionBusinessException('Invalid webhook event.', 422);
        $risk = in_array($type, ['refund.pending','refund.processing','refund.processed','refund.failed','refund.needs-attention',
            'charge.dispute.create','charge.dispute.remind','charge.dispute.resolve'], true);
        if ($type !== 'charge.success' && !$risk) return;
        $transaction = $data['transaction'] ?? null;
        $reference = $type === 'charge.success' ? ($data['reference'] ?? null)
            : ($data['transaction_reference'] ?? (is_array($transaction) ? ($transaction['reference'] ?? null) : null));
        $domain = $data['domain'] ?? (is_array($transaction) ? ($transaction['domain'] ?? null) : null);
        if ($domain !== null && $domain !== $gateway->mode()) throw new TransactionBusinessException('Webhook environment mismatch.', 422);
        $attempt = false;
        if (is_string($reference)) {
            // Other products can use the same merchant account; never mutate their records.
            if (!str_starts_with($reference, 'TILPAY-')) return;
            PaystackGateway::reference($reference);
            $find = $db->prepare('SELECT id FROM delivery_payment_attempts WHERE reference = ? AND environment = ?');
            $find->execute([$reference, $gateway->mode()]); $attempt = $find->fetchColumn();
        } elseif ($risk) {
            $providerId = is_array($transaction) ? ($transaction['id'] ?? null) : $transaction;
            if (is_int($providerId) || is_string($providerId)) {
                $find = $db->prepare("SELECT attempt_id FROM delivery_payment_receipts WHERE provider = 'paystack' AND environment = ? AND provider_transaction_id = ?");
                $find->execute([$gateway->mode(), (string)$providerId]); $attempt = $find->fetchColumn();
            }
        }
        // Do not acknowledge a financial event whose payment cannot yet be resolved.
        if (!$attempt) throw new TransactionBusinessException('Payment event awaits reconciliation.', 503);
        DeliveryPayments::assertSchema($db);
        DeliveryPayments::transaction($db, static function (PDO $db) use ($attempt, $raw, $type, $risk): void {
            // Holds and pickup serialize on the delivery, then the attempt. No network here.
            if ($risk) DeliveryPayments::holdInTransaction($db, $attempt, 'provider_' . str_replace('.', '_', $type));
            $db->prepare('INSERT INTO payment_webhook_events (event_hash, attempt_id, event_type, status, processed_at)
                VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE event_hash = VALUES(event_hash)')
                ->execute([hash('sha256', $raw), $attempt, $type, $risk ? 'processed' : 'pending', $risk ? gmdate('Y-m-d H:i:s') : null]);
        }); // HTTP 200 is sent only after this commit.
    }

    public static function processNext(PDO $db, ?PaymentGateway $gateway = null): bool
    {
        $event = DeliveryPayments::transaction($db, static function (PDO $db): array|false {
            $db->exec("UPDATE payment_webhook_events SET status = 'failed', last_error = 'verification_retries_exhausted', reservation_token = NULL, reserved_until = NULL
                WHERE status = 'processing' AND reserved_until <= UTC_TIMESTAMP() AND attempts >= 10");
            $stmt = $db->query("SELECT * FROM payment_webhook_events WHERE attempts < 10 AND
                ((status = 'pending' AND available_at <= UTC_TIMESTAMP()) OR (status = 'processing' AND reserved_until <= UTC_TIMESTAMP())) ORDER BY id LIMIT 1 FOR UPDATE");
            $row = $stmt->fetch(PDO::FETCH_ASSOC); if (!$row) return false;
            $row['reservation_token'] = bin2hex(random_bytes(16)); $row['attempts'] = (int)$row['attempts'] + 1;
            $db->prepare("UPDATE payment_webhook_events SET status = 'processing', attempts = ?, reservation_token = ?, reserved_until = ? WHERE id = ?")
                ->execute([$row['attempts'], $row['reservation_token'], gmdate('Y-m-d H:i:s', time() + 60), $row['id']]);
            return $row;
        });
        if (!$event) return false;
        try {
            $gateway ??= new PaystackGateway();
            $attempt = DeliveryPayments::attempt($db, $event['attempt_id']);
            if ($attempt['environment'] !== $gateway->mode()) throw new PaymentConfigurationException('Payment environment mismatch.');
            DeliveryPayments::reconcile($db, $attempt['id'], $gateway->verify($attempt['reference']));
            if (!in_array(DeliveryPayments::attempt($db, $attempt['id'])['status'], ['verified', 'review_required'], true)) throw new PaymentGatewayException('Verification is still pending.');
            $db->prepare("UPDATE payment_webhook_events SET status = 'processed', processed_at = UTC_TIMESTAMP(), last_error = NULL, reservation_token = NULL, reserved_until = NULL
                WHERE id = ? AND reservation_token = ? AND status = 'processing'")->execute([$event['id'], $event['reservation_token']]);
        } catch (Throwable $e) {
            $db->prepare("UPDATE payment_webhook_events SET status = ?, available_at = ?, last_error = 'provider_verification_unavailable', reservation_token = NULL, reserved_until = NULL
                WHERE id = ? AND reservation_token = ? AND status = 'processing'")
                ->execute([$event['attempts'] >= 10 ? 'failed' : 'pending', gmdate('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** min(7, $event['attempts'])))), $event['id'], $event['reservation_token']]);
            Logger::warning('Payment webhook verification deferred', ['event_id' => $event['id'], 'attempts' => $event['attempts']]);
        }
        return true;
    }
}
