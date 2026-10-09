<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../helpers/external_notification_gateway.php';

echo ">>> Testing External Notification Gateway (Termii, Twilio, Mock) <<<\n\n";

// 1. Phone Normalization Tests
assert(ExternalNotificationGateway::normalizeNigerianPhone('08012345678') === '2348012345678', 'Failed to normalize local 080 format');
assert(ExternalNotificationGateway::normalizeNigerianPhone('+2348012345678') === '2348012345678', 'Failed to normalize +234 format');
assert(ExternalNotificationGateway::normalizeNigerianPhone('2348012345678') === '2348012345678', 'Failed to handle already normalized format');
echo "[PASS] Nigerian phone normalization verified (23480... format).\n";

// 2. Default Sandbox Mock Dispatch Test
putenv('SMS_PROVIDER=log');
$smsResult = ExternalNotificationGateway::sendSms('08012345678', 'Test message from Tweak Insight');
assert($smsResult['success'] === true, 'Mock SMS dispatch should succeed');
assert($smsResult['provider'] === 'mock_log', 'Provider should report mock_log');
assert(!empty($smsResult['message_id']), 'Message ID should be present');
echo "[PASS] Mock SMS dispatch executed cleanly.\n";

$waResult = ExternalNotificationGateway::sendWhatsApp('08012345678', 'Test WhatsApp message');
assert($waResult['success'] === true, 'Mock WhatsApp dispatch should succeed');
assert($waResult['provider'] === 'mock_log', 'WhatsApp provider should report mock_log');
echo "[PASS] Mock WhatsApp dispatch executed cleanly.\n";

// 3. Provider Configuration Detection
putenv('SMS_PROVIDER=termii');
putenv('TERMII_API_KEY=');
assert(ExternalNotificationGateway::isConfigured() === false, 'Should report unconfigured when API key is missing');

putenv('TERMII_API_KEY=test_termii_key_123');
assert(ExternalNotificationGateway::isConfigured() === true, 'Should report configured when Termii key is set');
echo "[PASS] Termii provider credentials detection verified.\n";

putenv('SMS_PROVIDER=twilio');
putenv('TWILIO_AUTH_TOKEN=');
assert(ExternalNotificationGateway::isConfigured() === false, 'Should report unconfigured when Twilio token is missing');

putenv('TWILIO_AUTH_TOKEN=test_auth_token_xyz');
assert(ExternalNotificationGateway::isConfigured() === true, 'Should report configured when Twilio token is set');
echo "[PASS] Twilio provider credentials detection verified.\n";

// Reset to development log provider
putenv('SMS_PROVIDER=log');
echo "\n>>> ALL EXTERNAL NOTIFICATION GATEWAY TESTS PASSED! <<<\n";
