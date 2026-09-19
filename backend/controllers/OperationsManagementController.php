<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/operations_schema.php';
require_once __DIR__ . '/../helpers/operational_records.php';
require_once __DIR__ . '/../helpers/notification_service.php';

/** Deeper operations records: fleet, rate cards, business accounts, and dispatch exceptions. */
final class OperationsManagementController
{
    private const SERVICE_TYPES = ['same_day', 'scheduled', 'business'];
    private const RULE_CODES = ['base_fare', 'included_distance_km', 'distance_per_km', 'distance_over_threshold_per_km', 'included_weight_kg', 'weight_per_kg', 'same_day_surcharge', 'scheduled_surcharge', 'fragile_surcharge', 'perishable_surcharge'];

    public static function drivers(PDO $db): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_availability']);
        [$page, $limit, $offset] = self::page();
        $filter = trim($_GET['status'] ?? 'all');
        $allowed = ['all', 'active', 'inactive', 'suspended', 'verified', 'submitted', 'rejected'];
        if (!in_array($filter, $allowed, true)) Response::error('Unsupported driver status filter.');
        $where = "WHERE u.role = 'delivery'"; $params = [];
        if (in_array($filter, ['active', 'inactive', 'suspended'], true)) { $where .= ' AND d.active_status = :status'; $params['status'] = $filter; }
        if (in_array($filter, ['verified', 'submitted', 'rejected'], true)) { $where .= ' AND d.kyc_status = :status'; $params['status'] = $filter; }
        $count = $db->prepare("SELECT COUNT(*) FROM users u JOIN drivers d ON d.user_id = u.id {$where}"); $count->execute($params); $total = (int)$count->fetchColumn();
        $statement = $db->prepare("SELECT u.id AS user_id, u.full_name, u.email, u.phone, u.is_approved, u.account_status, d.id AS driver_id, d.kyc_status, d.active_status, d.vehicle_type, d.vehicle_registration, d.max_payload_kg, a.availability_status, a.last_location_at FROM users u JOIN drivers d ON d.user_id = u.id LEFT JOIN driver_availability a ON a.driver_id = d.id {$where} ORDER BY u.created_at DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT); $statement->bindValue(':offset', $offset, PDO::PARAM_INT); $statement->execute();
        Response::paginated($statement->fetchAll(PDO::FETCH_ASSOC), $total, $page, $limit, 'Fleet records retrieved.');
    }

    public static function updateDriver(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_availability']);
        $data = self::input(); $userId = (int)($data->user_id ?? 0);
        if (!$userId) Response::error('user_id is required.');
        $query = $db->prepare("SELECT d.*, u.id AS user_id FROM drivers d JOIN users u ON u.id = d.user_id WHERE u.id = ? AND u.role = 'delivery'"); $query->execute([$userId]); $driver = $query->fetch(PDO::FETCH_ASSOC);
        if (!$driver) Response::notFound('Driver not found.');
        $active = $data->active_status ?? $driver['active_status'];
        if (!in_array($active, ['active', 'inactive', 'suspended'], true)) Response::error('Invalid active_status.');
        if ($active === 'active' && $driver['kyc_status'] !== 'verified') Response::error('Only verified drivers may be set active.', 422);
        $vehicleType = trim((string)($data->vehicle_type ?? $driver['vehicle_type'] ?? '')) ?: null;
        $registration = trim((string)($data->vehicle_registration ?? $driver['vehicle_registration'] ?? '')) ?: null;
        $payload = isset($data->max_payload_kg) ? max(0, (float)$data->max_payload_kg) : $driver['max_payload_kg'];
        $update = $db->prepare('UPDATE drivers SET active_status = ?, vehicle_type = ?, vehicle_registration = ?, max_payload_kg = ? WHERE id = ?');
        $update->execute([$active, $vehicleType, $registration, $payload, $driver['id']]);
        if ($active !== 'active') $db->prepare("UPDATE driver_availability SET availability_status = 'offline', available_since = NULL WHERE driver_id = ?")->execute([$driver['id']]);
        OperationalRecords::audit($db, $adminId, 'admin', 'driver.operations_updated', 'driver', (int)$driver['id'], ['active_status' => $driver['active_status']], ['active_status' => $active], ['vehicle_type' => $vehicleType]);
        Response::json(['user_id' => $userId, 'active_status' => $active], 'Driver operations record updated.');
    }

