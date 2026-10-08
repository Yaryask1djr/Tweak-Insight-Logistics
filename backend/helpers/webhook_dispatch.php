<?php
declare(strict_types=1);

/** Only server-configured HTTPS endpoints are eligible. Never trusts a job URL. */
final class WebhookDispatch
{
    public static function publicIp(string $ip): bool
    {
        if (str_contains($ip, ':') && ($packed = @inet_pton($ip)) !== false && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $ip = inet_ntop(substr($packed, 12));
        }
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE);
    }

    public static function signature(string $secret, int $time, string $event, string $body): string
    {
        return hash_hmac('sha256', $time . "\n" . $event . "\n" . $body, $secret);
    }

    public static function send(array $payload): void
    {
        $endpoints = json_decode((string)getenv('WEBHOOK_ENDPOINTS_JSON'), true, 16, JSON_THROW_ON_ERROR);
        $id = $payload['endpoint_id'] ?? null;
        $config = is_string($id) ? ($endpoints[$id] ?? null) : null;
        if (!is_array($config)) throw new RuntimeException('Webhook endpoint is not configured.');
        $url = $config['url'] ?? ''; $secret = $config['secret'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) throw new RuntimeException('Webhook requires a configured HTTPS endpoint on port 443.');
        if (!is_string($secret) || strlen($secret) < 32 || str_starts_with($secret, 'REPLACE_')) throw new RuntimeException('Webhook signing secret is missing.');
        $host = strtolower($parts['host']);
        if (!preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', $host) || filter_var($host, FILTER_VALIDATE_IP)) throw new RuntimeException('Webhook host must be a configured DNS name.');
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = [];
        foreach ($records ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) continue;
            if (!self::publicIp($ip)) throw new RuntimeException('Webhook DNS resolved to a prohibited network.');
            $ips[] = $ip;
        }
        if (!$ips) throw new RuntimeException('Webhook DNS has no eligible addresses.');
        $event = $payload['event_id'] ?? '';
        if (!is_string($event) || !preg_match('/\A[A-Za-z0-9_-]{8,100}\z/', $event)) throw new RuntimeException('Webhook requires a stable event_id.');
        $body = json_encode($payload['data'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($body) > 262144) throw new RuntimeException('Webhook body exceeds 256 KiB.');
        $time = time(); $received = 0;
        $ip = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
        $handle = curl_init($url);
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-TIL-Event: ' . $event, 'X-TIL-Timestamp: ' . $time,
                    'X-TIL-Signature: sha256=' . self::signature($secret, $time, $event, $body)],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$host . ':443:' . $ip], // DNS pin closes resolution/connect rebinding.
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $bytes) use (&$received): int {
                    $received += strlen($bytes); return $received > 65536 ? 0 : strlen($bytes);
                },
            ]);
            if (curl_exec($handle) === false) throw new RuntimeException('Webhook transport failed.');
            $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($code < 200 || $code >= 300) throw new RuntimeException('Webhook returned HTTP ' . $code);
        } finally { curl_close($handle); }
    }
}
