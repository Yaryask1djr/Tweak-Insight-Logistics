<?php

declare(strict_types=1);
require_once __DIR__ . '/database_transaction.php';
require_once __DIR__ . '/csv_export.php';

/** Durable export requests, bounded reads and private, owner-authorized files. */
final class ReportExport
{
    public static function filters(array $input): array
    {
        $result = [];
        foreach (['action', 'actor_role', 'delivery_id', 'status', 'from', 'to'] as $field) {
            $value = $input[$field] ?? $input[$field . '_date'] ?? '';
            if (!is_scalar($value) || strlen((string)$value) > 120) throw new TransactionBusinessException('Invalid export filter.', 422);
            $result[$field] = trim((string)$value);
        }
        foreach (['from', 'to'] as $field) {
            if ($result[$field] === '') continue;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $result[$field]);
            if (!$date || $date->format('Y-m-d') !== $result[$field]) throw new TransactionBusinessException('Export dates must use YYYY-MM-DD.', 422);
        }
        if ($result['from'] && $result['to'] && $result['from'] > $result['to']) throw new TransactionBusinessException('Export date range is reversed.', 422);
        if ($result['delivery_id'] && (!ctype_digit($result['delivery_id']) || (int)$result['delivery_id'] < 1)) throw new TransactionBusinessException('Invalid delivery_id.', 422);
        return array_filter($result, static fn($value) => $value !== '');
    }

    public static function request(PDO $db, int $owner, string $kind, array $filters): array
    {
        if (!in_array($kind, ['audit', 'deliveries'], true)) throw new InvalidArgumentException('Invalid report kind.');
        $filters = self::filters($filters);
        $table = $kind === 'audit' ? 'audit_logs' : 'deliveries';
        $maxId = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
        $id = bin2hex(random_bytes(16));
        $expires = gmdate('Y-m-d H:i:s', time() + 86400);
        $db->prepare('INSERT INTO report_exports (id, owner_user_id, report_kind, filters, upper_id, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$id, $owner, $kind, json_encode($filters, JSON_THROW_ON_ERROR), $maxId, $expires]);
        return ['export_id' => $id, 'status' => 'pending', 'expires_at' => $expires];
    }

    public static function owned(PDO $db, string $id, int $owner): array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $id)) throw new TransactionBusinessException('Export not found.', 404);
        $stmt = $db->prepare('SELECT * FROM report_exports WHERE id = ? AND owner_user_id = ?');
        $stmt->execute([$id, $owner]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new TransactionBusinessException('Export not found.', 404);
        if (strtotime($row['expires_at'] . ' UTC') <= time()) throw new TransactionBusinessException('Export expired. Generate a new report.', 410);
        return $row;
    }

    public static function directory(): string
    {
        $dir = (defined('STORAGE_PATH') ? STORAGE_PATH : __DIR__ . '/../storage') . '/exports';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Export storage unavailable.');
        return $dir;
    }

    public static function path(array $row): string
    {
        $file = $row['file_name'] ?? '';
        if (!preg_match('/\A[a-f0-9]{32}-[a-f0-9]{32}\.csv\z/', $file)) throw new RuntimeException('Export artifact unavailable.');
        $path = self::directory() . '/' . $file;
        if (is_link($path) || !is_file($path)) throw new RuntimeException('Export artifact unavailable.');
        return $path;
    }

    public static function processNext(PDO $db, ?callable $heartbeat = null): bool
    {
        $token = bin2hex(random_bytes(16));
        $job = DatabaseTransaction::run($db, static function (PDO $db) use ($token): array|false {
            $job = $db->query("SELECT * FROM report_exports WHERE expires_at > UTC_TIMESTAMP() AND attempts < 3
                AND (status = 'pending' OR (status = 'processing' AND reserved_until < UTC_TIMESTAMP())) ORDER BY created_at, id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$job) return false;
            $db->prepare("UPDATE report_exports SET status = 'processing', attempts = attempts + 1, reservation_token = ?, reserved_until = ? WHERE id = ?")
                ->execute([$token, gmdate('Y-m-d H:i:s', time() + 300), $job['id']]);
            return $job;
        });
        if (!$job) return false;
        $file = $job['id'] . '-' . $token . '.csv';
        $path = self::directory() . '/' . $file;
        $fp = null;
        try {
            $owner = $db->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'admin' AND account_status = 'active'");
            $owner->execute([$job['owner_user_id']]);
            if (!(int)$owner->fetchColumn()) throw new RuntimeException('Report owner no longer authorized.');
            $filters = self::filters(json_decode($job['filters'], true, 32, JSON_THROW_ON_ERROR));
            $isAudit = $job['report_kind'] === 'audit';
            $table = $isAudit ? 'audit_logs' : 'deliveries';
            $columns = $isAudit ? 'id, created_at, action, actor_role, actor_user_id, entity_type, entity_id, delivery_id'
                : 'id, tracking_number, status, service_type, total_cost, payment_status, request_time, pickup_time, delivery_time';
            $dateColumn = $isAudit ? 'created_at' : 'request_time';
            $where = ['id > :cursor', 'id <= :upper_id']; $params = ['upper_id' => $job['upper_id']];
            foreach ($isAudit ? ['action', 'actor_role', 'delivery_id'] : ['status'] as $field) {
                if (isset($filters[$field])) {
                    // Match the audit listing's substring search in generated reports.
                    $where[] = $field . ($field === 'action' ? ' LIKE :' : ' = :') . $field;
                    $params[$field] = $field === 'action' ? '%' . $filters[$field] . '%' : $filters[$field];
                }
            }
            if (isset($filters['from'])) { $where[] = "{$dateColumn} >= :from_date"; $params['from_date'] = $filters['from'] . ' 00:00:00'; }
            if (isset($filters['to'])) { $where[] = "{$dateColumn} < :to_date"; $params['to_date'] = (new DateTimeImmutable($filters['to'], new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d'); }
            $sql = "SELECT {$columns} FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id LIMIT 500';
            $fp = fopen($path, 'xb');
            if (!$fp) throw new RuntimeException('Cannot create export.');
            chmod($path, 0600);
            CsvExport::write($fp, array_map('trim', explode(',', $columns)));
            $cursor = 0; $count = 0;
            do {
                if ($heartbeat !== null) $heartbeat();
                $renew = $db->prepare("UPDATE report_exports SET reserved_until = ? WHERE id = ? AND reservation_token = ? AND status = 'processing' AND reserved_until >= UTC_TIMESTAMP() AND expires_at > UTC_TIMESTAMP()");
                // Microsecond-free times may be unchanged within one second; use a SELECT fence below.
                $renew->execute([gmdate('Y-m-d H:i:s', time() + 300), $job['id'], $token]);
                $fence = $db->prepare("SELECT COUNT(*) FROM report_exports WHERE id = ? AND reservation_token = ? AND status = 'processing' AND reserved_until >= UTC_TIMESTAMP() AND expires_at > UTC_TIMESTAMP()");
                $fence->execute([$job['id'], $token]);
                if (!(int)$fence->fetchColumn()) throw new RuntimeException('Export lease lost.');
                $stmt = $db->prepare($sql); $stmt->execute($params + ['cursor' => $cursor]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) { CsvExport::write($fp, array_values($row)); $cursor = (int)$row['id']; $count++; }
            } while (count($rows) === 500);
            if (!fflush($fp)) throw new RuntimeException('Export write failed.');
            fclose($fp); $fp = null;
            $ready = $db->prepare("UPDATE report_exports SET status = 'ready', row_count = ?, file_name = ?, completed_at = UTC_TIMESTAMP(), error_message = NULL
                WHERE id = ? AND reservation_token = ? AND status = 'processing' AND reserved_until >= UTC_TIMESTAMP() AND expires_at > UTC_TIMESTAMP()");
            $ready->execute([$count, $file, $job['id'], $token]);
            if ($ready->rowCount() !== 1) throw new RuntimeException('Export completion lost its lease.');
            return true;
        } catch (Throwable $error) {
            if (is_resource($fp)) fclose($fp);
            if (is_file($path)) unlink($path);
            $db->prepare("UPDATE report_exports SET status = 'failed', error_message = 'Report generation failed. Request a new export.' WHERE id = ? AND reservation_token = ? AND status = 'processing'")
                ->execute([$job['id'], $token]);
            throw $error;
        }
    }
}