    public static function rateCards(PDO $db): void
    {
        OperationsSchema::requireTables($db, ['rate_cards', 'rate_rules']);
        $statement = $db->query("SELECT r.*, u.full_name AS created_by_name FROM rate_cards r LEFT JOIN users u ON u.id = r.created_by WHERE r.city = 'Kano' ORDER BY r.is_active DESC, r.effective_from DESC");
        $cards = $statement->fetchAll(PDO::FETCH_ASSOC);
        $rules = $db->query('SELECT rate_card_id, rule_code, amount, threshold_value, sort_order FROM rate_rules ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cards as &$card) { $card['rules'] = array_values(array_filter($rules, fn ($rule) => (int)$rule['rate_card_id'] === (int)$card['id'])); }
        Response::json($cards, 'Kano rate cards retrieved.');
    }

    public static function saveRateCard(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['rate_cards', 'rate_rules']);
        $data = self::input(); $service = $data->service_type ?? '';
        if (!in_array($service, self::SERVICE_TYPES, true)) Response::error('A valid service_type is required.');
        $name = trim((string)($data->name ?? '')); if ($name === '') Response::error('Rate card name is required.');
        $rules = is_array($data->rules ?? null) ? $data->rules : [];
        if (!$rules) Response::error('At least one rate rule is required.');
        foreach ($rules as $rule) {
            $value = is_array($rule) ? $rule : (array)$rule;
            if (!in_array($value['rule_code'] ?? '', self::RULE_CODES, true)) Response::error('Unsupported rate rule.');
        }
        $id = (int)($data->id ?? 0); $effective = $data->effective_from ?? date('Y-m-d H:i:s'); $active = !empty($data->is_active) ? 1 : 0;
        $db->beginTransaction();
        try {
            if ($id) {
                $existing = $db->prepare('SELECT id FROM rate_cards WHERE id = ? AND city = \'Kano\' FOR UPDATE'); $existing->execute([$id]); if (!$existing->fetchColumn()) Response::notFound('Rate card not found.');
                $db->prepare('UPDATE rate_cards SET name = ?, service_type = ?, effective_from = ?, effective_to = ?, is_active = ? WHERE id = ?')->execute([$name, $service, $effective, $data->effective_to ?? null, $active, $id]);
                $db->prepare('DELETE FROM rate_rules WHERE rate_card_id = ?')->execute([$id]);
            } else {
                $insert = $db->prepare("INSERT INTO rate_cards (name, city, service_type, currency, effective_from, effective_to, is_active, created_by) VALUES (?, 'Kano', ?, 'NGN', ?, ?, ?, ?)");
                $insert->execute([$name, $service, $effective, $data->effective_to ?? null, $active, $adminId]); $id = (int)$db->lastInsertId();
            }
            $insertRule = $db->prepare('INSERT INTO rate_rules (rate_card_id, rule_code, amount, threshold_value, sort_order) VALUES (?, ?, ?, ?, ?)');
            foreach ($rules as $position => $rule) { $value = is_array($rule) ? $rule : (array)$rule; $insertRule->execute([$id, $value['rule_code'], max(0, (float)($value['amount'] ?? 0)), isset($value['threshold_value']) && $value['threshold_value'] !== '' ? (float)$value['threshold_value'] : null, $position]); }
            $db->commit();
        } catch (Throwable $exception) { if ($db->inTransaction()) $db->rollBack(); throw $exception; }
        OperationalRecords::audit($db, $adminId, 'admin', $data->id ? 'rate_card.updated' : 'rate_card.created', 'rate_card', $id, null, ['service_type' => $service, 'active' => (bool)$active]);
        Response::json(['id' => $id], 'Rate card saved. It applies only to future quotes once the pricing engine is switched to rate cards.');
    }

