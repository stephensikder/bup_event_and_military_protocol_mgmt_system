<?php
declare(strict_types=1);

require_once __DIR__ . '/mongo.php';

/**
 * Add a notification for a user (MongoDB Atlas)
 */
function add_user_notification(int $userId, string $title, string $message, string $type = 'system', ?string $link = null): bool {
    try {
        $id = mongo_next_id('notifications');
        mcoll('notifications')->insertOne([
            'id' => $id,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'is_read' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('Error adding notification: ' . $e->getMessage());
        return false;
    }
}

/**
 * Seed notifications if a user has none (MongoDB Atlas)
 */
function ensure_user_notifications(int $userId): void {
    try {
        $count = mcoll('notifications')->countDocuments(['user_id' => $userId]);
        if ($count > 0) {
            return;
        }

        $user = mongo_doc_to_array(mcoll('users')->findOne(['id' => $userId]));
        if (!$user) return;

        $isStudent = (($user['role'] ?? '') === 'Student' || ($user['level'] ?? '') === 'Student');

        if ($isStudent) {
            // Student ONLY receives student-related alerts: newly scheduled events & campus announcements
            $ev = mongo_doc_to_array(mcoll('events')->findOne(
                ['status' => ['$in' => ['Upcoming', 'Ongoing']]],
                ['sort' => ['event_date' => 1]]
            ));
            if ($ev) {
                $evDate = !empty($ev['event_date']) ? date('d M Y', strtotime((string)$ev['event_date'])) : 'Upcoming';
                add_user_notification(
                    $userId,
                    'New University Event: ' . $ev['name'],
                    'A new official ceremony "' . $ev['name'] . '" has been published for ' . $evDate . ' at ' . ($ev['venue'] ?? 'BUP') . '.',
                    'event',
                    BASE_URL . '/student_dashboard.php?tab=events'
                );
            }

            $ann = mongo_doc_to_array(mcoll('announcements')->findOne(
                [],
                ['sort' => ['created_at' => -1]]
            ));
            if ($ann) {
                add_user_notification(
                    $userId,
                    'Campus Notice: ' . $ann['title'],
                    substr((string)($ann['message'] ?? ''), 0, 140) . '...',
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
        $ann = mongo_doc_to_array(mcoll('announcements')->findOne(
            [],
            ['sort' => ['created_at' => -1]]
        ));
        if ($ann) {
            add_user_notification(
                $userId,
                'Official Announcement: ' . $ann['title'],
                substr((string)($ann['message'] ?? ''), 0, 140) . '...',
                'announcement',
                BASE_URL . '/notifications.php'
            );
        }

        // 3. Duty notification if applicable
        $duty = mongo_doc_to_array(mcoll('duties')->findOne(
            ['$or' => [['assigned_to' => $userId], ['supervisor_id' => $userId]]],
            ['sort' => ['id' => -1]]
        ));
        if ($duty) {
            add_user_notification(
                $userId,
                'Duty Assignment Alert: ' . $duty['title'],
                'You have an active operational duty scheduled for ' . ($duty['duty_date'] ?: 'upcoming event') . ' (Status: ' . $duty['status'] . ').',
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
 * Get notifications for a user (MongoDB Atlas)
 */
function get_user_notifications(int $userId, int $limit = 15): array {
    ensure_user_notifications($userId);
    try {
        $isStudent = function_exists('is_student') ? is_student() : false;
        $filter = ['user_id' => $userId];
        if ($isStudent) {
            $filter['type'] = ['$nin' => ['duty_assigned', 'duty_allocated', 'duty']];
            $filter['title'] = ['$not' => new MongoDB\BSON\Regex('Duty', 'i')];
        }

        $cursor = mcoll('notifications')->find($filter, [
            'sort' => ['created_at' => -1],
            'limit' => $limit
        ]);
        return array_values(array_map('mongo_doc_to_array', iterator_to_array($cursor)));
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get unread count (MongoDB Atlas)
 */
function get_unread_notification_count(int $userId): int {
    try {
        $isStudent = function_exists('is_student') ? is_student() : false;
        $filter = ['user_id' => $userId, 'is_read' => 0];
        if ($isStudent) {
            $filter['type'] = ['$nin' => ['duty_assigned', 'duty_allocated', 'duty']];
            $filter['title'] = ['$not' => new MongoDB\BSON\Regex('Duty', 'i')];
        }
        return mcoll('notifications')->countDocuments($filter);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Notify level-to-level duty assignment (MongoDB Atlas)
 */
function notify_duty_assignment(int $dutyId, string $title, string $department, string $level, int $assignedTo, int $assignedByUserId): void {
    try {
        $sender = mongo_doc_to_array(mcoll('users')->findOne(['id' => $assignedByUserId]));
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
                $coords = mcoll('users')->find([
                    '$or' => [['level' => 'L2'], ['role' => 'Event Coordinator']],
                    'status' => 'Active'
                ]);
                foreach ($coords as $cDoc) {
                    $c = mongo_doc_to_array($cDoc);
                    add_user_notification(
                        (int)$c['id'],
                        'Executive Duty Directive: ' . $title,
                        'Event Director assigned coordination duty "' . $title . '".',
                        'duty_assigned',
                        BASE_URL . '/duties.php'
                    );
                }
            } elseif (strpos($level, 'L3') !== false || !empty($department)) {
                $cleanDept = str_replace('L3-', '', $department);
                $leads = mcoll('users')->find([
                    '$or' => [['level' => 'L3'], ['role' => 'Department Lead']],
                    'department' => $cleanDept,
                    'status' => 'Active'
                ]);
                foreach ($leads as $lDoc) {
                    $l = mongo_doc_to_array($lDoc);
                    add_user_notification(
                        (int)$l['id'],
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
 * Notify duty status updates (MongoDB Atlas)
 */
function notify_duty_status_change(int $dutyId, string $newStatus, int $updatedByUserId): void {
    try {
        $updater = mongo_doc_to_array(mcoll('users')->findOne(['id' => $updatedByUserId]));
        $updaterName = (string)($updater['name'] ?? 'User');

        $duty = mongo_doc_to_array(mcoll('duties')->findOne(['id' => $dutyId]));

        if ($duty) {
            $targets = array_unique([(int)($duty['assigned_by'] ?? 0), (int)($duty['supervisor_id'] ?? 0)]);
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
