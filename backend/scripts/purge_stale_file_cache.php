<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../bootstrap.php';
$cutoff = time() - 7 * 86400;
$deleted = 0;
foreach (glob(STORAGE_PATH . '/cache/*.json') ?: [] as $path) {
    if (is_link($path) || !is_file($path) || filemtime($path) >= $cutoff) continue;
    if (!unlink($path)) throw new RuntimeException('Could not remove expired cache file.');
    $deleted++;
}
echo 'Removed ' . $deleted . ' stale file-cache entries.' . PHP_EOL;