    public static function businessAccounts(PDO $db): void
    {
        OperationsSchema::requireTables($db, ['clients', 'business_accounts']);
        [$page, $limit, $offset] = self::page(); $status = trim($_GET['status'] ?? 'all');
        $where = ''; $params = []; if ($status !== 'all') { $where = 'WHERE b.account_status = :status'; $params['status'] = $status; }
        $count = $db->prepare("SELECT COUNT(*) FROM business_accounts b {$where}"); $count->execute($params); $total = (int)$count->fetchColumn();
        $statement = $db->prepare("SELECT b.*, c.user_id AS owner_user_id, u.full_name AS owner_name, u.email AS owner_email FROM business_accounts b JOIN clients c ON c.id = b.owner_client_id JOIN users u ON u.id = c.user_id {$where} ORDER BY b.created_at DESC LIMIT :limit OFFSET :offset"); foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value); $statement->bindValue(':limit', $limit, PDO::PARAM_INT); $statement->bindValue(':offset', $offset, PDO::PARAM_INT); $statement->execute();
        Response::paginated($statement->fetchAll(PDO::FETCH_ASSOC), $total, $page, $limit, 'Business accounts retrieved.');
    }

    public static function saveBusinessAccount(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['clients', 'business_accounts']);
        $data = self::input(); $id = (int)($data->id ?? 0); $status = $data->account_status ?? 'pending'; if (!in_array($status, ['pending', 'active', 'suspended', 'closed'], true)) Response::error('Invalid business account status.');
        if ($id) {
            $update = $db->prepare('UPDATE business_accounts SET legal_name = ?, trading_name = ?, contact_email = ?, contact_phone = ?, account_status = ?, credit_limit = ?, payment_terms_days = ? WHERE id = ?');
            $update->execute([trim((string)$data->legal_name), trim((string)($data->trading_name ?? '')) ?: null, trim((string)($data->contact_email ?? '')) ?: null, trim((string)($data->contact_phone ?? '')) ?: null, $status, max(0, (float)($data->credit_limit ?? 0)), max(0, (int)($data->payment_terms_days ?? 0)), $id]);
        } else {
            $ownerUserId = (int)($data->owner_user_id ?? 0); $client = $db->prepare('SELECT id FROM clients WHERE user_id = ?'); $client->execute([$ownerUserId]); $clientId = (int)$client->fetchColumn(); if (!$clientId) Response::error('owner_user_id must belong to a client account.');
            $insert = $db->prepare('INSERT INTO business_accounts (owner_client_id, legal_name, trading_name, contact_email, contact_phone, account_status, credit_limit, payment_terms_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'); $insert->execute([$clientId, trim((string)$data->legal_name), trim((string)($data->trading_name ?? '')) ?: null, trim((string)($data->contact_email ?? '')) ?: null, trim((string)($data->contact_phone ?? '')) ?: null, $status, max(0, (float)($data->credit_limit ?? 0)), max(0, (int)($data->payment_terms_days ?? 0))]); $id = (int)$db->lastInsertId();
        }
        OperationalRecords::audit($db, $adminId, 'admin', $data->id ? 'business_account.updated' : 'business_account.created', 'business_account', $id, null, ['account_status' => $status]);
        Response::json(['id' => $id], 'Business account saved.');
    }

    private static function page(): array { $page = max(1, (int)($_GET['page'] ?? 1)); $limit = max(1, min(100, (int)($_GET['limit'] ?? 20))); return [$page, $limit, ($page - 1) * $limit]; }
    private static function input(): object { return json_decode(file_get_contents('php://input')) ?: (object)[]; }
}
