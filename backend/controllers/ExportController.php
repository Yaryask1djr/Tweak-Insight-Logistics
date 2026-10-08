<?php
require_once __DIR__ . '/../helpers/report_export.php';
require_once __DIR__ . '/../helpers/response.php';

final class ExportController
{
    public static function request(PDO $db, int $owner, string $kind): void
    {
        try {
            $raw = file_get_contents('php://input', false, null, 0, 8193);
            if (strlen($raw) > 8192) Response::error('Export request is too large.', 413);
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($data)) Response::error('Export filters must be an object.', 422);
            Response::json(ReportExport::request($db, $owner, $kind, $data), 'Report queued.', 202);
        } catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        catch (JsonException $e) { Response::error('Invalid export JSON.', 422); }
    }
    public static function get(PDO $db, int $owner, bool $download): void
    {
        try { $row = ReportExport::owned($db, (string)($_GET['id'] ?? ''), $owner); }
        catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        if (!$download) Response::json(array_intersect_key($row, array_flip(['id', 'status', 'row_count', 'created_at', 'completed_at', 'expires_at', 'error_message'])));
        if ($row['status'] !== 'ready') Response::error('Export is not ready.', 409);
        try { $file = ReportExport::path($row); }
        catch (RuntimeException $e) { Response::error('Export artifact is unavailable. Request a new report.', 410); }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $row['report_kind'] . '-report.csv"');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}
