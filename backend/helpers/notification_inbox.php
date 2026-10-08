<?php
declare(strict_types=1);
require_once __DIR__ . '/listing_query.php';

final class NotificationInbox
{
    public static function list(PDO $db, int $userId, array $query): array
    {
        [$page, $limit, $offset] = ListingQuery::page($query);
        $search = ListingQuery::search($query);
        $order = ListingQuery::order($query, ['newest' => 'created_at DESC, id DESC', 'oldest' => 'created_at ASC, id ASC'], 'newest');
        $unread = filter_var($query['unread'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($unread === null) throw new TransactionBusinessException('Unread must be a boolean.', 422);
        $where = "WHERE user_id = :user_id AND channel = 'in_app'";
        $parameters = ['user_id' => $userId];
        if ($unread) $where .= " AND delivery_status != 'read'";
        if ($search !== '') {
            $where .= " AND (title LIKE :title ESCAPE '!' OR body LIKE :body ESCAPE '!')";
            $parameters['title'] = $parameters['body'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        }
        $count = $db->prepare("SELECT COUNT(*) FROM notifications {$where}"); $count->execute($parameters);
        $total = (int)$count->fetchColumn();
        $statement = $db->prepare("SELECT id, delivery_id, notification_type, title, body, payload, delivery_status, sent_at, read_at, created_at FROM notifications {$where} ORDER BY {$order} LIMIT :limit OFFSET :offset");
        foreach ($parameters as $name => $value) $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT); $statement->bindValue(':offset', $offset, PDO::PARAM_INT); $statement->execute();
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) $item['payload'] = $item['payload'] ? json_decode((string)$item['payload'], true) : null;
        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }
}
