<?php

declare(strict_types=1);

/**
 * The sole public PHP entry point for API traffic.
 *
 * Keep the web server's DocumentRoot set to this directory. Private source,
 * environment configuration, logs, and KYC files remain one directory above
 * it and are loaded only by server-side code.
 */
require_once dirname(__DIR__) . '/api/index.php';
