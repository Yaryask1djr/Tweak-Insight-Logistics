<?php
declare(strict_types=1);

class PaymentGatewayException extends RuntimeException {}
final class PaymentConfigurationException extends PaymentGatewayException {}

interface PaymentGateway
{
    public function mode(): string;
    public function initialize(array $attempt): array;
    public function verify(string $reference): array;
}

/** Fixed Paystack API origin; no caller-controlled URL or secret leaves the server. */
final class PaystackGateway implements PaymentGateway
{
    private string $secret;
    private string $environment;
    private string $callback;
    public function __construct()
    {
        if (getenv('PAYMENT_PROVIDER') !== 'paystack') throw new PaymentConfigurationException('Paystack payments are not configured.');
        $this->environment = self::configuredMode();
        $secret = (string)getenv('PAYSTACK_SECRET_KEY');
        if (!preg_match('/\Ask_' . $this->environment . '_[A-Za-z0-9]{20,128}\z/', $secret)) throw new PaymentConfigurationException('Payment credentials are not configured for this environment.');
        $this->secret = $secret;
        $this->callback = self::callbackUrl((string)getenv('PAYMENT_RETURN_URL'), $this->environment);
    }
    public static function configuredMode(): string
    {
        $mode = (string)getenv('PAYSTACK_MODE');
        if (!in_array($mode, ['test', 'live'], true) || (strtolower((string)getenv('APP_ENV')) === 'production' && $mode !== 'live')) {
            throw new PaymentConfigurationException('Configure the payment environment; production requires live mode.');
        }
        return $mode;
    }
    public static function callbackUrl(string $url, string $mode): string
    {
        $parts = parse_url($url);
        $local = $mode === 'test' && strtolower((string)getenv('APP_ENV')) !== 'production'
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1'], true) && ($parts['scheme'] ?? '') === 'http';
        if (!$parts || empty($parts['host']) || (($parts['scheme'] ?? '') !== 'https' && !$local)
            || isset($parts['pass']) || isset($parts['user']) || isset($parts['fragment']) || isset($parts['query'])
            || ($parts['path'] ?? '') !== '/payment/return') throw new PaymentConfigurationException('Configure an absolute payment return URL ending in /payment/return.');
        return $url;
    }
    public function mode(): string { return $this->environment; }
    public static function reference(mixed $reference): string
    {
        if (!is_string($reference) || !preg_match('/\ATILPAY-[a-f0-9]{32}\z/', $reference)) throw new InvalidArgumentException('Invalid payment reference.');
        return $reference;
    }
    public static function checkoutUrl(mixed $url): string
    {
        $parts = is_string($url) ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'checkout.paystack.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['port'])
            || !preg_match('#\A/[A-Za-z0-9_-]+\z#', $parts['path'] ?? '') || isset($parts['query'])) {
            throw new PaymentGatewayException('Payment provider returned an invalid checkout address.');
        }
        return $url;
    }
    public static function signatureValid(string $raw, mixed $signature, string $secret): bool
    {
        return is_string($signature) && preg_match('/\A[a-f0-9]{128}\z/', $signature)
            && hash_equals(hash_hmac('sha512', $raw, $secret), $signature);
    }
    public function authenticateWebhook(string $raw, mixed $signature): bool { return self::signatureValid($raw, $signature, $this->secret); }
    public function initialize(array $attempt): array
    {
        $result = $this->request('POST', '/transaction/initialize', [
            'email' => $attempt['payer_email'], 'amount' => (string)$attempt['amount_minor'], 'currency' => 'NGN',
            'reference' => $attempt['reference'], 'callback_url' => $this->callback,
            'metadata' => json_encode(self::metadata($attempt), JSON_THROW_ON_ERROR),
        ]);
        if (($result['reference'] ?? null) !== $attempt['reference']) throw new PaymentGatewayException('Payment provider reference mismatch.');
        return ['reference' => $result['reference'], 'authorization_url' => self::checkoutUrl($result['authorization_url'] ?? null)];
    }
    public static function metadata(array $attempt): array
    {
        return ['til_attempt_id' => (string)$attempt['id'], 'til_delivery_id' => (string)$attempt['delivery_id'],
            'til_client_id' => (string)$attempt['client_id'], 'til_fare_id' => (string)$attempt['fare_approval_id']];
    }
    public function verify(string $reference): array { return $this->request('GET', '/transaction/verify/' . self::reference($reference)); }
    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!function_exists('curl_init')) throw new PaymentConfigurationException('The server payment HTTP extension is unavailable.');
        $handle = curl_init('https://api.paystack.co' . $path); $body = '';
        try {
            curl_setopt_array($handle, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->secret, 'Content-Type: application/json'],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 524288) return 0;
                    $body .= $chunk; return strlen($chunk);
                },
            ]);
            if ($payload !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
            if (curl_exec($handle) === false) throw new PaymentGatewayException('Payment provider is unavailable. Check the existing payment before trying again.');
            $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($code < 200 || $code >= 300) throw new PaymentGatewayException('Payment provider could not confirm this request.');
            try { $json = json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
            catch (JsonException $e) { throw new PaymentGatewayException('Invalid payment provider response.'); }
            if (($json['status'] ?? null) !== true || !is_array($json['data'] ?? null)) throw new PaymentGatewayException('Payment provider did not confirm the request.');
            return $json['data']; // Transaction success is checked separately in the reconciliation service.
        } finally { curl_close($handle); }
    }
}
