<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_once __DIR__ . '/config/notifications.php';
require_login();

header('Content-Type: application/json');

$userId = (int)current_user()['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'mark_all_read') {
    mcoll('notifications')->updateMany(
        ['user_id' => $userId],
        ['$set' => ['is_read' => 1]]
    );
    echo json_encode(['success' => true, 'unread' => 0]);
    exit;
}

if ($action === 'toggle_read') {
    $notifId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($notifId > 0) {
        $notif = mcoll('notifications')->findOne(['id' => $notifId, 'user_id' => $userId]);
        if ($notif) {
            $newStatus = ((int)($notif['is_read'] ?? 0) === 1) ? 0 : 1;
            mcoll('notifications')->updateOne(
                ['id' => $notifId, 'user_id' => $userId],
                ['$set' => ['is_read' => $newStatus]]
            );
        }
    }
    $unread = get_unread_notification_count($userId);
    echo json_encode(['success' => true, 'unread' => $unread]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
exit;
