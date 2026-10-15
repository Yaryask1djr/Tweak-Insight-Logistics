<?php
declare(strict_types=1);

/**
 * CLI runner to batch-flush Redis-buffered driver GPS telemetry events to MySQL.
 *
 * Usage:
 *   php backend/scripts/flush_telemetry_buffer.php
 *   php backend/scripts/flush_telemetry_buffer.php --limit=500
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/telemetry_stream.php';

$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 500;

try {
    $db = (new Database())->getConnection();
    $flushed = TelemetryStream::flushBatch($db, $limit);
    $remaining = TelemetryStream::getBufferLength();

    echo sprintf("[Telemetry Flusher] Flushed %d GPS events to MySQL. Remaining buffer depth: %d.\n", $flushed, $remaining);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[Telemetry Flusher ERROR] " . $e->getMessage() . "\n");
    exit(1);
}
