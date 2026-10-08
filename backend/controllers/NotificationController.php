<?php

require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/notification_inbox.php';
require_once __DIR__ . '/../helpers/http_input.php';
require_once __DIR__ . '/../helpers/notification_service.php';

/** API endpoints for a user's own persisted in-app inbox. */
final class NotificationController
{
    public static function list(PDO $db, int $userId): void
    {
        self::ensureAvailable($db);
        try { $result = NotificationInbox::list($db, $userId, $_GET); }
        catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }
        Response::paginated($result['items'], $result['total'], $result['page'], $result['limit'], 'Notifications retrieved.');
    }

    public static function unreadCount(PDO $db, int $userId): void
    {
        self::ensureAvailable($db);
        $statement = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND channel = 'in_app' AND delivery_status != 'read'");
        $statement->execute([$userId]);
        Response::json(['unread_count' => (int)$statement->fetchColumn()], 'Unread notification count retrieved.');
    }

    public static function markRead(PDO $db, int $userId): void
    {
        self::ensureAvailable($db);
        try { $notificationId = HttpInput::positiveId(HttpInput::readObject(), 'notification_id'); }
        catch (TransactionBusinessException $e) { Response::error($e->getMessage(), $e->getStatusCode()); }

        $statement = $db->prepare(
            "UPDATE notifications
             SET delivery_status = 'read', read_at = COALESCE(read_at, NOW())
             WHERE id = ? AND user_id = ? AND channel = 'in_app'"
        );
        $statement->execute([$notificationId, $userId]);
        if ($statement->rowCount() === 0) {
            $exists = $db->prepare("SELECT id FROM notifications WHERE id = ? AND user_id = ? AND channel = 'in_app'");
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
             WHERE user_id = ? AND channel = 'in_app' AND delivery_status != 'read'"
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
