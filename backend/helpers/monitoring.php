<?php

require_once __DIR__ . '/logger.php';

/** Structured operational events consumed by log search, alerts, or APM. */
final class Monitoring
{
    public static function queueFailure(array $context): void
    {
        self::event('queue.failure', 'ERROR', $context);
    }

    public static function slowQuery(string $name, float $durationMs, array $context = []): void
    {
        self::event('database.slow_query', 'WARNING', [
            'query_name' => $name,
            'duration_ms' => round($durationMs, 2),
            ...$context,
        ]);
    }

    public static function storageFailure(string $operation, array $context = []): void
    {
        self::event('storage.failure', 'ERROR', ['operation' => $operation, ...$context]);
    }

    public static function authAnomaly(string $type, array $context = []): void
    {
        self::event('auth.anomaly', 'WARNING', ['anomaly' => $type, ...$context]);
    }

    public static function staleGps(array $context): void
    {
        self::event('gps.stale', 'WARNING', $context);
    }

    private static function event(string $event, string $level, array $context): void
    {
        $context['event'] = $event;
        $context['environment'] = getenv('APP_ENV') ?: 'development';
        $message = "monitoring.{$event}";
        match ($level) {
            'ERROR' => Logger::error($message, $context),
            'WARNING' => Logger::warning($message, $context),
            default => Logger::info($message, $context),
        };
    }
}