<?php
/** Daily retention job. Database references expire before private files are removed. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/report_export.php';
$db = (new Database())->getConnection();
$db->exec("UPDATE report_exports SET status = 'expired' WHERE expires_at <= UTC_TIMESTAMP() AND status <> 'expired'");
$db->exec("UPDATE report_exports SET status = 'failed', error_message = 'Worker retries exhausted. Generate a new report.' WHERE status = 'processing' AND attempts >= 3 AND reserved_until < UTC_TIMESTAMP()");
foreach (glob(ReportExport::directory() . '/*.csv') as $path) {
    if (is_link($path) || filemtime($path) > time() - 86400) continue;
    if (!preg_match('/\A([a-f0-9]{32})-[a-f0-9]{32}\.csv\z/', basename($path), $match)) continue;
    $active = $db->prepare("SELECT COUNT(*) FROM report_exports WHERE id = ? AND status IN ('pending', 'processing', 'ready') AND expires_at > UTC_TIMESTAMP()");
    $active->execute([$match[1]]);
    if (!(int)$active->fetchColumn() && !unlink($path)) throw new RuntimeException('Could not remove expired export.');
}
echo "Export retention completed.\n";
