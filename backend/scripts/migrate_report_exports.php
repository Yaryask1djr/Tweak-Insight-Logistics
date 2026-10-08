<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
if (!preg_match('/CREATE TABLE IF NOT EXISTS report_exports \([\s\S]*?\) ENGINE=InnoDB[^;]*;/', $schema, $match)) throw new RuntimeException('Export schema missing.');
$db->exec($match[0]);
echo "Report export table ready.\n";
