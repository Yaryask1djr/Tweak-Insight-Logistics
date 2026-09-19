<?php

declare(strict_types=1);

final class SecurityConfig
{
    private const EXPOSED_JWT_SECRET_SHA256 = '4012cb03fada07472e845456b138a8f6dff640519a6d72a606db796c0ff4e765';

    private const NON_PRODUCTION_ENVIRONMENTS = [
        'development',
        'dev',
        'local',
        'test',
        'testing',
    ];

    private const PLACEHOLDER_VALUES = [
        'change_me',
        'changeme',
        'default',
        'password',
        'replace_me',
        'secret',
    ];

    public static function isProductionLike(): bool
    {
        $environment = strtolower(trim((string) getenv('APP_ENV')));

        return !in_array($environment, self::NON_PRODUCTION_ENVIRONMENTS, true);
    }

    public static function jwtSecretError(?string $secret): ?string
    {
        $value = trim((string) $secret);

        if (self::isPlaceholder($value)) {
            return 'JWT_SECRET is missing or uses a placeholder value.';
        }

        if (strlen($value) < 32) {
            return 'JWT_SECRET must contain at least 32 bytes.';
        }

        if (hash_equals(self::EXPOSED_JWT_SECRET_SHA256, hash('sha256', $value))) {
            return 'JWT_SECRET matches a revoked credential and must be rotated.';
        }

        return null;
    }

    public static function databaseCredentialsError(
        ?string $username,
        ?string $password,
        bool $requireProductionStrength
    ): ?string {
        $user = trim((string) $username);
        $secret = (string) $password;

        if ($user === '' || strcasecmp($user, 'root') === 0 || self::isPlaceholder($user)) {
            return 'DB_USER must be a dedicated application account, not root.';
        }

        if (self::isPlaceholder($secret)) {
            return 'DB_PASS is missing or uses a placeholder value.';
        }

        if ($requireProductionStrength && strlen($secret) < 32) {
            return 'DB_PASS must contain at least 32 bytes in production-like environments.';
        }

        return null;
    }

    public static function isPlaceholderValue(?string $value): bool
    {
        return self::isPlaceholder((string) $value);
    }

    private static function isPlaceholder(string $value): bool
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || in_array($normalized, self::PLACEHOLDER_VALUES, true)) {
            return true;
        }

        return str_starts_with($normalized, 'replace_')
            || str_starts_with($normalized, 'change_')
            || str_starts_with($normalized, 'your_')
            || str_contains($normalized, 'placeholder');
    }
}
