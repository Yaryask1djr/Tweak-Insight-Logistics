<?php
require_once __DIR__ . '/../helpers/listing_query.php';
require_once __DIR__ . '/../helpers/response.php';

final class ReportingController
{
    private const UNASSIGNED = "d.status IN ('pending','under_review','broadcasted') AND d.delivery_person_id IS NULL";
    private const DELAYED = "d.status NOT IN ('delivered','completed','cancelled','rejected','failed') AND (d.preferred_delivery_time < UTC_TIMESTAMP() OR d.request_time < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 150 MINUTE))";
    private const CANCELLED = "d.status IN ('cancelled','rejected','failed')";

    public static function analytics(PDO $db): void
    {
        $period = $_GET['period'] ?? '30';
        if (!in_array($period, ['today', '7', '30', '90', 'all'], true)) Response::error('Invalid report period.', 422);
        $where = ''; $params = [];
        if ($period !== 'all') {
            $from = $period === 'today' ? new DateTimeImmutable('today', new DateTimeZone('Africa/Lagos')) : new DateTimeImmutable('-' . (int)$period . ' days', new DateTimeZone('UTC'));
            $where = 'WHERE d.request_time >= ?'; $params[] = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $stmt = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(d.status IN ('delivered','completed')),0) AS completed,
            COALESCE(SUM(" . self::DELAYED . "),0) AS delayed,
            COALESCE(SUM(CASE WHEN d.payment_status = 'paid' THEN d.total_cost ELSE 0 END),0) AS paid_amount,
            AVG(NULLIF(d.distance_km,0)) AS average_distance,
            COALESCE(SUM(d.service_type = 'same_day'),0) AS same_day FROM deliveries d {$where}");
        $stmt->execute($params); Response::json($stmt->fetch(PDO::FETCH_ASSOC));
    }

    public static function exceptions(PDO $db): void
    {
        try { [$page, $limit, $offset] = ListingQuery::page($_GET); }
        catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        $predicates = ['unassigned' => self::UNASSIGNED, 'delayed' => self::DELAYED, 'cancelled' => self::CANCELLED];
        $predicates['all'] = '(' . implode(') OR (', array_values($predicates)) . ')';
        $category = $_GET['category'] ?? 'all';
        if (!is_string($category) || !isset($predicates[$category])) Response::error('Invalid exception category.', 422);
        $counts = $db->query('SELECT ' . implode(', ', array_map(static fn($key, $sql) => "COALESCE(SUM({$sql}),0) AS `{$key}`", array_keys($predicates), array_values($predicates))) . ' FROM deliveries d')->fetch(PDO::FETCH_ASSOC);
        $stmt = $db->prepare("SELECT d.id, d.tracking_number, d.status, d.pickup_time, d.pickup_address, d.delivery_address,
            d.request_time, d.preferred_delivery_time, d.total_cost, d.item_description, u.full_name AS delivery_person_name
            FROM deliveries d LEFT JOIN users u ON u.id = d.delivery_person_id WHERE {$predicates[$category]} ORDER BY d.id DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT); $stmt->bindValue(':offset', $offset, PDO::PARAM_INT); $stmt->execute();
        Response::json(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'counts' => array_map('intval', $counts), 'pagination' => ListingQuery::pagination((int)$counts[$category], $page, $limit)]);
    }

    public static function payments(PDO $db, int $client): void
    {
        $stmt = $db->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(payment_status = 'paid'),0) AS paid_count,
            COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_cost ELSE 0 END),0) AS paid_amount,
            COALESCE(SUM(payment_status IN ('unpaid','pending') AND status NOT IN ('cancelled','rejected','failed')),0) AS pending_count,
            COALESCE(SUM(CASE WHEN payment_status IN ('unpaid','pending') AND status NOT IN ('cancelled','rejected','failed') THEN total_cost ELSE 0 END),0) AS pending_amount
            FROM deliveries WHERE client_id = ?");
        $stmt->execute([$client]); Response::json($stmt->fetch(PDO::FETCH_ASSOC));
    }
}
