<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/delivery_payments.php';
try {
    $options = getopt('', ['reference:']);
    $reference = PaystackGateway::reference($options['reference'] ?? null);
    $db = (new Database())->getConnection(); $gateway = new PaystackGateway();
    $find = $db->prepare('SELECT * FROM delivery_payment_attempts WHERE reference = ? AND environment = ?'); $find->execute([$reference, $gateway->mode()]);
    $attempt = $find->fetch(PDO::FETCH_ASSOC);
    if (!$attempt) throw new RuntimeException('No payment exists in this environment.');
    DeliveryPayments::reconcile($db, $attempt['id'], $gateway->verify($reference));
    $current = DeliveryPayments::attempt($db, $attempt['id']);
    echo json_encode(['reference' => $reference, 'status' => $current['status'], 'attention_reason' => $current['attention_reason']], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Payment could not be verified. Check the reference, provider configuration and reconciliation logs. No checkout was created.\n"); exit(1);
}
