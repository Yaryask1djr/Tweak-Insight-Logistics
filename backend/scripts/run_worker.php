<?php
declare(strict_types=1);

/**
 * Tweak Insight Logistics — Worker Process Entry Point
 *
 * Provides a standardized runner for supervisor, systemd, or CLI execution:
 *   php backend/scripts/run_worker.php --daemon
 *   php backend/scripts/run_worker.php --once
 */

require __DIR__ . '/queue_worker.php';
