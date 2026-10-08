<?php
declare(strict_types=1);
require_once __DIR__ . '/../helpers/payment_webhooks.php';

final class PaymentController
{
    public static function request(string $raw): array
    {
        if (strlen($raw) > 8192) throw new TransactionBusinessException('Payment request is too large.', 413);
        try { $body = json_decode($raw, false, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new TransactionBusinessException('A valid JSON object is required.', 422); }
        if (!$body instanceof stdClass) throw new TransactionBusinessException('A JSON object is required.', 422);
        return (array)$body;
    }
    private static function integer(mixed $value, string $name, int $min = 1): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => 2147483647]]) === false)
            throw new TransactionBusinessException('A valid ' . $name . ' is required.', 422);
        return (int)$value;
    }
    public static function handle(PDO $db, string $operation, ?int $userId = null): void
    {
        try {
            if ($operation === 'webhook') {
                $raw = file_get_contents('php://input', false, null, 0, PaymentWebhooks::MAX_BODY + 1);
                PaymentWebhooks::ingest($db, $raw === false ? '' : $raw, $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? null, new PaystackGateway());
                Response::json(['received' => true], 'Payment event received.'); return;
            }
            if (in_array($operation, ['view', 'admin_view'], true)) {
                Response::json(DeliveryPayments::view($db, self::integer($_GET['delivery_id'] ?? null, 'delivery_id'), $operation === 'view' ? $userId : null)); return;
            }
            $raw = file_get_contents('php://input', false, null, 0, 8193);
            $body = self::request($raw === false ? '' : $raw);
            if ($operation === 'approve') {
                if (!is_string($body['reason'] ?? null)) throw new TransactionBusinessException('An approval reason is required.', 422);
                $data = DeliveryPayments::approveFare($db, self::integer($body['delivery_id'] ?? null, 'delivery_id'), $userId,
                    self::integer($body['expected_version'] ?? null, 'expected_version', 0), $body['amount'] ?? null, $body['reason']);
            } elseif ($operation === 'initialize') {
                $data = DeliveryPayments::initialize($db, self::integer($body['delivery_id'] ?? null, 'delivery_id'), $userId,
                    self::integer($body['fare_version'] ?? null, 'fare_version'), new PaystackGateway());
            } elseif ($operation === 'verify') {
                $data = DeliveryPayments::verifyOwned($db, PaystackGateway::reference($body['reference'] ?? null), $userId, new PaystackGateway());
            } else { throw new LogicException('Unknown payment operation.'); }
            Response::json($data);
        } catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        catch (InvalidArgumentException $e) { Response::error('Invalid payment reference.', 422); }
        catch (PaymentConfigurationException $e) { Response::error('Payments are not configured for this environment. Contact operations.', 503); }
        catch (PaymentGatewayException $e) { Response::error('The provider could not confirm payment. Check this payment again; do not create another booking to retry.', 502); }
        catch (Throwable $e) {
            Logger::error('Payment operation failed', ['operation' => $operation, 'type' => get_class($e)]);
            Response::error('Payment records are temporarily unavailable. No payment confirmation has been issued.', 503);
        }
    }
}
