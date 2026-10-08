<?php
declare(strict_types=1);

final class LogSanitizer
{
    public static function text(string $value, int $limit = 2048): string
    {
        return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', ' ', $value) ?? '', 0, $limit, 'UTF-8');
    }
    public static function requestId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $value) ? $value : null;
    }
    public static function uri(string $uri): string
    {
        // Public tracking tokens and provider references may appear in query parameters.
        return self::text(explode('?', $uri, 2)[0], 1024);
    }
    public static function context(array $context, int $depth = 0): array
    {
        if ($depth > 6) return ['truncated' => true];
        $clean = [];
        foreach (array_slice($context, 0, 100, true) as $key => $value) {
            $name = self::text((string)$key, 128);
            if (preg_match('/password|secret|authorization|cookie|token|otp|document_number|storage_key|email|phone|address|error_message/i', $name)) {
                $clean[$name] = '[REDACTED]';
            } elseif (is_array($value)) {
                $clean[$name] = self::context($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$name] = self::text($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$name] = $value;
            } else {
                $clean[$name] = '[' . get_debug_type($value) . ']';
            }
        }
        return $clean;
    }
}
