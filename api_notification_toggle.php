<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_login();

header('Content-Type: application/json');

$userId = (int)current_user()['id'];
$pdo = db();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'mark_all_read') {
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['success' => true, 'unread' => 0]);
    exit;
}

if ($action === 'toggle_read') {
    $notifId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($notifId > 0) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = CASE WHEN is_read = 1 THEN 0 ELSE 1 END WHERE id = ? AND user_id = ?");
        $stmt->execute([$notifId, $userId]);
    }
    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$userId]);
    $unread = (int)$unreadStmt->fetchColumn();
    echo json_encode(['success' => true, 'unread' => $unread]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
exit;

