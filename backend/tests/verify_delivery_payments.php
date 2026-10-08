<?php
/** Production services with SQLite dialect adaptation and a simulated provider; no live charges. */
require_once __DIR__ . '/support/PaymentFixture.php';
require_once __DIR__ . '/../controllers/PaymentController.php';
$checks = 0;
function pcheck(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function denied(callable $work, int $code): void {
    $caught = false; try { $work(); } catch (TransactionBusinessException $e) { $caught = $e->getStatusCode() === $code; }
    pcheck($caught, 'Expected rejection ' . $code);
}
function cleared(PDO $db): bool { return DeliveryPayments::pickupFlags($db, $db->query('SELECT * FROM deliveries WHERE id = 1')->fetchAll(PDO::FETCH_ASSOC))[1]; }
function ingest(PDO $db, string $type, array $data): void {
    $raw = json_encode(['event' => $type, 'data' => $data], JSON_THROW_ON_ERROR);
    PaymentWebhooks::ingest($db, $raw, hash_hmac('sha512', $raw, (string)getenv('PAYSTACK_SECRET_KEY')), new PaystackGateway());
}
foreach (['1' => 100, '650.25' => 65025, '10000000.00' => 1000000000] as $value => $minor) pcheck(PaymentMoney::minor((string)$value) === $minor, 'Money rounding');
foreach ([0, -1, 1.1, true, null, [], '1e3', '1.001', '10000000.01', ' 12', '01.00'] as $value) denied(static fn() => PaymentMoney::minor($value), 422);
foreach (['[]','null','{','true'] as $raw) denied(static fn() => PaymentController::request($raw), 422);
denied(static fn() => PaymentController::request(str_repeat(' ', 8193)), 413);
paymentEnvironment();
foreach (['http://checkout.paystack.com/code','https://checkout.paystack.com.evil.test/code','https://user@checkout.paystack.com/code','https://checkout.paystack.com/code?next=evil'] as $url) {
    $caught = false; try { PaystackGateway::checkoutUrl($url); } catch (PaymentGatewayException $e) { $caught = true; } pcheck($caught, 'Unsafe checkout URL');
}
$db = paymentFixture(); $original = $db->query('SELECT snapshot_json FROM delivery_booking_snapshots')->fetchColumn();
denied(static fn() => DeliveryPayments::approveFare($db, 1, 1, 0, '650.25', 'reason'), 403);
denied(static fn() => DeliveryPayments::approveFare($db, 1, 100, 0, '650.25', 'reason'), 403);
denied(static fn() => DeliveryPayments::initialize($db, 1, 2, 1, new TestPaymentGateway($db)), 404);
denied(static fn() => DeliveryPayments::initialize($db, 1, 1, 1, new TestPaymentGateway($db)), 409);
$fare = DeliveryPayments::approveFare($db, 1, 99, 0, '650.25', 'Verified route');
pcheck($fare['amount_minor'] === 65025 && $fare['version'] === 1, 'Approval did not use exact kobo');
pcheck(DeliveryPayments::approveFare($db, 1, 99, 0, '650.25', 'Verified route') === $fare, 'Approval retry not idempotent');
denied(static fn() => DeliveryPayments::approveFare($db, 1, 99, 0, '700', 'Other reason'), 409);
pcheck($db->query('SELECT snapshot_json FROM delivery_booking_snapshots')->fetchColumn() === $original, 'Original quote changed');
pcheck(!$db->inTransaction(), 'Business rejection left a transaction open');
$db->exec("UPDATE deliveries SET payment_status = 'paid'"); pcheck(!cleared($db), 'A paid flag alone enabled pickup');
$db->exec("UPDATE deliveries SET payment_status = 'unpaid'");
$gateway = new TestPaymentGateway($db);
$gateway->duringInitialize = static function () use ($db, $gateway): void {
    denied(static fn() => DeliveryPayments::initialize($db, 1, 1, 1, $gateway), 409);
};
$view = DeliveryPayments::initialize($db, 1, 1, 1, $gateway); $reference = $view['attempt']['reference'];
DeliveryPayments::initialize($db, 1, 1, 1, $gateway);
pcheck($gateway->initializations === 1 && (int)$db->query('SELECT COUNT(*) FROM delivery_payment_attempts')->fetchColumn() === 1, 'Checkout was duplicated');
denied(static fn() => DeliveryPayments::approveFare($db, 1, 99, 1, '700', 'Revision'), 409);
denied(static fn() => DeliveryPayments::verifyOwned($db, $reference, 2, $gateway), 404);
$gateway->overrides = ['status' => 'pending']; DeliveryPayments::verifyOwned($db, $reference, 1, $gateway);
pcheck(!cleared($db), 'API success/pending became a paid transaction');
$gateway->overrides = []; $verified = DeliveryPayments::verifyOwned($db, $reference, 1, $gateway);
pcheck($verified['receipt']['amount_minor'] === 65025 && cleared($db), 'Correct provider evidence did not clear pickup');
DeliveryPayments::verifyOwned($db, $reference, 1, $gateway);
pcheck((int)$db->query('SELECT COUNT(*) FROM delivery_payment_receipts')->fetchColumn() === 1, 'Duplicate receipt');
pcheck((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment.provider_verified'")->fetchColumn() === 1, 'Duplicate payment audit');
foreach (['delivery_fare_approvals','delivery_payment_receipts'] as $table) foreach (['UPDATE' => "UPDATE {$table} SET amount_minor = 1", 'DELETE' => "DELETE FROM {$table}"] as $sql) {
    $caught = false; try { $db->exec($sql); } catch (PDOException $e) { $caught = true; } pcheck($caught, 'Immutable payment record was mutable');
}
ingest($db, 'charge.success', ['reference' => $reference, 'domain' => 'test']);
ingest($db, 'charge.success', ['reference' => $reference, 'domain' => 'test']);
pcheck((int)$db->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn() === 1, 'Webhook replay duplicated inbox');
pcheck(PaymentWebhooks::processNext($db, $gateway) && !PaymentWebhooks::processNext($db, $gateway), 'Inbox did not drain once');
ingest($db, 'refund.pending', ['transaction_reference' => $reference, 'domain' => 'test']);
pcheck(!cleared($db), 'Refund did not block pickup immediately');
DeliveryPayments::verifyOwned($db, $reference, 1, $gateway); pcheck(!cleared($db), 'Success replay removed refund hold');
putenv('APP_ENV=production'); pcheck(!cleared($db), 'Test receipt accepted in production'); paymentEnvironment();

foreach ([['amount' => 1], ['amount' => '65025'], ['currency' => 'USD'], ['reference' => 'wrong'], ['domain' => 'live'],
    ['id' => 1.5], ['id' => '18446744073709551616'], ['customer' => ['email' => 'other@example.test']], ['metadata' => []],
    ['paid_at' => '2026-02-30T10:00:00Z'], ['status' => 'reversed'], ['status' => true]] as $override) {
    $db = paymentFixture(); [$gateway, $reference] = paymentStarted($db); $gateway->overrides = $override;
    $view = DeliveryPayments::verifyOwned($db, $reference, 1, $gateway);
    pcheck($view['attempt']['status'] === 'review_required' && !$view['receipt'] && !cleared($db), 'Mismatch accepted: ' . json_encode($override));
}
$db = paymentFixture(); [$gateway, $reference] = paymentStarted($db);
$db->exec("UPDATE deliveries SET status = 'cancelled'");
$view = DeliveryPayments::verifyOwned($db, $reference, 1, $gateway);
pcheck($view['receipt'] !== null && $view['attempt']['status'] === 'review_required' && !cleared($db), 'Late payment lost or cleared cancelled delivery');
$db = paymentFixture(); [$gateway, $reference] = paymentStarted($db);
$db->exec("UPDATE deliveries SET delivery_address = 'Changed destination'");
$view = DeliveryPayments::verifyOwned($db, $reference, 1, $gateway);
pcheck($view['attempt']['status'] === 'review_required' && !cleared($db), 'Changed shipment accepted');

$db = paymentFixture(); DeliveryPayments::approveFare($db, 1, 99, 0, '650.25', 'Approved'); $gateway = new TestPaymentGateway($db); $gateway->failInitialize = true;
$caught = false; try { DeliveryPayments::initialize($db, 1, 1, 1, $gateway); } catch (PaymentGatewayException $e) { $caught = true; }
$reference = $db->query('SELECT reference FROM delivery_payment_attempts')->fetchColumn();
pcheck($caught && !$db->inTransaction() && !cleared($db), 'Unknown initialization accepted as paid');
$gateway->failInitialize = false; DeliveryPayments::initialize($db, 1, 1, 1, $gateway);
pcheck($reference === $db->query('SELECT reference FROM delivery_payment_attempts')->fetchColumn(), 'Timeout replaced the reference');

$db = paymentFixture(); [$gateway, $reference] = paymentStarted($db);
$raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
denied(static fn() => PaymentWebhooks::ingest($db, $raw, str_repeat('0', 128), new PaystackGateway()), 401);
denied(static fn() => PaymentWebhooks::ingest($db, str_repeat('x', 262145), null, new PaystackGateway()), 413);
pcheck((int)$db->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn() === 0, 'Unauthenticated inbox write');
ingest($db, 'charge.success', ['reference' => $reference, 'domain' => 'test']); $gateway->failVerify = true;
PaymentWebhooks::processNext($db, $gateway);
pcheck($db->query('SELECT status FROM payment_webhook_events')->fetchColumn() === 'pending' && !cleared($db), 'Provider outage lost event or unlocked pickup');
$db->exec("UPDATE payment_webhook_events SET status = 'processing', reserved_until = '2000-01-01 00:00:00', reservation_token = 'expired'");
$gateway->failVerify = false; PaymentWebhooks::processNext($db, $gateway);
pcheck(cleared($db), 'Expired worker lease not recovered');
ingest($db, 'charge.dispute.create', ['transaction' => ['reference' => $reference, 'domain' => 'test']]); pcheck(!cleared($db), 'Dispute failed to hold');

// Required audit failure must roll back the receipt and paid flag together.
$db = paymentFixture(); [$gateway, $reference] = paymentStarted($db);
$db->exec("CREATE TRIGGER fail_payment_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
$caught = false; try { DeliveryPayments::verifyOwned($db, $reference, 1, $gateway); } catch (PDOException $e) { $caught = true; }
pcheck($caught && !$db->inTransaction() && (int)$db->query('SELECT COUNT(*) FROM delivery_payment_receipts')->fetchColumn() === 0 && $db->query('SELECT payment_status FROM deliveries')->fetchColumn() === 'pending', 'Partial payment commit after audit failure');

// Fare approval is also rolled back if its mandatory audit cannot be persisted.
$db = paymentFixture();
$db->exec("CREATE TRIGGER fail_fare_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
$caught = false; try { DeliveryPayments::approveFare($db, 1, 99, 0, '650.25', 'Approved'); } catch (PDOException $e) { $caught = true; }
pcheck($caught && (int)$db->query('SELECT COUNT(*) FROM delivery_fare_approvals')->fetchColumn() === 0 && $db->query('SELECT total_cost FROM deliveries')->fetchColumn() === '550', 'Partial fare approval');

// A hold arriving while checkout initialization is in flight fences the late URL.
$db = paymentFixture(); DeliveryPayments::approveFare($db, 1, 99, 0, '650.25', 'Approved'); $gateway = new TestPaymentGateway($db);
$gateway->duringInitialize = static fn(array $a) => DeliveryPayments::hold($db, $a['id'], 'provider_refund_pending');
$view = DeliveryPayments::initialize($db, 1, 1, 1, $gateway);
pcheck(!$view['can_pay'] && $view['attempt']['status'] === 'review_required' && $view['attempt']['authorization_url'] === null, 'Late initialization overwrote a hold');

// One provider transaction cannot pay two bookings, even with otherwise valid bindings.
$db = paymentFixture(); [$gateway, $reference] = paymentStarted($db); DeliveryPayments::verifyOwned($db, $reference, 1, $gateway);
DeliveryPayments::transaction($db, static fn(PDO $db) => DeliveryBooking::apply($db, 1, 'second-payment-booking', str_repeat('b', 64), 'bookingPlan'));
$db->exec("UPDATE deliveries SET status = 'under_review' WHERE id = 2");
DeliveryPayments::approveFare($db, 2, 99, 0, '650.25', 'Approved'); $second = DeliveryPayments::initialize($db, 2, 1, 1, $gateway);
$gateway->overrides = ['id' => '10001']; $view = DeliveryPayments::verifyOwned($db, $second['attempt']['reference'], 1, $gateway);
pcheck($view['attempt']['status'] === 'review_required' && $view['receipt'] === null && (int)$db->query('SELECT COUNT(*) FROM delivery_payment_receipts')->fetchColumn() === 1, 'Provider transaction reused');

// Refund ACK must not escape before the hold and inbox write commit.
$db = paymentFixture(); [$gateway, $reference] = paymentStarted($db); DeliveryPayments::verifyOwned($db, $reference, 1, $gateway);
$db->exec("CREATE TRIGGER fail_hold_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
$caught = false; try { ingest($db, 'refund.pending', ['transaction_reference' => $reference]); } catch (PDOException $e) { $caught = true; }
pcheck($caught && (int)$db->query('SELECT COUNT(*) FROM payment_webhook_events')->fetchColumn() === 0 && cleared($db), 'Refund was acknowledged without its hold');
$db->exec('DROP TRIGGER fail_hold_audit');
ingest($db, 'refund.pending', ['transaction_reference' => $reference]); pcheck(!cleared($db), 'Provider retry did not commit hold');

$db = paymentFixture(); [$gateway, $reference] = paymentStarted($db);
ingest($db, 'charge.success', ['reference' => $reference]);
$db->exec("UPDATE payment_webhook_events SET status = 'processing', attempts = 10, reserved_until = '2000-01-01 00:00:00'");
pcheck(!PaymentWebhooks::processNext($db, $gateway) && $db->query('SELECT status FROM payment_webhook_events')->fetchColumn() === 'failed', 'Abandoned final lease was not dead-lettered');
pcheck($gateway->verifications === 0 && !cleared($db), 'Retry exhaustion ran another provider call');
denied(static fn() => ingest($db, 'charge.success', ['reference' => $reference, 'domain' => 'live']), 422);
denied(static fn() => ingest($db, 'refund.pending', ['transaction_reference' => 'TILPAY-' . str_repeat('f', 32)]), 503);
$raw = '{'; denied(static fn() => PaymentWebhooks::ingest($db, $raw, hash_hmac('sha512', $raw, (string)getenv('PAYSTACK_SECRET_KEY')), new PaystackGateway()), 422);
echo "PASS: {$checks} fare/payment checks (SQLite + simulated provider; native MySQL and live Paystack not exercised).\n";
