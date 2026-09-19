<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/notification_service.php';

/** API endpoints for a user's own persisted in-app inbox. */
final class NotificationController
{
    public static function list(PDO $db, int $userId): void
    {
        self::ensureAvailable($db);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $unreadOnly = filter_var($_GET['unread'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $where = 'WHERE user_id = :user_id';
        if ($unreadOnly) {
            $where .= " AND delivery_status != 'read'";
        }

        $count = $db->prepare("SELECT COUNT(*) FROM notifications {$where}");
        $count->execute(['user_id' => $userId]);
        $total = (int)$count->fetchColumn();

        $statement = $db->prepare(
            "SELECT id, delivery_id, channel, notification_type, title, body, payload, delivery_status, sent_at, read_at, created_at
             FROM notifications {$where}
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset"
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['payload'] = $item['payload'] ? json_decode((string)$item['payload'], true) : null;
        }

        Response::paginated($items, $total, $page, $limit, 'Notifications retrieved.');
    }

    public static function unreadCount(PDO $db, int $userId): void
    {
        self::ensureAvailable($db);
        $statement = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND delivery_status != 'read'");
        $statement->execute([$userId]);
        Response::json(['unread_count' => (int)$statement->fetchColumn()], 'Unread notification count retrieved.');
    }

    public static function markRead(PDO $db, int $userId): void
    {
        self::ensureAvailable($db);
        $data = json_decode(file_get_contents('php://input'));
        $notificationId = (int)($data->notification_id ?? 0);
        if (!$notificationId) {
            Response::error('notification_id is required.');
        }

        $statement = $db->prepare(
            "UPDATE notifications
             SET delivery_status = 'read', read_at = COALESCE(read_at, NOW())
             WHERE id = ? AND user_id = ?"
        );
        $statement->execute([$notificationId, $userId]);
        if ($statement->rowCount() === 0) {
            $exists = $db->prepare('SELECT id FROM notifications WHERE id = ? AND user_id = ?');
            $exists->execute([$notificationId, $userId]);
            if (!$exists->fetchColumn()) {
                Response::notFound('Notification not found.');
            }
        }

        Response::json(['notification_id' => $notificationId, 'read' => true], 'Notification marked as read.');
    }

    public static function markAllRead(PDO $db, int $userId): void
    {
        self::ensureAvailable($db);
        $statement = $db->prepare(
            "UPDATE notifications
             SET delivery_status = 'read', read_at = COALESCE(read_at, NOW())
             WHERE user_id = ? AND delivery_status != 'read'"
        );
        $statement->execute([$userId]);
        Response::json(['marked_read' => $statement->rowCount()], 'All notifications marked as read.');
    }

    private static function ensureAvailable(PDO $db): void
    {
        if (!NotificationService::isAvailable($db)) {
            Response::error('Notifications are unavailable until the operational database migration is applied.', 503);
        }
    }
}
