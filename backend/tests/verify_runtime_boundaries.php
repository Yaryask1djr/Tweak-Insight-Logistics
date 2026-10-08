<?php
require_once __DIR__ . '/../helpers/booking_input.php';
require_once __DIR__ . '/../helpers/booking_quote_inputs.php';
require_once __DIR__ . '/../controllers/HealthController.php';
require_once __DIR__ . '/../helpers/refresh_session.php';
require_once __DIR__ . '/../helpers/listing_query.php';
require_once __DIR__ . '/../helpers/webhook_dispatch.php';

$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function rejected(callable $action): void {
    try { $action(); throw new LogicException('Invalid input accepted.'); }
    catch (TransactionBusinessException $e) { check($e->getStatusCode() === 422 || $e->getStatusCode() === 413, 'Invalid input status.'); }
}
$data = (object)['pickup_address' => 'Sabon Gari, Kano', 'delivery_address' => 'Bompai, Kano',
    'pickup_contact_name' => 'Sender', 'delivery_contact_name' => 'Receiver', 'pickup_contact_phone' => '08000000001',
    'delivery_contact_phone' => '08000000002', 'item_description' => 'Parcel', 'package_size' => 'small_package'];
$valid = BookingInput::normalize($data, true);
check($valid->item_quantity === 1 && $valid->service_type === 'same_day', 'Default booking normalization.');
check(BookingQuoteInputs::resolveDistance($valid) > 1, 'Known landmark distance unavailable.');
$invalid = [['item_quantity', -1], ['item_quantity', 1.5], ['item_quantity', 51], ['item_quantity', '2'], ['weight_kg', -1], ['weight_kg', INF],
    ['weight_kg', '2.5'], ['is_fragile', 'false'], ['pickup_contact_name', []], ['item_description', str_repeat('a', 501)],
    ['pickup_latitude', 91], ['pickup_longitude', -181], ['pickup_latitude', 12], ['package_size', 'free'], ['service_type', 'express'],
    ['preferred_pickup_time', '2026-02-30T10:00:00Z'], ['preferred_pickup_time', '2000-01-01T10:00:00Z'], ['preferred_pickup_time', '2026-09-20'], ['pickup_city', 'Lagos']];
foreach ($invalid as [$field, $value]) rejected(static function () use ($data, $field, $value) { $bad = clone $data; $bad->$field = $value; BookingInput::normalize($bad, true); });
foreach (['null', '[]', '{bad}', str_repeat('a', 65537)] as $raw) rejected(static fn() => BookingInput::decode($raw));
$unknown = (object)['pickup_address' => 'Unknown street', 'delivery_address' => 'Unknown street two', 'distance_km' => 1];
rejected(static fn() => BookingQuoteInputs::resolveDistance($unknown));
$scheduled = clone $data; $scheduled->service_type = 'scheduled';
rejected(static fn() => BookingInput::normalize($scheduled, true));
$scheduled->preferred_pickup_time = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
$scheduled->preferred_delivery_time = gmdate('Y-m-d\TH:i:s\Z', time() + 7200);
check(BookingInput::normalize($scheduled, true)->preferred_pickup_time === gmdate('Y-m-d H:i:s', time() + 3600), 'Schedule must store UTC.');
$scheduled->preferred_delivery_time = gmdate('Y-m-d\TH:i:s\Z', time() + 1800);
rejected(static fn() => BookingInput::normalize($scheduled, true));

$now = time();
foreach (['stopped','queue_error','database_error','outbox_error'] as $status) check(!HealthController::workerStatus(['updated_at_unix' => $now, 'queue' => 'default', 'status' => $status], $now)['ok'], 'A stopped/error worker was reported healthy.');
check(HealthController::workerStatus(['updated_at_unix' => $now, 'queue' => 'default', 'status' => 'idle'], $now)['ok'], 'Live idle worker was rejected.');
check(!HealthController::workerStatus(['updated_at_unix' => $now-121, 'queue' => 'default', 'status' => 'idle'], $now)['ok'], 'Stale heartbeat was accepted.');
check(!HealthController::workerStatus(['updated_at_unix' => $now+1, 'queue' => 'default', 'status' => 'idle'], $now)['ok'], 'Future heartbeat was accepted.');
check(!HealthController::workerStatus(['updated_at_unix' => $now, 'queue' => 'other', 'status' => 'idle'], $now)['ok'], 'Wrong queue heartbeat was accepted.');

$_SERVER['HTTP_USER_AGENT'] = 'Browser under test';
$session = ['revoked_at' => gmdate('Y-m-d H:i:s', $now), 'revoked_reason' => 'rotated', 'user_agent_hash' => hash('sha256', $_SERVER['HTTP_USER_AGENT']),
    'token_version' => 1, 'current_token_version' => 1, 'account_status' => 'active', 'expires_at' => gmdate('Y-m-d H:i:s', $now+60)];
check(RefreshSession::isRotationRace($session, $now), 'Same-browser immediate rotation conflict not recognized.');
check(!RefreshSession::isRotationRace($session, $now+6), 'Race grace was extended beyond five seconds.');
foreach (['revoked_reason' => 'logout', 'account_status' => 'suspended', 'current_token_version' => 2, 'user_agent_hash' => hash('sha256', 'attacker')] as $field => $value) {
    $bad = $session; $bad[$field] = $value; check(!RefreshSession::isRotationRace($bad, $now), 'Invalid token entered race grace.');
}
foreach ([['page' => 0], ['limit' => 1001], ['page' => '1 OR 1'], ['limit' => []]] as $query) rejected(static fn() => ListingQuery::page($query));
check(ListingQuery::page(['page' => '2', 'limit' => '25']) === [2, 25, 25], 'Pagination bounds changed valid query.');
rejected(static fn() => ListingQuery::order(['sort' => 'id; DROP TABLE users'], ['newest' => 'id DESC'], 'newest'));
check(ListingQuery::pagination(25, 2, 25)['has_next_page'] === false, 'Final page advertises another page.');
foreach (['127.0.0.1','10.0.0.1','172.16.1.1','192.168.1.1','169.254.169.254','100.127.1.1','192.0.2.1','198.18.0.1','0.0.0.0','::1','fc00::1','fe80::1','::ffff:127.0.0.1'] as $ip) check(!WebhookDispatch::publicIp($ip), 'Unsafe webhook network accepted: ' . $ip);
check(WebhookDispatch::publicIp('8.8.8.8') && WebhookDispatch::publicIp('2606:4700:4700::1111'), 'Global addresses rejected.');
check(WebhookDispatch::signature('secret', 123, 'event-123', '{}') !== WebhookDispatch::signature('secret', 124, 'event-123', '{}'), 'Timestamp not bound to webhook signature.');
putenv('WEBHOOK_ENDPOINTS_JSON={}');
try { WebhookDispatch::send(['target_url' => 'http://127.0.0.1', 'event_id' => 'event-123']); throw new LogicException('Arbitrary webhook URL accepted.'); }
catch (RuntimeException $e) { check(true, 'Unconfigured webhook fails closed.'); }
echo "PASS: {$checks} pricing, readiness, refresh, listing and webhook boundary checks.\n";
