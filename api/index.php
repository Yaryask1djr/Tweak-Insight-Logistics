<?php

declare(strict_types=1);

/**
 * Vercel Serverless PHP Function Entrypoint
 * Routes incoming API traffic to the core backend router.
 */

require_once __DIR__ . '/../backend/api/index.php';
