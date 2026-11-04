<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Add a notification for a user
 */
function add_user_notification(int $userId, string $title, string $message, string $type = 'system', ?string $link = null): bool {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        return $stmt->execute([$userId, $type, $title, $message, $link]);
    } catch (Throwable $e) {
        error_log('Error adding notification: ' . $e->getMessage());
        return false;
    }
}

/**
 * Seed notifications if a user has none
 */
function ensure_user_notifications(int $userId): void {
    try {
        $pdo = db();
        $check = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
        $check->execute([$userId]);
        if ((int)$check->fetchColumn() > 0) {
            return;
        }

        $userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if (!$user) return;

        $isStudent = (($user['role'] ?? '') === 'Student' || ($user['level'] ?? '') === 'Student');

        if ($isStudent) {
            // Student ONLY receives student-related alerts: newly scheduled events & campus announcements
            $ev = $pdo->query("SELECT name, event_date, venue FROM events WHERE status IN ('Upcoming', 'Ongoing') ORDER BY event_date ASC LIMIT 1")->fetch();
            if ($ev) {
                add_user_notification(
                    $userId,
                    'New University Event: ' . $ev['name'],
                    'A new official ceremony "' . $ev['name'] . '" has been published for ' . date('d M Y', strtotime($ev['event_date'])) . ' at ' . $ev['venue'] . '.',
                    'event',
                    BASE_URL . '/student_dashboard.php?tab=events'
                );
            }

            $ann = $pdo->query("SELECT title, message FROM announcements ORDER BY created_at DESC LIMIT 1")->fetch();
            if ($ann) {
                add_user_notification(
                    $userId,
                    'Campus Notice: ' . $ann['title'],
                    substr($ann['message'], 0, 140) . '...',
                    'announcement',
                    BASE_URL . '/student_dashboard.php?tab=announcements'
                );
            }

            add_user_notification(
                $userId,
                'Student Account Verified',
                'Your institutional student profile has been verified. Access live ceremony schedules and emergency contacts.',
                'announcement',
                BASE_URL . '/student_dashboard.php'
            );
            return;
        }

        // 1. Login session alert for personnel
        add_user_notification(
            $userId,
            'Security: Active Login Session',
            'Your institutional login credentials were authenticated successfully for command access.',
            'login_logout',
            BASE_URL . '/profile.php'
        );

        // 2. Announcements
        $ann = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC LIMIT 1")->fetch();
        if ($ann) {
            add_user_notification(
                $userId,
                'Official Announcement: ' . $ann['title'],
                substr($ann['message'], 0, 140) . '...',
                'announcement',
                BASE_URL . '/notifications.php'
            );
        }

        // 3. Duty notification if applicable
        $duty = $pdo->prepare("SELECT * FROM duties WHERE assigned_to = ? OR supervisor_id = ? ORDER BY id DESC LIMIT 1");
        $duty->execute([$userId, $userId]);
        $d = $duty->fetch();
        if ($d) {
            add_user_notification(
                $userId,
                'Duty Assignment Alert: ' . $d['title'],
                'You have an active operational duty scheduled for ' . ($d['duty_date'] ?: 'upcoming event') . ' (Status: ' . $d['status'] . ').',
                'duty_assigned',
                BASE_URL . '/duties.php'
            );
        } else {
            add_user_notification(
                $userId,
                'Operational Duty Center Ready',
                'Duty roster synchronization is live. Check your assigned schedule and escalation chain.',
                'duty_allocated',
                BASE_URL . '/duties.php'
            );
        }
    } catch (Throwable $e) {
        error_log('Error ensuring notifications: ' . $e->getMessage());
    }
}

/**
 * Get notifications for a user
 */
function get_user_notifications(int $userId, int $limit = 15): array {
    ensure_user_notifications($userId);
    try {
        $pdo = db();
        $isStudent = function_exists('is_student') ? is_student() : false;
        if ($isStudent) {
            $stmt = $pdo->prepare("
                SELECT * FROM notifications 
                WHERE user_id = ? 
                  AND type NOT IN ('duty_assigned', 'duty_allocated', 'duty')
                  AND title NOT LIKE '%Duty%'
                ORDER BY created_at DESC 
                LIMIT ?
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT * FROM notifications 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
        }
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get unread count
 */
function get_unread_notification_count(int $userId): int {
    try {
        $pdo = db();
        $isStudent = function_exists('is_student') ? is_student() : false;
        if ($isStudent) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM notifications 
                WHERE user_id = ? 
                  AND is_read = 0
                  AND type NOT IN ('duty_assigned', 'duty_allocated', 'duty')
                  AND title NOT LIKE '%Duty%'
            ");
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        }
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Notify level-to-level duty assignment
 */
function notify_duty_assignment(int $dutyId, string $title, string $department, string $level, int $assignedTo, int $assignedByUserId): void {
    try {
        $pdo = db();
        $senderStmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
        $senderStmt->execute([$assignedByUserId]);
        $sender = $senderStmt->fetch();
        $senderName = $sender['name'] ?? 'Command';

        if ($assignedTo > 0) {
            add_user_notification(
                $assignedTo,
                'Duty Assigned: ' . $title,
                $senderName . ' assigned you duty "' . $title . '" [' . $level . ' - ' . $department . '].',
                'duty_assigned',
                BASE_URL . '/duties.php'
            );
        } else {
            if ($level === 'L2') {
                $coords = $pdo->query("SELECT id FROM users WHERE (level = 'L2' OR role = 'Event Coordinator') AND status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($coords as $cid) {
                    add_user_notification(
                        (int)$cid,
                        'Executive Duty Directive: ' . $title,
                        'Event Director assigned coordination duty "' . $title . '".',
                        'duty_assigned',
                        BASE_URL . '/duties.php'
                    );
                }
            } elseif (strpos($level, 'L3') !== false || !empty($department)) {
                $leads = $pdo->prepare("SELECT id FROM users WHERE (level = 'L3' OR role = 'Department Lead') AND department = ? AND status = 'Active'");
                $cleanDept = str_replace('L3-', '', $department);
                $leads->execute([$cleanDept]);
                foreach ($leads->fetchAll(PDO::FETCH_COLUMN) as $lid) {
                    add_user_notification(
                        (int)$lid,
                        'Department Duty: ' . $title,
                        'A new operational duty has been delegated to ' . $cleanDept . ' lead: "' . $title . '".',
                        'duty_assigned',
                        BASE_URL . '/duties.php'
                    );
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Error notifying duty assignment: ' . $e->getMessage());
    }
}

/**
 * Notify duty status updates
 */
function notify_duty_status_change(int $dutyId, string $newStatus, int $updatedByUserId): void {
    try {
        $pdo = db();
        $updaterStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $updaterStmt->execute([$updatedByUserId]);
        $updaterName = (string)($updaterStmt->fetchColumn() ?: 'User');

        $dStmt = $pdo->prepare("SELECT title, assigned_by, supervisor_id, assigned_to FROM duties WHERE id = ?");
        $dStmt->execute([$dutyId]);
        $duty = $dStmt->fetch();

        if ($duty) {
            $targets = array_unique([(int)$duty['assigned_by'], (int)$duty['supervisor_id']]);
            foreach ($targets as $uid) {
                if ($uid > 0 && $uid !== $updatedByUserId) {
                    add_user_notification(
                        $uid,
                        'Duty Status Updated: ' . $duty['title'],
                        $updaterName . ' updated status of "' . $duty['title'] . '" to ' . $newStatus . '.',
                        'system',
                        BASE_URL . '/duties.php'
                    );
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Error notifying duty status change: ' . $e->getMessage());
    }
}

