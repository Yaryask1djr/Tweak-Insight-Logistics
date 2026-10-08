<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/operations_schema.php';
require_once __DIR__ . '/../helpers/operational_records.php';
require_once __DIR__ . '/../helpers/notification_service.php';
require_once __DIR__ . '/../helpers/listing_query.php';

/** Deeper operations records: fleet, rate cards, business accounts, and dispatch exceptions. */
final class OperationsManagementController
{
    private const SERVICE_TYPES = ['same_day', 'scheduled', 'business'];
    private const RULE_CODES = ['base_fare', 'included_distance_km', 'distance_per_km', 'distance_over_threshold_per_km', 'included_weight_kg', 'weight_per_kg', 'same_day_surcharge', 'scheduled_surcharge', 'fragile_surcharge', 'perishable_surcharge'];

    public static function drivers(PDO $db): void
    {
        OperationsSchema::requireTables($db, ['drivers', 'driver_availability']);
        try {
            [$page, $limit, $offset] = ListingQuery::page($_GET);
            $search = ListingQuery::search($_GET);
            $order = ListingQuery::order($_GET, ['newest' => 'u.id DESC', 'name' => 'u.full_name ASC, u.id ASC'], 'newest');
        } catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        $where = ["u.role = 'delivery'"]; $params = [];
        $status = $_GET['status'] ?? 'all'; $availability = $_GET['availability'] ?? 'all';
        if (!in_array($status, ['all','active','inactive','suspended','verified','submitted','rejected'], true)) Response::error('Invalid driver status.', 422);
        if ($status !== 'all') { $where[] = in_array($status, ['active','inactive','suspended'], true) ? 'd.active_status = :status' : 'd.kyc_status = :status'; $params['status'] = $status; }
        if (!in_array($availability, ['all','available','busy','offline','paused'], true)) Response::error('Invalid availability.', 422);
        if ($availability !== 'all') { $where[] = "COALESCE(a.availability_status, 'offline') = :availability"; $params['availability'] = $availability; }
        if ($search !== '') {
            $where[] = '(u.full_name LIKE :name OR u.phone LIKE :phone OR d.vehicle_type LIKE :vehicle OR d.vehicle_registration LIKE :registration)';
            foreach (['name','phone','vehicle','registration'] as $key) $params[$key] = '%' . $search . '%';
        }
        $join = 'FROM users u JOIN drivers d ON d.user_id = u.id LEFT JOIN driver_availability a ON a.driver_id = d.id';
        $filter = 'WHERE ' . implode(' AND ', $where);
        $count = $db->prepare("SELECT COUNT(*) {$join} {$filter}"); $count->execute($params); $total = (int)$count->fetchColumn();
        $statement = $db->prepare("SELECT u.id AS user_id, u.full_name, u.email, u.phone, u.is_approved, u.account_status, d.id AS driver_id, d.kyc_status, d.active_status, d.vehicle_type, d.vehicle_registration, d.max_payload_kg, a.availability_status, a.last_location_at {$join} {$filter} ORDER BY {$order} LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT); $statement->bindValue(':offset', $offset, PDO::PARAM_INT); $statement->execute();
        $counts = $db->query("SELECT COUNT(*) AS total, COALESCE(SUM(a.availability_status = 'available'),0) AS available, COALESCE(SUM(a.availability_status = 'busy'),0) AS busy, COALESCE(SUM(COALESCE(a.availability_status,'offline') = 'offline'),0) AS offline {$join} WHERE u.role = 'delivery'")->fetch(PDO::FETCH_ASSOC);
        Response::paginated($statement->fetchAll(PDO::FETCH_ASSOC), $total, $page, $limit, 'Fleet records retrieved.', 200, ['counts' => array_map('intval', $counts)]);
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
        try {
            [$page, $limit, $offset] = ListingQuery::page($_GET);
            $search = ListingQuery::search($_GET);
            $order = ListingQuery::order($_GET, ['newest' => 'r.id DESC', 'name' => 'r.name ASC, r.id ASC'], 'newest');
        } catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        $where = "r.city = 'Kano'"; $params = [];
        if ($search !== '') { $where .= ' AND r.name LIKE :search'; $params['search'] = '%' . $search . '%'; }
        $count = $db->prepare("SELECT COUNT(*) FROM rate_cards r WHERE {$where}"); $count->execute($params);
        $total = (int)$count->fetchColumn();
        $statement = $db->prepare("SELECT r.*, u.full_name AS created_by_name FROM rate_cards r LEFT JOIN users u ON u.id = r.created_by WHERE {$where} ORDER BY {$order} LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) $statement->bindValue(':' . $key, $value);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT); $statement->bindValue(':offset', $offset, PDO::PARAM_INT); $statement->execute();
        $cards = $statement->fetchAll(PDO::FETCH_ASSOC); $rules = [];
        if ($cards) {
            $ids = array_column($cards, 'id');
            $stmt = $db->prepare('SELECT rate_card_id, rule_code, amount, threshold_value, sort_order FROM rate_rules WHERE rate_card_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort_order, id');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $rule) $rules[$rule['rate_card_id']][] = $rule;
        }
        foreach ($cards as &$card) $card['rules'] = $rules[$card['id']] ?? [];
        Response::paginated($cards, $total, $page, $limit, 'Kano rate cards retrieved.');
    }

    public static function saveRateCard(PDO $db, int $adminId): void
    {
        OperationsSchema::requireTables($db, ['rate_cards', 'rate_rules']);
        $data = self::input(); $service = $data->service_type ?? '';
        if (!in_array($service, self::SERVICE_TYPES, true)) Response::error('A valid service_type is required.');
        $name = trim((string)($data->name ?? '')); if ($name === '') Response::error('Rate card name is required.');
        $rules = is_array($data->rules ?? null) ? $data->rules : [];
        if (!$rules) Response::error('At least one rate rule is required.');
        if (count($rules) > count(self::RULE_CODES) || mb_strlen($name) > 150) Response::error('Rate card exceeds supported name/rule bounds.', 422);
        $codes = [];
        foreach ($rules as $rule) {
            $value = is_array($rule) ? $rule : (array)$rule;
            if (!in_array($value['rule_code'] ?? '', self::RULE_CODES, true)) Response::error('Unsupported rate rule.');
            $code = $value['rule_code'];
            if (isset($codes[$code])) Response::error('Duplicate rate rule.', 422);
            $codes[$code] = true;
            $amount = $value['amount'] ?? null;
            $threshold = $value['threshold_value'] ?? null;
            if ((!is_int($amount) && !is_float($amount)) || !is_finite((float)$amount) || $amount < 0 || $amount > 10000000) Response::error('Rate amounts must be numeric and between 0 and 10,000,000.', 422);
            if ($threshold !== null && $threshold !== '' && ((!is_int($threshold) && !is_float($threshold)) || !is_finite((float)$threshold) || $threshold < 0 || $threshold > 1000)) Response::error('Rate thresholds must be numeric and between 0 and 1,000.', 422);
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
        OperationalRecords::audit($db, $adminId, 'admin', !empty($data->id) ? 'rate_card.updated' : 'rate_card.created', 'rate_card', $id, null, ['service_type' => $service, 'active' => (bool)$active]);
        Response::json(['id' => $id], 'Rate card saved for future delivery quotes.');
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
        OperationalRecords::audit($db, $adminId, 'admin', !empty($data->id) ? 'business_account.updated' : 'business_account.created', 'business_account', $id, null, ['account_status' => $status]);
        Response::json(['id' => $id], 'Business account saved.');
    }

    private static function page(): array { $page = max(1, (int)($_GET['page'] ?? 1)); $limit = max(1, min(100, (int)($_GET['limit'] ?? 20))); return [$page, $limit, ($page - 1) * $limit]; }
    private static function input(): object { return json_decode(file_get_contents('php://input')) ?: (object)[]; }
}
