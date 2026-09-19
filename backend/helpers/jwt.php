<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/security_config.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTHelper {
    private static string $algorithm = 'HS256';

    private static function getSecretKey(): string {
        $secret = trim((string) (getenv('JWT_SECRET') ?: ''));
        $configurationError = SecurityConfig::jwtSecretError($secret);

        if ($configurationError !== null) {
            throw new RuntimeException('JWT secret configuration is invalid.');
        }

        return $secret;
    }

    /**
     * Issue a short-lived access token. Long-lived browser sessions use the
     * opaque HttpOnly refresh cookie managed by RefreshSession instead.
     */
    public static function encode(array $data, int $ttlSeconds = 900): string {
        $secret = self::getSecretKey();
        $issuedAt = time();
        $expire = $issuedAt + max(60, min($ttlSeconds, 3600)); // 15 minutes by default; never over 1 hour
        $payload = [
            'jti'  => bin2hex(random_bytes(16)),
            'iat'  => $issuedAt,
            'exp'  => $expire,
            'typ'  => 'access',
            'data' => $data
        ];
        return JWT::encode($payload, $secret, self::$algorithm);
    }

    public static function decode(string $token): object {
        $secret = self::getSecretKey();
        return JWT::decode($token, new Key($secret, self::$algorithm));
    }
}
