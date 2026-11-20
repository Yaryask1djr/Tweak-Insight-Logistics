<?php
header('Content-Type: application/json');
echo json_encode([
    'status' => 'alive',
    'php_version' => PHP_VERSION,
    'backend_exists' => is_dir(__DIR__ . '/../backend'),
    'time' => date('c'),
]);
