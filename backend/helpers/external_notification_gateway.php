<?php

declare(strict_types=1);

require_once __DIR__ . '/logger.php';

/**
 * Universal External Notification Gateway for Nigeria (SMS & WhatsApp).
 *
 * Supported Providers:
 * 1. Termii (Primary Nigerian SMS & WhatsApp provider)
 * 2. Twilio (International SMS & WhatsApp fallback)
 * 3. Log/Simulated (Development and sandbox testing)
 */
final class ExternalNotificationGateway
{
    public static function isConfigured(): bool
    {
        $provider = strtolower((string)(getenv('SMS_PROVIDER') ?: ''));
        if ($provider === 'termii' && !empty(getenv('TERMII_API_KEY'))) {
            return true;
        }
        if ($provider === 'twilio' && !empty(getenv('TWILIO_AUTH_TOKEN'))) {
            return true;
        }
        return false;
    }

    /**
     * Dispatch an SMS notification.
     *
     * @param string $to Recipient phone number (e.g. +2348012345678 or 08012345678)
     * @param string $message Text content
     * @return array{success: bool, provider: string, message_id?: string, error?: string}
     */
    public static function sendSms(string $to, string $message): array
    {
        $phone = self::normalizeNigerianPhone($to);
        $provider = strtolower((string)(getenv('SMS_PROVIDER') ?: 'log'));

        if ($provider === 'termii') {
            return self::sendViaTermii($phone, $message, 'sms');
        }

        if ($provider === 'twilio') {
            return self::sendViaTwilio($phone, $message, 'sms');
        }

        // Default or testing/development log fallback
        Logger::info('[MOCK SMS SENT]', ['to' => $phone, 'length' => strlen($message), 'preview' => substr($message, 0, 60)]);
        return [
            'success' => true,
            'provider' => 'mock_log',
            'message_id' => 'mock_' . bin2hex(random_bytes(6)),
        ];
    }

    /**
     * Dispatch a WhatsApp notification.
     *
     * @param string $to Recipient phone number
     * @param string $message Text content
     * @return array{success: bool, provider: string, message_id?: string, error?: string}
     */
    public static function sendWhatsApp(string $to, string $message): array
    {
        $phone = self::normalizeNigerianPhone($to);
        $provider = strtolower((string)(getenv('WHATSAPP_PROVIDER') ?: getenv('SMS_PROVIDER') ?: 'log'));

        if ($provider === 'termii') {
            return self::sendViaTermii($phone, $message, 'whatsapp');
        }

        if ($provider === 'twilio') {
            return self::sendViaTwilio($phone, $message, 'whatsapp');
        }

        Logger::info('[MOCK WHATSAPP SENT]', ['to' => $phone, 'length' => strlen($message), 'preview' => substr($message, 0, 60)]);
        return [
            'success' => true,
            'provider' => 'mock_log',
            'message_id' => 'mock_wa_' . bin2hex(random_bytes(6)),
        ];
    }

    /**
     * Termii HTTP API dispatcher.
     * Docs: https://developers.termii.com
     */
    private static function sendViaTermii(string $phone, string $message, string $channel = 'sms'): array
    {
        $apiKey = (string)getenv('TERMII_API_KEY');
        $senderId = (string)(getenv('TERMII_SENDER_ID') ?: 'TweakLog');
        $url = 'https://api.ng.termii.com/api/sms/send';

        $payload = [
            'to' => $phone,
            'from' => $senderId,
            'sms' => $message,
            'type' => 'plain',
            'channel' => ($channel === 'whatsapp') ? 'whatsapp' : 'generic',
            'api_key' => $apiKey,
        ];

        return self::executeHttpJson($url, $payload, 'termii');
    }

    /**
     * Twilio REST API dispatcher.
     */
    private static function sendViaTwilio(string $phone, string $message, string $channel = 'sms'): array
    {
        $sid = (string)getenv('TWILIO_ACCOUNT_SID');
        $token = (string)getenv('TWILIO_AUTH_TOKEN');
        $fromNumber = (string)getenv('TWILIO_FROM_NUMBER');

        $toFormatted = ($channel === 'whatsapp') ? "whatsapp:+$phone" : "+$phone";
        $fromFormatted = ($channel === 'whatsapp') ? "whatsapp:$fromNumber" : $fromNumber;

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";

        $postFields = http_build_query([
            'To' => $toFormatted,
            'From' => $fromFormatted,
            'Body' => $message,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$sid}:{$token}",
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return ['success' => false, 'provider' => 'twilio', 'error' => $curlErr];
        }

        $json = json_decode((string)$response, true);
        if ($httpCode >= 200 && $httpCode < 300 && isset($json['sid'])) {
            return ['success' => true, 'provider' => 'twilio', 'message_id' => $json['sid']];
        }

        return [
            'success' => false,
            'provider' => 'twilio',
            'error' => $json['message'] ?? "HTTP error {$httpCode}",
        ];
    }

    private static function executeHttpJson(string $url, array $payload, string $providerName): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return ['success' => false, 'provider' => $providerName, 'error' => $curlErr];
        }

        $json = json_decode((string)$response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'provider' => $providerName,
                'message_id' => $json['message_id'] ?? $json['sid'] ?? 'ok',
            ];
        }

        return [
            'success' => false,
            'provider' => $providerName,
            'error' => $json['message'] ?? $json['error'] ?? "HTTP error {$httpCode}",
        ];
    }

    /**
     * Standardizes local Nigerian phone number representations to 2348XXXXXXXXX format.
     */
    public static function normalizeNigerianPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '234' . substr($digits, 1);
        }
        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            return $digits;
        }
        return $digits;
    }
}
