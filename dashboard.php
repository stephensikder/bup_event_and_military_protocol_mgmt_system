<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';

require_login();

$page_title = 'Dashboard | BUP Protocol';

/*
|--------------------------------------------------------------------------
| POST ACTION HANDLERS (MongoDB Atlas)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Quick status update for duties */
    if ($action === 'quick_status') {
        $id = (int)($_POST['id'] ?? 0);
        $new_status = trim($_POST['status'] ?? 'Completed');

        if ($id > 0) {
            mcoll('duties')->updateOne(['id' => $id], ['$set' => ['status' => $new_status]]);
            if (function_exists('notify_duty_status_change')) {
                notify_duty_status_change($id, $new_status, (int)current_user()['id']);
            }
            mongo_audit_log(
                current_user()['id'],
                'Duty Status Changed',
                'Set duty #' . $id . ' status to ' . $new_status
            );
            flash('success', 'Duty status set to ' . $new_status . '!');
        }
        redirect(BASE_URL . '/dashboard.php');
    }

    /* Rapid Duty Allocation from L2 / L3 */
    if ($action === 'allocate_duty') {
        $event_id = (int)($_POST['event_id'] ?? 1);
        $title = trim($_POST['title'] ?? '');
        $department = trim($_POST['department'] ?? 'Protocol');
        $level = trim($_POST['level'] ?? 'L5');
        $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $location = trim($_POST['location'] ?? '');
        $time_slot = trim($_POST['time_slot'] ?? '');
        $reports_to = trim($_POST['reports_to'] ?? '');
        $backup_person = trim($_POST['backup_person'] ?? '');
        $priority = trim($_POST['priority'] ?? 'Normal');

        if (is_team_lead()) {
            $department = current_department();
        }

        if ($title !== '') {
            $newDutyId = mongo_next_id('duties');
            $now = date('Y-m-d H:i:s');

            mcoll('duties')->insertOne([
                'id' => $newDutyId,
                'event_id' => $event_id,
                'title' => $title,
                'department' => $department,
                'level' => $level,
                'location' => $location,
                'time_slot' => $time_slot,
                'reports_to' => $reports_to,
                'backup_person' => $backup_person,
                'priority' => $priority,
                'assigned_to' => $assigned_to,
                'status' => 'Assigned',
                'assigned_by' => (int)current_user()['id'],
                'created_at' => $now
            ]);

            if (function_exists('notify_duty_assignment')) {
                notify_duty_assignment($newDutyId, $title, $department, $level, (int)$assigned_to, (int)current_user()['id']);
            }

            mongo_audit_log(
                current_user()['id'],
                'Duty Allocated',
                'Allocated duty: ' . $title . ' in ' . $department
            );
            flash('success', 'Duty "' . $title . '" allocated successfully to ' . $department . '!');
        }
        redirect(BASE_URL . '/dashboard.php');
    }

    /* Field Incident Escalation from L4 / L5 */
    if ($action === 'report_incident') {
        $incident = trim($_POST['incident'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $severity = trim($_POST['severity'] ?? 'Medium');

        if ($incident !== '') {
            mongo_audit_log(
                current_user()['id'],
                'FIELD ESCALATION (' . $severity . ')',
                'At ' . $location . ': ' . $incident
            );
            flash('success', 'Field incident escalated to Event Director & Command Desk!');
        }
        redirect(BASE_URL . '/dashboard.php');
    }

    /* Supervisor Progress Report to Event Coordinator (L2) */
    if ($action === 'send_supervisor_progress') {
        $progress_notes = trim($_POST['progress_notes'] ?? '');
        $progress_dept = trim($_POST['progress_dept'] ?? 'All Departments');
        $completion_pct = (int)($_POST['completion_pct'] ?? 50);

        if ($progress_notes !== '') {
            mongo_audit_log(
                current_user()['id'],
                'SUPERVISOR PROGRESS REPORT',
                '[' . $progress_dept . ' — ' . $completion_pct . '% Completed]: ' . $progress_notes
            );

            if (function_exists('add_user_notification')) {
                $l2Users = mcoll('users')->find([
                    '$or' => [
                        ['role' => 'Event Coordinator'],
                        ['level' => 'L2']
                    ]
                ]);
                foreach ($l2Users as $l2u) {
                    add_user_notification(
                        (int)$l2u['id'],
                        'duty_assigned',
                        'Ground Progress Update (' . $progress_dept . ')',
                        current_user()['name'] . ' (Supervisor) reported ' . $completion_pct . '% ground progress: ' . substr($progress_notes, 0, 120),
                        BASE_URL . '/duties.php'
                    );
                }
            }
            flash('success', 'Ground operations progress report successfully transmitted to Event Coordinator (L2)!');
        }
        redirect(BASE_URL . '/dashboard.php');
    }
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

if (!function_exists('dashboard_status_class')) {
    function dashboard_status_class(string $status): string
    {
        $allowed = [
            'upcoming',
            'ongoing',
            'completed',
            'cancelled',
            'approved',
            'rejected',
            'pending',
            'confirmed',
            'draft',
            'present',
            'absent',
            'registered',
        ];

        $class = strtolower(trim($status));

        return in_array($class, $allowed, true)
            ? $class
            : 'neutral';
    }
}

if (!function_exists('dashboard_percent')) {
    function dashboard_percent(int $done, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) round(($done / $total) * 100);
    }
}

/*
|--------------------------------------------------------------------------
| User
|--------------------------------------------------------------------------
*/

$user = current_user();
$user_name = $user['name'] ?? 'User';
$name_parts = preg_split('/\s+/', trim($user_name));
$initials = '';

foreach ($name_parts as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    if (strlen($initials) >= 2) {
        break;
    }
}

if ($initials === '') {
    $initials = 'U';
}

/*
|--------------------------------------------------------------------------
| Dashboard Statistics (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

$stats = [
    'events' => mcoll('events')->countDocuments(),
    'guests' => mcoll('guests')->countDocuments(),
    'duties' => mcoll('duties')->countDocuments(['status' => ['$ne' => 'Completed']]),
    'attendance' => mcoll('attendance')->countDocuments(['status' => 'Present']),
    'pending_approvals' => mcoll('events')->countDocuments(['approval_status' => ['$in' => ['Draft', 'Pending']]]),
    'pending_protocol' => mcoll('protocol_items')->countDocuments(['completed' => ['$in' => [0, false, '0']]]),
    'conflicts' => 0,
];

/*
|--------------------------------------------------------------------------
| Conflict Detection (MongoDB Atlas Aggregation Pipeline)
| Same date + same venue + same event time
|--------------------------------------------------------------------------
*/

$conflictPipeline = [
    [
        '$match' => [
            'status' => ['$ne' => 'Cancelled']
        ]
    ],
    [
        '$group' => [
            '_id' => [
                'event_date' => '$event_date',
                'venue' => '$venue',
                'event_time' => '$event_time'
            ],
            'count' => ['$sum' => 1],
            'items' => ['$push' => ['id' => '$id', 'name' => '$name']]
        ]
    ],
    [
        '$match' => [
            'count' => ['$gt' => 1]
        ]
    ]
];

$conflictsCursor = mcoll('events')->aggregate($conflictPipeline);
$conflicts = [];
$totalConflicts = 0;

foreach ($conflictsCursor as $cGroup) {
    $gCount = (int)($cGroup['count'] ?? 0);
    $totalConflicts += (int)($gCount * ($gCount - 1) / 2);
    $items = iterator_to_array($cGroup['items']);
    for ($i = 0; $i < count($items); $i++) {
        for ($j = $i + 1; $j < count($items); $j++) {
            if (count($conflicts) < 5) {
                $conflicts[] = [
                    'event_one_id' => $items[$i]['id'] ?? 0,
                    'event_one' => $items[$i]['name'] ?? '',
                    'event_date' => $cGroup['_id']['event_date'] ?? '',
                    'event_time' => $cGroup['_id']['event_time'] ?? '',
                    'venue' => $cGroup['_id']['venue'] ?? '',
                    'event_two_id' => $items[$j]['id'] ?? 0,
                    'event_two' => $items[$j]['name'] ?? ''
                ];
            }
        }
    }
}
$stats['conflicts'] = $totalConflicts;

/*
|--------------------------------------------------------------------------
| Upcoming Events (MongoDB Atlas)
|--------------------------------------------------------------------------
*/
$todayStr = date('Y-m-d');
$upcomingCursor = mcoll('events')->find([
    'event_date' => ['$gte' => $todayStr],
    'status' => ['$ne' => 'Cancelled']
], [
    'sort' => ['event_date' => 1, 'event_time' => 1],
    'limit' => 6
]);

$upcoming = [];
foreach ($upcomingCursor as $doc) {
    $upcoming[] = mongo_doc_to_array($doc);
}

/*
|--------------------------------------------------------------------------
| Ongoing Events (MongoDB Atlas)
|--------------------------------------------------------------------------
*/
$ongoingCursor = mcoll('events')->find([
    'status' => 'Ongoing'
], [
    'sort' => ['event_date' => 1, 'event_time' => 1],
    'limit' => 5
]);

$ongoing = [];
foreach ($ongoingCursor as $doc) {
    $ongoing[] = mongo_doc_to_array($doc);
}

/*
|--------------------------------------------------------------------------
| Focus Event
|--------------------------------------------------------------------------
*/
$focusEvent = null;
if (!empty($ongoing)) {
    $focusEvent = $ongoing[0];
} elseif (!empty($upcoming)) {
    $focusEvent = $upcoming[0];
}

/*
|--------------------------------------------------------------------------
| Focus Event Operational Data (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

$focusDuties = [];
$focusProtocol = [];
$focusGuests = [];
$focusAttendance = [
    'registered' => 0,
    'present' => 0,
    'absent' => 0,
];

$focusDutyTotal = 0;
$focusDutyCompleted = 0;
$focusProtocolTotal = 0;
$focusProtocolCompleted = 0;
$focusGuestTotal = 0;

if ($focusEvent) {
    $focusEventId = (int)$focusEvent['id'];

    /* Duties */
    $fdCursor = mcoll('duties')->find(['event_id' => $focusEventId]);
    $rawDuties = [];
    foreach ($fdCursor as $dDoc) {
        $rawDuties[] = mongo_doc_to_array($dDoc);
    }

    $focusDutyTotal = count($rawDuties);
    foreach ($rawDuties as $d) {
        if (($d['status'] ?? '') === 'Completed') {
            $focusDutyCompleted++;
        }
    }

    usort($rawDuties, function($a, $b) {
        $getPriority = function($st) {
            if ($st === 'Ongoing') return 1;
            if ($st === 'Assigned') return 2;
            if ($st === 'Pending') return 3;
            return 4;
        };
        $pA = $getPriority($a['status'] ?? '');
        $pB = $getPriority($b['status'] ?? '');
        if ($pA !== $pB) return $pA <=> $pB;
        return strcmp($a['due_at'] ?? '', $b['due_at'] ?? '');
    });
    $focusDuties = array_slice($rawDuties, 0, 8);

    /* Protocol */
    $fpCursor = mcoll('protocol_items')->find(['event_id' => $focusEventId], ['sort' => ['sort_order' => 1, 'id' => 1]]);
    $rawProtocol = [];
    foreach ($fpCursor as $pDoc) {
        $rawProtocol[] = mongo_doc_to_array($pDoc);
    }
    $focusProtocolTotal = count($rawProtocol);
    foreach ($rawProtocol as $p) {
        if (!empty($p['completed'])) {
            $focusProtocolCompleted++;
        }
    }
    $focusProtocol = array_slice($rawProtocol, 0, 8);

    /* Guests */
    $fgCursor = mcoll('guests')->find(['event_id' => $focusEventId], ['sort' => ['priority_level' => -1, 'name' => 1]]);
    $rawGuests = [];
    foreach ($fgCursor as $gDoc) {
        $rawGuests[] = mongo_doc_to_array($gDoc);
    }
    $focusGuestTotal = count($rawGuests);
    $focusGuests = array_slice($rawGuests, 0, 8);

    /* Attendance */
    $focusAttendance = [
        'registered' => mcoll('attendance')->countDocuments(['event_id' => $focusEventId]),
        'present' => mcoll('attendance')->countDocuments(['event_id' => $focusEventId, 'status' => 'Present']),
        'absent' => mcoll('attendance')->countDocuments(['event_id' => $focusEventId, 'status' => 'Absent']),
    ];
}

/*
|--------------------------------------------------------------------------
| Focus Event Progress
|--------------------------------------------------------------------------
*/

$dutyProgress = dashboard_percent($focusDutyCompleted, $focusDutyTotal);
$protocolProgress = dashboard_percent($focusProtocolCompleted, $focusProtocolTotal);
$overallOperationalProgress = dashboard_percent(
    $focusDutyCompleted + $focusProtocolCompleted,
    $focusDutyTotal + $focusProtocolTotal
);

/*
|--------------------------------------------------------------------------
| Event Timeline
|--------------------------------------------------------------------------
*/

$timeline = [];
if ($focusEvent) {
    if (!empty($focusEvent['created_at'])) {
        $timeline[] = [
            'title' => 'Event Created',
            'description' => 'Event record was created in the system.',
            'time' => $focusEvent['created_at'],
            'class' => 'done',
        ];
    }

    if (!empty($focusEvent['approved_at'])) {
        $timeline[] = [
            'title' => 'Approval Completed',
            'description' => 'Event approval was recorded.',
            'time' => $focusEvent['approved_at'],
            'class' => 'done',
        ];
    } else {
        $timeline[] = [
            'title' => 'Approval Pending',
            'description' => 'Event is waiting for approval workflow action.',
            'time' => $focusEvent['approval_status'] ?? 'Pending',
            'class' => 'pending',
        ];
    }

    $timeline[] = [
        'title' => 'Event Schedule',
        'description' => $focusEvent['venue'] ?? 'Venue not specified',
        'time' => ($focusEvent['event_date'] ?? '') . ' · ' . substr(($focusEvent['event_time'] ?? ''), 0, 5),
        'class' => 'upcoming',
    ];

    $timeline[] = [
        'title' => 'Duty Preparation',
        'description' => $focusDutyCompleted . ' of ' . $focusDutyTotal . ' duties completed.',
        'time' => $dutyProgress . '%',
        'class' => $dutyProgress >= 100 ? 'done' : 'pending',
    ];

    $timeline[] = [
        'title' => 'Protocol Checklist',
        'description' => $focusProtocolCompleted . ' of ' . $focusProtocolTotal . ' items completed.',
        'time' => $protocolProgress . '%',
        'class' => $protocolProgress >= 100 ? 'done' : 'pending',
    ];
}

/*
|--------------------------------------------------------------------------
| Recent Audit Activity (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$recentPipeline = [
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'user_id',
            'foreignField' => 'id',
            'as' => 'user'
        ]
    ],
    ['$unwind' => ['path' => '$user', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['created_at' => -1, 'id' => -1]],
    ['$limit' => 6]
];
$recentCursor = mcoll('audit_logs')->aggregate($recentPipeline);
$recent = [];
foreach ($recentCursor as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['name'] = $row['user']['name'] ?? 'System';
    unset($row['user']);
    $recent[] = $row;
}

/*
|--------------------------------------------------------------------------
| Recent / Upcoming Duty Deadlines (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

$deadlinePipeline = [
    [
        '$match' => [
            'status' => ['$ne' => 'Completed']
        ]
    ],
    [
        '$lookup' => [
            'from' => 'events',
            'localField' => 'event_id',
            'foreignField' => 'id',
            'as' => 'event'
        ]
    ],
    ['$unwind' => ['path' => '$event', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['due_at' => 1]],
    ['$limit' => 5]
];
$deadlineCursor = mcoll('duties')->aggregate($deadlinePipeline);
$deadlineDuties = [];
foreach ($deadlineCursor as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['event_name'] = $row['event']['name'] ?? null;
    unset($row['event']);
    $deadlineDuties[] = $row;
}

/*
|--------------------------------------------------------------------------
| 5-LEVEL CHAIN OF COMMAND DATA & LEVEL INTERFACES
|--------------------------------------------------------------------------
*/
$active_level = current_level();
$user_dept = current_department();
$user_id = (int)($user['id'] ?? 0);

// Department counts for L1 / L2
$deptDutyCounts = [
    'Venue' => ['total' => 0, 'completed' => 0, 'ongoing' => 0, 'label' => 'Venue Management', 'sub' => 'Setup, Stage, AV, Cleanliness', 'color' => '#166534', 'bg' => '#dcfce7'],
    'Security' => ['total' => 0, 'completed' => 0, 'ongoing' => 0, 'label' => 'Safety & Security', 'sub' => 'Access control, Crowd, Emergency', 'color' => '#991b1b', 'bg' => '#fee2e2'],
    'Logistics' => ['total' => 0, 'completed' => 0, 'ongoing' => 0, 'label' => 'Transport & Supply', 'sub' => 'Transport, Equipment, Food', 'color' => '#92400e', 'bg' => '#fef3c7'],
    'Protocol' => ['total' => 0, 'completed' => 0, 'ongoing' => 0, 'label' => 'Guest & VIP Handling', 'sub' => 'Reception, VIP movement, Protocol', 'color' => '#6b21a8', 'bg' => '#f3e8ff'],
    'Communication' => ['total' => 0, 'completed' => 0, 'ongoing' => 0, 'label' => 'Media & Information', 'sub' => 'Internal comm, Media, Desk', 'color' => '#155e75', 'bg' => '#cffafe'],
];

$deptAgg = mcoll('duties')->aggregate([
    [
        '$match' => [
            'department' => ['$ne' => null, '$ne' => '']
        ]
    ],
    [
        '$group' => [
            '_id' => [
                'department' => '$department',
                'status' => '$status'
            ],
            'cnt' => ['$sum' => 1]
        ]
    ]
]);

foreach ($deptAgg as $grp) {
    $d = $grp['_id']['department'] ?? '';
    $st = $grp['_id']['status'] ?? '';
    $cnt = (int)($grp['cnt'] ?? 0);
    if (isset($deptDutyCounts[$d])) {
        $deptDutyCounts[$d]['total'] += $cnt;
        if ($st === 'Completed') {
            $deptDutyCounts[$d]['completed'] += $cnt;
        } elseif ($st === 'Ongoing') {
            $deptDutyCounts[$d]['ongoing'] += $cnt;
        }
    }
}

// User's own assigned duties or Supervisor ground operations (MongoDB Atlas)
if (is_supervisor()) {
    $supPipeline = [
        [
            '$match' => [
                '$or' => [
                    ['supervisor_id' => $user_id],
                    ['assigned_by' => $user_id],
                    ['assigned_to' => $user_id],
                    ['level' => ['$in' => ['L4', 'L5']]]
                ]
            ]
        ],
        [
            '$lookup' => [
                'from' => 'events',
                'localField' => 'event_id',
                'foreignField' => 'id',
                'as' => 'event'
            ]
        ],
        ['$unwind' => ['path' => '$event', 'preserveNullAndEmptyArrays' => true]],
        [
            '$lookup' => [
                'from' => 'users',
                'localField' => 'assigned_to',
                'foreignField' => 'id',
                'as' => 'assignee'
            ]
        ],
        ['$unwind' => ['path' => '$assignee', 'preserveNullAndEmptyArrays' => true]]
    ];
    $supDutiesCursor = mcoll('duties')->aggregate($supPipeline);
    $myDuties = [];
    foreach ($supDutiesCursor as $doc) {
        $row = mongo_doc_to_array($doc);
        $row['event_name'] = $row['event']['name'] ?? null;
        $row['assignee_name'] = $row['assignee']['name'] ?? null;
        unset($row['event'], $row['assignee']);
        $myDuties[] = $row;
    }

    usort($myDuties, function($a, $b) {
        $getPriority = function($st) {
            if ($st === 'Ongoing') return 1;
            if ($st === 'Assigned') return 2;
            if ($st === 'Pending') return 3;
            return 4;
        };
        $pA = $getPriority($a['status'] ?? '');
        $pB = $getPriority($b['status'] ?? '');
        if ($pA !== $pB) return $pA <=> $pB;
        return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
    });
    $myDuties = array_slice($myDuties, 0, 10);
} else {
    $assignPipeline = [
        ['$match' => ['assigned_to' => $user_id]],
        [
            '$lookup' => [
                'from' => 'events',
                'localField' => 'event_id',
                'foreignField' => 'id',
                'as' => 'event'
            ]
        ],
        ['$unwind' => ['path' => '$event', 'preserveNullAndEmptyArrays' => true]],
        [
            '$lookup' => [
                'from' => 'users',
                'localField' => 'assigned_to',
                'foreignField' => 'id',
                'as' => 'assignee'
            ]
        ],
        ['$unwind' => ['path' => '$assignee', 'preserveNullAndEmptyArrays' => true]]
    ];
    $assignCursor = mcoll('duties')->aggregate($assignPipeline);
    $myDuties = [];
    foreach ($assignCursor as $doc) {
        $row = mongo_doc_to_array($doc);
        $row['event_name'] = $row['event']['name'] ?? null;
        $row['assignee_name'] = $row['assignee']['name'] ?? null;
        unset($row['event'], $row['assignee']);
        $myDuties[] = $row;
    }

    usort($myDuties, function($a, $b) {
        $getPriority = function($st) {
            if ($st === 'Ongoing') return 1;
            if ($st === 'Assigned') return 2;
            if ($st === 'Pending') return 3;
            return 4;
        };
        $pA = $getPriority($a['status'] ?? '');
        $pB = $getPriority($b['status'] ?? '');
        if ($pA !== $pB) return $pA <=> $pB;
        return strcmp($a['due_at'] ?? '', $b['due_at'] ?? '');
    });

    if (empty($myDuties) && in_array($active_level, ['L4', 'L5'])) {
        $previewPipeline = [
            ['$match' => ['level' => $active_level]],
            [
                '$lookup' => [
                    'from' => 'events',
                    'localField' => 'event_id',
                    'foreignField' => 'id',
                    'as' => 'event'
                ]
            ],
            ['$unwind' => ['path' => '$event', 'preserveNullAndEmptyArrays' => true]],
            [
                '$lookup' => [
                    'from' => 'users',
                    'localField' => 'assigned_to',
                    'foreignField' => 'id',
                    'as' => 'assignee'
                ]
            ],
            ['$unwind' => ['path' => '$assignee', 'preserveNullAndEmptyArrays' => true]],
            ['$sort' => ['due_at' => 1]],
            ['$limit' => 6]
        ];
        $previewCursor = mcoll('duties')->aggregate($previewPipeline);
        foreach ($previewCursor as $pDoc) {
            $row = mongo_doc_to_array($pDoc);
            $row['event_name'] = $row['event']['name'] ?? null;
            $row['assignee_name'] = $row['assignee']['name'] ?? null;
            unset($row['event'], $row['assignee']);
            $myDuties[] = $row;
        }
    }
}

// Department duties (for L3 & L4)
$deptMatchStage = [];
if ($user_dept !== 'General' && $user_dept !== 'Overall Command') {
    $deptMatchStage['department'] = $user_dept;
}

$deptDutiesPipeline = [];
if (!empty($deptMatchStage)) {
    $deptDutiesPipeline[] = ['$match' => $deptMatchStage];
}
$deptDutiesPipeline = array_merge($deptDutiesPipeline, [
    [
        '$lookup' => [
            'from' => 'events',
            'localField' => 'event_id',
            'foreignField' => 'id',
            'as' => 'event'
        ]
    ],
    ['$unwind' => ['path' => '$event', 'preserveNullAndEmptyArrays' => true]],
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'assigned_to',
            'foreignField' => 'id',
            'as' => 'assignee'
        ]
    ],
    ['$unwind' => ['path' => '$assignee', 'preserveNullAndEmptyArrays' => true]]
]);

$deptCursor = mcoll('duties')->aggregate($deptDutiesPipeline);
$deptDuties = [];
foreach ($deptCursor as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['event_name'] = $row['event']['name'] ?? null;
    $row['assignee_name'] = $row['assignee']['name'] ?? null;
    $row['assignee_role'] = $row['assignee']['role'] ?? null;
    $row['assignee_level'] = $row['assignee']['level'] ?? null;
    unset($row['event'], $row['assignee']);
    $deptDuties[] = $row;
}

usort($deptDuties, function($a, $b) {
    $getPriority = function($st) {
        if ($st === 'Ongoing') return 1;
        if ($st === 'Assigned') return 2;
        if ($st === 'Pending') return 3;
        return 4;
    };
    $pA = $getPriority($a['status'] ?? '');
    $pB = $getPriority($b['status'] ?? '');
    if ($pA !== $pB) return $pA <=> $pB;
    return strcmp($a['due_at'] ?? '', $b['due_at'] ?? '');
});

// Full cross-department duty alignment list for L2 Event Coordinator (Requirement 6)
$l2Pipeline = [
    [
        '$lookup' => [
            'from' => 'events',
            'localField' => 'event_id',
            'foreignField' => 'id',
            'as' => 'event'
        ]
    ],
    ['$unwind' => ['path' => '$event', 'preserveNullAndEmptyArrays' => true]],
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'assigned_to',
            'foreignField' => 'id',
            'as' => 'u_staff'
        ]
    ],
    ['$unwind' => ['path' => '$u_staff', 'preserveNullAndEmptyArrays' => true]],
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'supervisor_id',
            'foreignField' => 'id',
            'as' => 'u_sup'
        ]
    ],
    ['$unwind' => ['path' => '$u_sup', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['duty_date' => -1, 'start_time' => 1, 'id' => -1]]
];

$l2Cursor = mcoll('duties')->aggregate($l2Pipeline);
$l2AlignmentDuties = [];

// Preload leads by department
$deptLeads = [];
$leadsCursor = mcoll('users')->find([
    '$or' => [
        ['role' => 'Department Lead'],
        ['level' => 'L3']
    ]
]);
foreach ($leadsCursor as $lDoc) {
    $ld = mongo_doc_to_array($lDoc);
    $d = $ld['department'] ?? '';
    if ($d && !isset($deptLeads[$d])) {
        $deptLeads[$d] = $ld['name'] ?? null;
    }
}

foreach ($l2Cursor as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['event_name'] = $row['event']['name'] ?? 'BUP Annual Protocol Ceremony';
    $row['staff_name'] = $row['u_staff']['name'] ?? null;
    $row['staff_level'] = $row['u_staff']['level'] ?? null;
    $row['supervisor_name'] = $row['u_sup']['name'] ?? null;
    $row['lead_name'] = $deptLeads[$row['department'] ?? ''] ?? null;
    unset($row['event'], $row['u_staff'], $row['u_sup']);
    $l2AlignmentDuties[] = $row;
}

// Users in this department & active users
$activeUserDocs = mcoll('users')->find(['status' => 'Active'], ['sort' => ['level' => 1, 'name' => 1]]);
$allActiveUsers = [];
foreach ($activeUserDocs as $uDoc) {
    $allActiveUsers[] = mongo_doc_to_array($uDoc);
}

$deptAssignees = [];
$deptSupervisors = [];
$deptStaff = [];
foreach ($allActiveUsers as $au) {
    if (is_team_lead()) {
        if (strcasecmp($au['department'] ?? '', $user_dept) === 0) {
            $deptAssignees[] = $au;
            if (($au['level'] ?? '') === 'L4' || ($au['role'] ?? '') === 'Supervisor') {
                $deptSupervisors[] = $au;
            } elseif (($au['level'] ?? '') === 'L5' || strpos($au['role'] ?? '', 'Staff') !== false || strpos($au['role'] ?? '', 'Volunteer') !== false) {
                $deptStaff[] = $au;
            }
        }
    } else {
        $deptAssignees[] = $au;
        if (($au['level'] ?? '') === 'L4' || ($au['role'] ?? '') === 'Supervisor') {
            $deptSupervisors[] = $au;
        } elseif (($au['level'] ?? '') === 'L5' || strpos($au['role'] ?? '', 'Staff') !== false || strpos($au['role'] ?? '', 'Volunteer') !== false) {
            $deptStaff[] = $au;
        }
    }
}

// Events dropdown
$evDropDocs = mcoll('events')->find([], ['sort' => ['event_date' => 1]]);
$eventsDropdown = [];
foreach ($evDropDocs as $ed) {
    $eventsDropdown[] = mongo_doc_to_array($ed);
}

// Urgent/High priority duties
$urgentPipeline = [
    [
        '$match' => [
            'priority' => ['$in' => ['Urgent', 'High']],
            'status' => ['$ne' => 'Completed']
        ]
    ],
    [
        '$lookup' => [
            'from' => 'events',
            'localField' => 'event_id',
            'foreignField' => 'id',
            'as' => 'event'
        ]
    ],
    ['$unwind' => ['path' => '$event', 'preserveNullAndEmptyArrays' => true]],
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'assigned_to',
            'foreignField' => 'id',
            'as' => 'assignee'
        ]
    ],
    ['$unwind' => ['path' => '$assignee', 'preserveNullAndEmptyArrays' => true]]
];

$urgentCursor = mcoll('duties')->aggregate($urgentPipeline);
$urgentDuties = [];
foreach ($urgentCursor as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['event_name'] = $row['event']['name'] ?? null;
    $row['assignee_name'] = $row['assignee']['name'] ?? null;
    unset($row['event'], $row['assignee']);
    $urgentDuties[] = $row;
}

usort($urgentDuties, function($a, $b) {
    $pA = ($a['priority'] ?? '') === 'Urgent' ? 1 : 2;
    $pB = ($b['priority'] ?? '') === 'Urgent' ? 1 : 2;
    if ($pA !== $pB) return $pA <=> $pB;
    return strcmp($a['due_at'] ?? '', $b['due_at'] ?? '');
});
$urgentDuties = array_slice($urgentDuties, 0, 6);

require __DIR__ . '/includes/header.php';

?>

<!-- =========================================================
     DASHBOARD HEADER
========================================================= -->

<div class="page-head">

    <div>

        <span class="eyebrow">MILITARY-STYLE DUTY ASSIGNMENT COMMAND CENTER</span>

        <h1>
            Good day, <?= e(is_supervisor() ? 'Supervisor' : $user_name) ?>.
        </h1>

        <p>
            Role: <strong><?= e(is_supervisor() ? 'Supervisor' : ($user['role'] ?? 'User')) ?></strong> &bull; Level: <strong><?= e($active_level) ?></strong><?php if (!is_supervisor() && !empty($user_dept)): ?> &bull; Department: <strong><?= e($user_dept) ?></strong><?php endif; ?>
        </p>

    </div>

    <div style="display:flex; gap:9px; align-items:center; flex-wrap:wrap;">

        <a class="btn btn-light" href="<?= BASE_URL ?>/duties.php">
            All Duties Roster
        </a>

        <?php if (can_create_events()): ?>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/events.php?action=new">
            + Create Event
        </a>
        <?php endif; ?>

    </div>

</div>





<?php if ($active_level === 'L1'): ?>
<!-- =========================================================
     LEVEL 1: EVENT DIRECTOR — OVERALL COMMAND INTERFACE
========================================================= -->
<section class="panel" style="margin-bottom:26px; border:2px solid #082d21; border-radius:20px; overflow:hidden;">

    <div style="padding:22px 28px; background:linear-gradient(135deg, #051d14 0%, #082d21 50%, #0e3f2f 100%); color:#ffffff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <span style="background:#d4af37; color:#062218; font-weight:900; font-size:11px; padding:4px 8px; border-radius:5px; font-family:monospace;">LEVEL 1</span>
                <span style="color:#f5d98d; font-size:12px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase;">Overall Command Deck</span>
            </div>
            <h2 style="margin:0; font-size:22px; color:#ffffff;">Event Director Operations &amp; Escalation Hub</h2>
            <p style="margin:6px 0 0; color:#cbd5e1; font-size:13px;">
                Responsible for: Decision making, Event Approvals, Cross-Department Oversight &amp; High-level Escalations.
            </p>
        </div>
        <div style="display:flex; gap:10px;">
            <a class="btn btn-primary" href="<?= BASE_URL ?>/approvals.php" style="background:#d4af37; color:#062218; border:none; font-weight:700;">
                Review Approvals (<?= $stats['pending_approvals'] ?>)
            </a>
            <a class="btn btn-light" href="<?= BASE_URL ?>/duties.php?lvl=L1">
                Command Duties
            </a>
        </div>
    </div>

    <!-- 5 Department Readiness Cards -->
    <div style="padding:24px 28px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
        <div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:14px; color:#0f172a; text-transform:uppercase; letter-spacing:1px;">
                5 Departments Readiness Overview
            </strong>
            <span style="font-size:12px; color:#64748b;">
                Chain of Command: Venue &bull; Security &bull; Logistics &bull; Protocol &bull; Communication
            </span>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:14px;">
            <?php foreach ($deptDutyCounts as $dKey => $dInfo): ?>
                <?php
                $pct = $dInfo['total'] > 0 ? (int)round(($dInfo['completed'] / $dInfo['total']) * 100) : 100;
                ?>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-top:4px solid <?= $dInfo['color'] ?>; border-radius:12px; padding:16px; display:flex; flex-direction:column; justify-content:space-between;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                            <strong style="font-size:14px; color:#0f172a;"><?= e($dInfo['label']) ?></strong>
                            <span style="font-size:10px; font-weight:800; padding:2px 6px; border-radius:4px; background:<?= $dInfo['bg'] ?>; color:<?= $dInfo['color'] ?>;">
                                <?= $pct ?>% Done
                            </span>
                        </div>
                        <p style="margin:0 0 12px; font-size:11px; color:#64748b; line-height:1.3;">
                            <?= e($dInfo['sub']) ?>
                        </p>
                    </div>

                    <div>
                        <div style="display:flex; justify-content:space-between; font-size:11px; color:#475569; margin-bottom:4px;">
                            <span>Tasks: <strong><?= $dInfo['total'] ?></strong></span>
                            <span>Active: <strong style="color:#d97706;"><?= $dInfo['ongoing'] ?></strong></span>
                            <span>Done: <strong style="color:#16a34a;"><?= $dInfo['completed'] ?></strong></span>
                        </div>
                        <div style="height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden;">
                            <div style="width:<?= $pct ?>%; height:100%; background:<?= $dInfo['color'] ?>; transition:width .4s;"></div>
                        </div>
                        <div style="margin-top:10px; text-align:right;">
                            <a href="<?= BASE_URL ?>/duties.php?dept=<?= urlencode($dKey) ?>" style="font-size:11px; font-weight:700; color:<?= $dInfo['color'] ?>; text-decoration:none;">
                                View Duties &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- High Priority & Escalated Duties -->
    <div style="padding:22px 28px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
            <div>
                <strong style="font-size:15px; color:#0f172a;">Priority Escalation &amp; Command Attention</strong>
                <p style="margin:2px 0 0; font-size:12px; color:#64748b;">Duties flagged as Urgent or High Priority requiring Director oversight.</p>
            </div>
            <a href="<?= BASE_URL ?>/duties.php" class="btn btn-light" style="font-size:11px; padding:5px 10px;">View All</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Duty</th>
                        <th>Department</th>
                        <th>Location &amp; Time</th>
                        <th>Assignee</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($urgentDuties as $ud): ?>
                        <tr>
                            <td>
                                <strong><?= e($ud['title']) ?></strong>
                                <small style="display:block; color:#64748b;"><?= e($ud['event_name'] ?? 'General Event') ?></small>
                            </td>
                            <td>
                                <span class="badge neutral"><?= e($ud['department'] ?? 'Protocol') ?></span>
                            </td>
                            <td>
                                <strong><?= e($ud['location'] ?: 'Command Desk') ?></strong>
                                <small style="display:block; color:#64748b;"><?= e($ud['time_slot'] ?: 'Full Event') ?></small>
                            </td>
                            <td>
                                <?= e($ud['assignee_name'] ?: 'Unassigned') ?>
                            </td>
                            <td>
                                <span style="font-weight:800; font-size:11px; color:<?= ($ud['priority'] === 'Urgent') ? '#ef4444' : '#f97316' ?>;">
                                    &bull; <?= e($ud['priority']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= strtolower($ud['status']) ?>"><?= e($ud['status']) ?></span>
                            </td>
                            <td>
                                <a href="<?= BASE_URL ?>/duties.php?edit=<?= $ud['id'] ?>#duty-form" class="table-link">Review</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$urgentDuties): ?>
                        <tr>
                            <td colspan="7" class="empty">No high priority duty escalations at this time. All sectors nominal.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</section>


<?php elseif ($active_level === 'L2'): ?>
<!-- =========================================================
     LEVEL 2: EVENT COORDINATOR — OPERATIONAL COORDINATION
========================================================= -->
<section class="panel" style="margin-bottom:26px; border:2px solid #0d563e; border-radius:20px; overflow:hidden;">

    <div style="padding:22px 28px; background:linear-gradient(135deg, #082d21 0%, #0f4d38 50%, #15664b 100%); color:#ffffff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <span style="background:#ffffff; color:#082d21; font-weight:900; font-size:11px; padding:4px 8px; border-radius:5px; font-family:monospace;">LEVEL 2</span>
                <span style="color:#d1fae5; font-size:12px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase;">Overall Coordination Desk</span>
            </div>
            <h2 style="margin:0; font-size:22px; color:#ffffff;">Event Coordinator &mdash; Multi-Team Allocation Matrix</h2>
            <p style="margin:6px 0 0; color:#d1fae5; font-size:13px;">
                Aligns all 5 department teams, allocates cross-functional duties &amp; tracks operational execution.
            </p>
        </div>
        <div>
            <a class="btn btn-light" href="#rapid-dispatch" style="font-weight:700;">
                + Rapid Duty Dispatch
            </a>
        </div>
    </div>

    <!-- Rapid Duty Allocation Form (Directly on Dashboard) -->
    <div id="rapid-dispatch" style="padding:22px 28px; background:#f0f7ff; border-bottom:1px solid #bfdbfe;">
        <div style="margin-bottom:12px;">
            <strong style="color:#1e40af; font-size:14px; text-transform:uppercase; letter-spacing:1px;">
                ⚡ Quick Duty Dispatch &amp; Allocation (L2 Command)
            </strong>
            <p style="margin:2px 0 0; font-size:12px; color:#475569;">Assign a new operational task directly to any department lead or ground supervisor.</p>
        </div>

        <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
            <input type="hidden" name="action" value="allocate_duty">

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#334155;">
                Target Event
                <select name="event_id" required style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 8px;">
                    <?php foreach ($eventsDropdown as $ed): ?>
                        <option value="<?= $ed['id'] ?>"><?= e($ed['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#334155;">
                Duty Title
                <input type="text" name="title" placeholder="e.g. Red Carpet Reception Coordination" required style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 10px;">
            </label>

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#334155;">
                Department
                <select name="department" required style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 8px;">
                    <option value="Protocol">Guest &amp; VIP Handling</option>
                    <option value="Venue">Venue Management</option>
                    <option value="Security">Safety &amp; Security</option>
                    <option value="Logistics">Transport &amp; Supply</option>
                    <option value="Communication">Media &amp; Information</option>
                </select>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#334155;">
                Assign Level
                <select name="level" required style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 8px;">
                    <option value="L3">L3 &mdash; Dept Lead</option>
                    <option value="L4">L4 &mdash; Supervisor</option>
                    <option value="L5" selected>L5 &mdash; Staff / Volunteer</option>
                </select>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#334155;">
                Assign Personnel
                <select name="assigned_to" style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 8px;">
                    <option value="">Select Assignee...</option>
                    <?php foreach ($allActiveUsers as $au): ?>
                        <option value="<?= $au['id'] ?>"><?= e($au['name']) ?> (<?= e($au['role']) ?> &bull; <?= e($au['level']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#334155;">
                Location &amp; Time
                <input type="text" name="location" placeholder="e.g. Auditorium Hall" style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 10px;">
            </label>

            <button type="submit" class="btn btn-primary" style="min-height:42px; font-weight:700; background:#2563eb; border-color:#2563eb; white-space:nowrap;">
                + Dispatch Duty
            </button>
        </form>
    </div>

    <!-- Cross-Department Duty Alignment Table (14 Columns, Requirement 6) -->
    <div style="padding:22px 28px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <div>
                <strong style="font-size:16px; color:#0f172a;">Cross-Department Duty Alignment Matrix</strong>
                <p style="margin:2px 0 0; font-size:12px; color:#64748b;">Comprehensive 14-column synchronization across all 5 operational departments.</p>
            </div>
            <div style="display:flex; gap:8px;">
                <input type="search" id="coordDutySearch" placeholder="🔍 Search duty, staff, dept..." style="font-size:12px; padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; min-width:200px;">
                <select id="coordDeptFilter" style="font-size:12px; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px;">
                    <option value="">All Departments</option>
                    <option value="Protocol">Protocol</option>
                    <option value="Venue">Venue</option>
                    <option value="Security">Security</option>
                    <option value="Logistics">Logistics</option>
                    <option value="Communication">Communication</option>
                </select>
                <select id="coordStatusFilter" style="font-size:12px; padding:6px 10px; border:1px solid #cbd5e1; border-radius:6px;">
                    <option value="">All Statuses</option>
                    <option value="Pending">Pending</option>
                    <option value="Assigned">Assigned</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Completed">Completed</option>
                </select>
            </div>
        </div>

        <?php
        // Conflict detection map: staff_id + date
        $scheduleCounts = [];
        foreach ($l2AlignmentDuties as $chkD) {
            if (!empty($chkD['assigned_to']) && !empty($chkD['duty_date'])) {
                $key = $chkD['assigned_to'] . '_' . $chkD['duty_date'];
                $scheduleCounts[$key] = ($scheduleCounts[$key] ?? 0) + 1;
            }
        }
        ?>

        <div class="table-wrap" style="overflow-x:auto;">
            <table class="user-table" id="coordAlignmentTable" style="font-size:12px; min-width:1300px;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th>1. Event Name</th>
                        <th>2. Department</th>
                        <th>3. Department Lead</th>
                        <th>4. Duty / Task</th>
                        <th>5. Assigned Supervisor</th>
                        <th>6. Assigned Staff</th>
                        <th>7. Scheduled Date</th>
                        <th>8. Start Time</th>
                        <th>9. End Time</th>
                        <th>10. Priority</th>
                        <th>11. Duty Status</th>
                        <th>12. Dependencies</th>
                        <th>13. Approval Status</th>
                        <th style="text-align:right;">14. Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($l2AlignmentDuties)): ?>
                        <tr><td colspan="14" style="text-align:center; padding:24px; color:#64748b;">No duties recorded across departments.</td></tr>
                    <?php else: ?>
                        <?php foreach ($l2AlignmentDuties as $dd): ?>
                            <?php
                            $hasConflict = false;
                            if (!empty($dd['assigned_to']) && !empty($dd['duty_date'])) {
                                $k = $dd['assigned_to'] . '_' . $dd['duty_date'];
                                if (($scheduleCounts[$k] ?? 0) > 1) {
                                    $hasConflict = true;
                                }
                            }
                            // Department dependency indicator
                            $dep = 'Self-Contained';
                            if (stripos($dd['department'], 'Venue') !== false) $dep = 'Logistics (Equipment)';
                            elseif (stripos($dd['department'], 'Protocol') !== false) $dep = 'Security (VIP Cordon)';
                            elseif (stripos($dd['department'], 'Security') !== false) $dep = 'Venue (Perimeter Gate)';
                            elseif (stripos($dd['department'], 'Logistics') !== false) $dep = 'Venue (Loading Dock)';
                            elseif (stripos($dd['department'], 'Comm') !== false) $dep = 'Venue (AV / Stage Feed)';

                            // Approval status
                            $approvalStatus = in_array($dd['status'], ['Completed', 'Ongoing', 'Assigned', 'Accepted'], true) ? 'Approved' : 'Pending Review';
                            ?>
                            <tr class="coord-duty-row" data-dept="<?= e(strtolower($dd['department'] ?? '')) ?>" data-status="<?= e(strtolower($dd['status'] ?? '')) ?>" style="<?= $hasConflict ? 'background:#fff7ed;' : '' ?>">
                                <td>
                                    <strong><?= e($dd['event_name']) ?></strong>
                                    <small style="display:block; color:#64748b;">📍 <?= e($dd['location'] ?: 'Campus') ?></small>
                                </td>
                                <td>
                                    <span class="badge neutral" style="font-weight:700;"><?= e($dd['department'] ?? 'Protocol') ?></span>
                                </td>
                                <td>
                                    <strong><?= e($dd['lead_name'] ?: ($dd['department'] . ' Lead')) ?></strong>
                                </td>
                                <td>
                                    <strong style="color:#1e40af;"><?= e($dd['title']) ?></strong>
                                    <?php if ($hasConflict): ?>
                                        <span class="badge" style="background:#fee2e2; color:#991b1b; font-size:10px; display:block; margin-top:2px;">⚠️ Multiple Duties Conflict</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= e($dd['supervisor_name'] ?: ($dd['reports_to'] ?: 'Dept Supervisor')) ?>
                                </td>
                                <td>
                                    <?php if (!empty($dd['staff_name'])): ?>
                                        <strong><?= e($dd['staff_name']) ?></strong>
                                        <small style="display:block; color:#64748b;"><?= e($dd['staff_level'] ?: 'L5 Staff') ?></small>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fee2e2; color:#b91c1c; font-size:10px;">⚠️ Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= !empty($dd['duty_date']) ? date('d M Y', strtotime($dd['duty_date'])) : date('d M Y', strtotime($dd['created_at'])) ?>
                                </td>
                                <td>
                                    <?= !empty($dd['start_time']) ? date('h:i A', strtotime($dd['start_time'])) : ($dd['time_slot'] ?: '09:00 AM') ?>
                                </td>
                                <td>
                                    <?= !empty($dd['end_time']) ? date('h:i A', strtotime($dd['end_time'])) : '17:00 PM' ?>
                                </td>
                                <td>
                                    <?php if ($dd['priority'] === 'High'): ?>
                                        <span class="badge" style="background:#fee2e2; color:#991b1b; font-weight:700;">High</span>
                                    <?php elseif ($dd['priority'] === 'Medium'): ?>
                                        <span class="badge" style="background:#fef3c7; color:#92400e; font-weight:700;">Medium</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f1f5f9; color:#475569;">Low</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= strtolower($dd['status']) ?>" style="font-weight:700;">
                                        <?= e($dd['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <small style="color:#475569; font-weight:600;">🔗 <?= e($dep) ?></small>
                                </td>
                                <td>
                                    <span class="badge" style="background:<?= $approvalStatus === 'Approved' ? '#d1fae5' : '#fef3c7' ?>; color:<?= $approvalStatus === 'Approved' ? '#065f46' : '#92400e' ?>; font-weight:700;">
                                        <?= e($approvalStatus) ?>
                                    </span>
                                </td>
                                <td style="text-align:right; white-space:nowrap;">
                                    <span style="font-size:11px; color:#64748b;"><?= date('d M H:i', strtotime($dd['created_at'])) ?></span>
                                    <a href="<?= BASE_URL ?>/duties.php?edit=<?= $dd['id'] ?>#duty-form" class="btn btn-sm btn-light" style="font-size:10px; padding:2px 6px; margin-left:4px;">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const qInput = document.getElementById('coordDutySearch');
        const deptFilter = document.getElementById('coordDeptFilter');
        const statusFilter = document.getElementById('coordStatusFilter');
        const rows = document.querySelectorAll('.coord-duty-row');

        function filterCoordDuties() {
            const q = (qInput ? qInput.value.toLowerCase() : '');
            const dept = (deptFilter ? deptFilter.value.toLowerCase() : '');
            const status = (statusFilter ? statusFilter.value.toLowerCase() : '');

            rows.forEach(r => {
                const text = r.textContent.toLowerCase();
                const rDept = r.getAttribute('data-dept') || '';
                const rStatus = r.getAttribute('data-status') || '';

                const matchesQ = !q || text.includes(q);
                const matchesDept = !dept || rDept.includes(dept);
                const matchesStatus = !status || rStatus.includes(status);

                r.style.display = (matchesQ && matchesDept && matchesStatus) ? '' : 'none';
            });
        }

        if (qInput) qInput.addEventListener('input', filterCoordDuties);
        if (deptFilter) deptFilter.addEventListener('change', filterCoordDuties);
        if (statusFilter) statusFilter.addEventListener('change', filterCoordDuties);
    });
    </script>

</section>


<?php elseif ($active_level === 'L3'): ?>
<!-- =========================================================
     LEVEL 3: DEPARTMENT LEAD — DEPARTMENT MANAGEMENT
========================================================= -->
<section class="panel" style="margin-bottom:26px; border:2px solid #059669; border-radius:20px; overflow:hidden;">

    <div style="padding:22px 28px; background:linear-gradient(135deg, #065f46 0%, #059669 100%); color:#ffffff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <span style="background:#ffffff; color:#065f46; font-weight:900; font-size:11px; padding:4px 8px; border-radius:5px; font-family:monospace;">LEVEL 3</span>
                <span style="color:#a7f3d0; font-size:12px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase;">Department Command</span>
            </div>
            <h2 style="margin:0; font-size:22px; color:#ffffff;"><?= e($user_dept) ?> Department Lead Console</h2>
            <p style="margin:6px 0 0; color:#d1fae5; font-size:13px;">
                Direct responsibility for departmental operations &bull; Delegating to Supervisors (L4) and Ground Staff (L5).
            </p>
        </div>
        <div style="font-size:12px; background:rgba(255,255,255,0.15); padding:8px 14px; border-radius:8px;">
            Active Department: <strong style="color:#ffffff;"><?= e($user_dept) ?></strong>
        </div>
    </div>

    <!-- 5 Department Specialized Metrics -->
    <?php
    $deptType = current_department_lead_type();
    ?>
    <div style="padding:20px 28px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
        <?php if ($deptType === 'VENUE'): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px;">
                <div style="background:#ffffff; border:1px solid #bbf7d0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#166534; text-transform:uppercase;">Venue Status</span>
                    <strong style="display:block; font-size:18px; color:#0f172a; margin-top:4px;">Halls &amp; Stages Ready</strong>
                    <small style="color:#64748b;">Auditorium, AV &amp; Lighting</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Today's Duties</span>
                    <strong style="display:block; font-size:18px; color:#059669; margin-top:4px;"><?= count($deptDuties) ?> Assigned</strong>
                    <small style="color:#64748b;">Active venue tasks</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Venue Personnel</span>
                    <strong style="display:block; font-size:18px; color:#0f172a; margin-top:4px;"><?= count($deptSupervisors) ?> Sup / <?= count($deptStaff) ?> Staff</strong>
                    <small style="color:#64748b;">Under your direct command</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Seating &amp; Capacity</span>
                    <strong style="display:block; font-size:18px; color:#0284c7; margin-top:4px;">Main Auditorium</strong>
                    <small style="color:#64748b;">Seating execution aligned</small>
                </div>
            </div>
        <?php elseif ($deptType === 'SECURITY'): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px;">
                <div style="background:#ffffff; border:1px solid #fecaca; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#991b1b; text-transform:uppercase;">Security Status</span>
                    <strong style="display:block; font-size:18px; color:#991b1b; margin-top:4px;">Clear &amp; Secured</strong>
                    <small style="color:#64748b;">Perimeter &amp; Access Control</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Security Staff</span>
                    <strong style="display:block; font-size:18px; color:#0f172a; margin-top:4px;"><?= count($deptSupervisors) ?> Sup / <?= count($deptStaff) ?> Guards</strong>
                    <small style="color:#64748b;">Checkpoints &amp; Patrol</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Incidents</span>
                    <strong style="display:block; font-size:18px; color:#16a34a; margin-top:4px;">0 Critical</strong>
                    <small style="color:#64748b;">Field logs monitored</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Attendance / Access</span>
                    <strong style="display:block; font-size:18px; color:#0284c7; margin-top:4px;">Active Scanners</strong>
                    <small style="color:#64748b;">QR token verification</small>
                </div>
            </div>
        <?php elseif ($deptType === 'LOGISTICS'): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px;">
                <div style="background:#ffffff; border:1px solid #fde68a; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#92400e; text-transform:uppercase;">Transport Status</span>
                    <strong style="display:block; font-size:18px; color:#92400e; margin-top:4px;">Fleet Mobilized</strong>
                    <small style="color:#64748b;">Buses, VIP Sedans &amp; Vans</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Equipment Status</span>
                    <strong style="display:block; font-size:18px; color:#059669; margin-top:4px;">100% Dispatched</strong>
                    <small style="color:#64748b;">AV, Mic, Stands &amp; Screens</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Supplies &amp; Food</span>
                    <strong style="display:block; font-size:18px; color:#0f172a; margin-top:4px;">Boxes In Stock</strong>
                    <small style="color:#64748b;">Refreshments &amp; Kits</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Logistics Crew</span>
                    <strong style="display:block; font-size:18px; color:#0284c7; margin-top:4px;"><?= count($deptSupervisors) ?> Sup / <?= count($deptStaff) ?> Crew</strong>
                    <small style="color:#64748b;">Under your direct command</small>
                </div>
            </div>
        <?php elseif ($deptType === 'PROTOCOL'): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px;">
                <div style="background:#ffffff; border:1px solid #e9d5ff; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#6b21a8; text-transform:uppercase;">VIP Reception Status</span>
                    <strong style="display:block; font-size:18px; color:#6b21a8; margin-top:4px;">Red Carpet Ready</strong>
                    <small style="color:#64748b;">Escorts &amp; Dais Standing</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Guest &amp; VIP List</span>
                    <strong style="display:block; font-size:18px; color:#0f172a; margin-top:4px;"><?= (int)$stats['guests'] ?> Guests</strong>
                    <small style="color:#64748b;">Invited dignitaries</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">VIP Seating</span>
                    <strong style="display:block; font-size:18px; color:#059669; margin-top:4px;">Row A &amp; B Allocated</strong>
                    <small style="color:#64748b;">Protocol seats reserved</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Protocol Personnel</span>
                    <strong style="display:block; font-size:18px; color:#0284c7; margin-top:4px;"><?= count($deptSupervisors) ?> Sup / <?= count($deptStaff) ?> Escorts</strong>
                    <small style="color:#64748b;">Direct protocol squad</small>
                </div>
            </div>
        <?php elseif ($deptType === 'COMMUNICATION'): ?>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px;">
                <div style="background:#ffffff; border:1px solid #cffafe; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#155e75; text-transform:uppercase;">Media &amp; Press Desk</span>
                    <strong style="display:block; font-size:18px; color:#155e75; margin-top:4px;">Active &amp; Connected</strong>
                    <small style="color:#64748b;">Press releases &amp; Coverage</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Announcements</span>
                    <strong style="display:block; font-size:18px; color:#059669; margin-top:4px;">Broadcast Ready</strong>
                    <small style="color:#64748b;">Stage updates &amp; alerts</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Documentation</span>
                    <strong style="display:block; font-size:18px; color:#0f172a; margin-top:4px;">Photo &amp; Video Squad</strong>
                    <small style="color:#64748b;">Live event recording</small>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase;">Media Team</span>
                    <strong style="display:block; font-size:18px; color:#0284c7; margin-top:4px;"><?= count($deptSupervisors) ?> Sup / <?= count($deptStaff) ?> Media Crew</strong>
                    <small style="color:#64748b;">Under your direct command</small>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Delegation Form for Department -->
    <div style="padding:22px 28px; background:#f0fdf4; border-bottom:1px solid #bbf7d0;">
        <strong style="color:#065f46; font-size:14px; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:10px;">
            + Delegate Duty to Supervisor (L4) or Staff (L5)
        </strong>

        <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; align-items:end;">
            <input type="hidden" name="action" value="allocate_duty">
            <input type="hidden" name="department" value="<?= e($user_dept) ?>">

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#1e293b;">
                Event
                <select name="event_id" required style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 8px;">
                    <?php foreach ($eventsDropdown as $ed): ?>
                        <option value="<?= $ed['id'] ?>"><?= e($ed['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#1e293b;">
                Duty Title
                <input type="text" name="title" placeholder="e.g. <?= ($deptType === 'VENUE' ? 'Hall Check' : ($deptType === 'SECURITY' ? 'Gate Security Check' : ($deptType === 'LOGISTICS' ? 'AV Equipment Check' : ($deptType === 'COMMUNICATION' ? 'Media Press Brief' : 'VIP Red Carpet')))) ?>" required style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 10px;">
            </label>

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#1e293b;">
                Delegate Level
                <select name="level" required style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 8px;">
                    <option value="L4">L4 &mdash; Supervisor</option>
                    <option value="L5" selected>L5 &mdash; Staff / Volunteer</option>
                </select>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#1e293b;">
                Assign Person (<?= e($user_dept) ?> Only)
                <select name="assigned_to" style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 8px;">
                    <option value="">Select Department Assignee...</option>
                    <?php foreach ($deptAssignees as $au): ?>
                        <option value="<?= $au['id'] ?>"><?= e($au['name']) ?> (<?= e($au['role']) ?> · <?= e($au['level']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:700; color:#1e293b;">
                Location &amp; Time
                <input type="text" name="location" placeholder="e.g. Main Gate, 17:00 - 19:00" style="min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 10px;">
            </label>

            <button type="submit" class="btn btn-primary" style="min-height:42px; background:#059669; border-color:#059669; font-weight:700;">
                Assign Duty
            </button>
        </form>
    </div>

    <!-- Department Duties & Roster -->
    <div style="padding:22px 28px;">
        <div style="margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:15px; color:#0f172a;">Active Duties in <?= e($user_dept) ?> Department</strong>
            <a href="<?= BASE_URL ?>/duties.php?dept=<?= urlencode($user_dept) ?>" class="btn btn-light" style="font-size:11px;">Filter in Register</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Duty</th>
                        <th>Level</th>
                        <th>Location &amp; Time</th>
                        <th>Assignee</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($deptDuties, 0, 6) as $dd): ?>
                        <tr>
                            <td>
                                <strong><?= e($dd['title']) ?></strong>
                                <small style="display:block; color:#64748b;"><?= e($dd['description'] ?: 'Operational duty') ?></small>
                            </td>
                            <td><span style="font-family:monospace; font-weight:800; font-size:11px; padding:2px 6px; border-radius:4px; background:#e2e8f0;"><?= e($dd['level'] ?? 'L5') ?></span></td>
                            <td>
                                <strong><?= e($dd['location'] ?: 'Department Area') ?></strong>
                                <small style="display:block; color:#64748b;"><?= e($dd['time_slot'] ?: 'Full Event') ?></small>
                            </td>
                            <td><?= e($dd['assignee_name'] ?: 'Unassigned') ?></td>
                            <td><span class="badge <?= strtolower($dd['status']) ?>"><?= e($dd['status']) ?></span></td>
                            <td>
                                <a href="<?= BASE_URL ?>/duties.php?edit=<?= $dd['id'] ?>#duty-form" class="table-link">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</section>


<?php elseif ($active_level === 'L4'): ?>
<!-- =========================================================
     LEVEL 4: SUPERVISOR — FIELD SUPERVISION & GROUND CONTROL
========================================================= -->
<section class="panel" style="margin-bottom:26px; border:2px solid #d97706; border-radius:20px; overflow:hidden;">

    <div style="padding:22px 28px; background:linear-gradient(135deg, #92400e 0%, #d97706 100%); color:#ffffff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <span style="background:#ffffff; color:#92400e; font-weight:900; font-size:11px; padding:4px 8px; border-radius:5px; font-family:monospace;">LEVEL 4</span>
                <span style="color:#fef3c7; font-size:12px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase;">Field Supervision Console</span>
            </div>
            <h2 style="margin:0; font-size:22px; color:#ffffff;">Supervisor Ground Operations &amp; Inspection Desk</h2>
            <p style="margin:6px 0 0; color:#fef3c7; font-size:13px;">
                On-site setup check &bull; Equipment verification &bull; Patrol monitoring &bull; Rapid incident escalation.
            </p>
        </div>
        <div>
            <a class="btn btn-light" href="#incident-desk" style="font-weight:700; color:#92400e;">
                &bull; Escalate Field Incident
            </a>
        </div>
    </div>

    <!-- 4 Supervisor Ground Checkpoints -->
    <div style="padding:20px 28px; background:#fffbeb; border-bottom:1px solid #fde68a;">
        <strong style="color:#92400e; font-size:13px; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:12px;">
            Field Checkpoints Status (On-Ground Verification)
        </strong>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px;">
            <div style="background:#ffffff; border:1px solid #fde68a; border-radius:10px; padding:12px 14px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                    <span style="font-size:18px;">🛠</span>
                    <strong style="font-size:13px; color:#1e293b;">1. On-site Setup</strong>
                </div>
                <small style="color:#64748b; display:block; margin-bottom:6px;">Stage, seating layout, banners &amp; signs verified</small>
                <span class="badge completed" style="font-size:10px;">Ready</span>
            </div>

            <div style="background:#ffffff; border:1px solid #fde68a; border-radius:10px; padding:12px 14px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                    <span style="font-size:18px;">📻</span>
                    <strong style="font-size:13px; color:#1e293b;">2. Equipment Check</strong>
                </div>
                <small style="color:#64748b; display:block; margin-bottom:6px;">AV mics, walkie-talkies, QR scanners tested</small>
                <span class="badge completed" style="font-size:10px;">Operational</span>
            </div>

            <div style="background:#ffffff; border:1px solid #fde68a; border-radius:10px; padding:12px 14px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                    <span style="font-size:18px;">🛡</span>
                    <strong style="font-size:13px; color:#1e293b;">3. Patrol &amp; Gates</strong>
                </div>
                <small style="color:#64748b; display:block; margin-bottom:6px;">Access points manned, perimeter patrols active</small>
                <span class="badge ongoing" style="font-size:10px;">Active Patrol</span>
            </div>

            <div style="background:#ffffff; border:1px solid #fde68a; border-radius:10px; padding:12px 14px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                    <span style="font-size:18px;">👥</span>
                    <strong style="font-size:13px; color:#1e293b;">4. Ground Roster</strong>
                </div>
                <small style="color:#64748b; display:block; margin-bottom:6px;">Staff &amp; volunteers briefed at designated posts</small>
                <span class="badge completed" style="font-size:10px;">Briefed</span>
            </div>
        </div>
    </div>

    <!-- Active Tasks Under Supervision with 1-Click Verification -->
    <div style="padding:22px 28px;">
        <div style="margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:15px; color:#0f172a;">Active Ground Duties Under Field Supervision</strong>
            <span style="font-size:12px; color:#64748b;">Direct action: click to verify or sign-off tasks</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Duty</th>
                        <th>Location</th>
                        <th>Time Slot</th>
                        <th>Assigned Crew</th>
                        <th>Status</th>
                        <th>Quick Sign-Off</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($myDuties, 0, 6) as $md): ?>
                        <tr>
                            <td>
                                <strong><?= e($md['title']) ?></strong>
                                <small style="display:block; color:#64748b;"><?= e($md['event_name'] ?? 'BUP Event') ?></small>
                            </td>
                            <td>📍 <?= e($md['location'] ?: 'Main Entrance') ?></td>
                            <td>🕒 <?= e($md['time_slot'] ?: '17:30 - 19:00') ?></td>
                            <td><strong><?= e($md['assignee_name'] ?? 'Assigned Staff') ?></strong></td>
                            <td><span class="badge <?= strtolower($md['status']) ?>"><?= e($md['status']) ?></span></td>
                            <td>
                                <?php if ($md['status'] === 'Pending' || $md['status'] === 'Assigned'): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="quick_status">
                                        <input type="hidden" name="id" value="<?= $md['id'] ?>">
                                        <input type="hidden" name="status" value="Ongoing">
                                        <button type="submit" class="btn btn-light" style="font-size:10px; padding:3px 8px;">
                                            Start &rarr;
                                        </button>
                                    </form>
                                <?php elseif ($md['status'] === 'Ongoing'): ?>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="quick_status">
                                        <input type="hidden" name="id" value="<?= $md['id'] ?>">
                                        <input type="hidden" name="status" value="Completed">
                                        <button type="submit" class="btn btn-primary" style="font-size:10px; padding:3px 8px; background:#16a34a; border-color:#16a34a;">
                                            &check; Verify Done
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:11px; color:#16a34a; font-weight:700;">&check; Signed Off</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Supervisor Progress Report to Event Coordinator (L2) -->
    <div style="padding:20px 28px; background:#f0fdf4; border-top:1px solid #bbf7d0;">
        <strong style="color:#065f46; font-size:14px; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:8px;">
            📋 Transmit Ground Progress Report to Event Coordinator (L2)
        </strong>
        <p style="margin:0 0 10px; font-size:12px; color:#166534;">
            As Common Supervisor, summarize the execution status across department leads (Venue, Security, Logistics, Protocol, Media) for L2 review.
        </p>

        <form method="post" style="display:grid; grid-template-columns:1fr 130px 2fr auto; gap:10px; align-items:center;">
            <input type="hidden" name="action" value="send_supervisor_progress">

            <select name="progress_dept" style="min-height:42px; border-radius:8px; border:1px solid #86efac; padding:0 8px; font-weight:600;">
                <option value="All Departments">All Departments (Overall)</option>
                <option value="Venue Management">Venue Management</option>
                <option value="Safety & Security">Safety & Security</option>
                <option value="Logistics & Transport">Logistics & Transport</option>
                <option value="VIP & Military Protocol">VIP & Military Protocol</option>
                <option value="Communication & Media">Communication & Media</option>
            </select>

            <select name="completion_pct" style="min-height:42px; border-radius:8px; border:1px solid #86efac; padding:0 8px;">
                <option value="25">25% Progress</option>
                <option value="50">50% Progress</option>
                <option value="75">75% Progress</option>
                <option value="100">100% Ready</option>
            </select>

            <input type="text" name="progress_notes" placeholder="Ground status summary for Coordinator review..." required style="min-height:42px; border-radius:8px; border:1px solid #86efac; padding:0 10px;">

            <button type="submit" class="btn btn-primary" style="min-height:42px; background:#16a34a; border-color:#16a34a; font-weight:700; white-space:nowrap;">
                Transmit to L2 &rarr;
            </button>
        </form>
    </div>

    <!-- Instant Field Escalation Form -->
    <div id="incident-desk" style="padding:20px 28px; background:#fef2f2; border-top:1px solid #fecaca;">
        <strong style="color:#991b1b; font-size:14px; text-transform:uppercase; letter-spacing:1px; display:block; margin-bottom:8px;">
            🚨 Escalate Incident to Event Director (L1) &amp; Coordinator (L2)
        </strong>

        <form method="post" style="display:grid; grid-template-columns:1.2fr 1.5fr auto auto; gap:10px; align-items:center;">
            <input type="hidden" name="action" value="report_incident">

            <input type="text" name="location" placeholder="Location (e.g. North Gate / VIP Lobby)" required style="min-height:42px; border-radius:8px; border:1px solid #fca5a5; padding:0 10px;">
            <input type="text" name="incident" placeholder="Describe incident or assistance needed..." required style="min-height:42px; border-radius:8px; border:1px solid #fca5a5; padding:0 10px;">

            <select name="severity" style="min-height:42px; border-radius:8px; border:1px solid #fca5a5; padding:0 8px;">
                <option value="Medium">Medium Severity</option>
                <option value="High">High Severity</option>
                <option value="Critical">Critical Escalation</option>
            </select>

            <button type="submit" class="btn btn-primary" style="min-height:42px; background:#dc2626; border-color:#dc2626; font-weight:700; white-space:nowrap;">
                Send Escalation Alert
            </button>
        </form>
    </div>

</section>


<?php else: /* L5: Staff / Volunteer */ ?>
<!-- =========================================================
     LEVEL 5: STAFF / VOLUNTEER — DIRECT EXECUTION & DUTY PASS
========================================================= -->
<section class="panel" style="margin-bottom:26px; border:2px solid #145942; border-radius:20px; overflow:hidden;">

    <div style="padding:22px 28px; background:linear-gradient(135deg, #093425 0%, #11573f 100%); color:#ffffff; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <span style="background:#ffffff; color:#093425; font-weight:900; font-size:11px; padding:4px 8px; border-radius:5px; font-family:monospace;">LEVEL 5</span>
                <span style="color:#d1fae5; font-size:12px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase;">Personal Duty Pass</span>
            </div>
            <h2 style="margin:0; font-size:22px; color:#ffffff;">Staff &amp; Volunteer Task Execution Center</h2>
            <p style="margin:6px 0 0; color:#d1fae5; font-size:13px;">
                Direct task execution &bull; Ground checkpoints &bull; Real-time status reporting.
            </p>
        </div>
    </div>

    <!-- Assigned Duty Cards Roster -->
    <div style="padding:24px 28px; background:#f8fafc;">
        <div style="margin-bottom:14px; display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:15px; color:#0f172a; text-transform:uppercase; letter-spacing:1px;">
                My Assigned Duty Roster (<?= count($myDuties) ?>)
            </strong>
            <span style="font-size:12px; color:#64748b;">
                Tap "Start Duty" upon arrival, "Complete" once executed
            </span>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
            <?php foreach ($myDuties as $duty): ?>
                <div style="background:#ffffff; border:1.5px solid #e2e8f0; border-radius:14px; padding:18px; display:flex; flex-direction:column; justify-content:space-between; box-shadow:0 2px 6px rgba(0,0,0,0.02);">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px; margin-bottom:8px;">
                            <span class="badge neutral" style="font-size:10px;"><?= e($duty['department'] ?? 'General') ?></span>
                            <span class="badge <?= strtolower($duty['status']) ?>"><?= e($duty['status']) ?></span>
                        </div>

                        <strong style="font-size:16px; color:#0f172a; display:block; margin-bottom:4px; line-height:1.3;">
                            <?= e($duty['title']) ?>
                        </strong>

                        <small style="color:#64748b; display:block; margin-bottom:12px;">
                            Event: <?= e($duty['event_name'] ?? 'BUP Protocol Event') ?>
                        </small>

                        <div style="background:#f1f5f9; border-radius:8px; padding:10px 12px; font-size:12px; margin-bottom:14px;">
                            <div style="margin-bottom:4px; color:#1e293b;">
                                <strong>📍 Location:</strong> <?= e($duty['location'] ?: 'Main Entrance / Station') ?>
                            </div>
                            <div style="margin-bottom:4px; color:#1e293b;">
                                <strong>🕒 Time Slot:</strong> <?= e($duty['time_slot'] ?: 'Full Duty Window') ?>
                            </div>
                            <div style="color:#475569;">
                                <strong>👤 Reports To:</strong> <?= e($duty['reports_to'] ?: 'Supervisor') ?>
                            </div>
                            <?php if (!empty($duty['backup_person'])): ?>
                                <div style="color:#64748b; font-size:11px; margin-top:2px;">
                                    <em>Backup: <?= e($duty['backup_person']) ?></em>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 1-Click Status Controls -->
                    <div>
                        <?php if ($duty['status'] === 'Pending' || $duty['status'] === 'Assigned'): ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="quick_status">
                                <input type="hidden" name="id" value="<?= $duty['id'] ?>">
                                <input type="hidden" name="status" value="Ongoing">
                                <button type="submit" class="btn btn-primary btn-block" style="background:#2563eb; border-color:#2563eb; font-weight:700;">
                                    ▶ Start Duty (Mark Ongoing)
                                </button>
                            </form>
                        <?php elseif ($duty['status'] === 'Ongoing'): ?>
                            <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="quick_status">
                                <input type="hidden" name="id" value="<?= $duty['id'] ?>">
                                <input type="hidden" name="status" value="Completed">
                                <button type="submit" class="btn btn-primary btn-block" style="background:#16a34a; border-color:#16a34a; font-weight:700;">
                                    ✔ Mark Duty as Completed
                                </button>
                            </form>
                        <?php else: ?>
                            <div style="text-align:center; padding:8px; background:#dcfce7; color:#166534; font-weight:700; border-radius:8px; font-size:12px;">
                                &check; Duty Successfully Completed
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$myDuties): ?>
                <div style="grid-column:1 / -1; padding:30px; text-align:center; background:#ffffff; border-radius:12px; border:1px dashed #cbd5e1;">
                    <span style="font-size:24px; display:block; margin-bottom:8px;">📋</span>
                    <strong style="color:#0f172a; font-size:15px; display:block;">No Individual Duties Currently Assigned</strong>
                    <p style="color:#64748b; font-size:13px; margin:4px 0 14px;">Contact your Department Lead (L3) or Supervisor (L4) for duty assignment.</p>
                    <a href="<?= BASE_URL ?>/duties.php" class="btn btn-light">Browse Open Duty Register</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Support Request -->
    <div style="padding:18px 28px; background:#ffffff; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span style="font-size:12px; color:#475569;">
            Need help on the ground or experiencing any issue at your station?
        </span>

        <form method="post" style="display:inline-flex; gap:8px;">
            <input type="hidden" name="action" value="report_incident">
            <input type="hidden" name="location" value="Duty Station">
            <input type="hidden" name="severity" value="Medium">
            <input type="hidden" name="incident" value="Staff requesting field supervisor assistance at duty station.">
            <button type="submit" class="btn btn-light" style="font-size:11px; font-weight:700; color:#dc2626;">
                🚨 Request Supervisor Assistance
            </button>
        </form>
    </div>

</section>
<?php endif; ?>


<!-- =========================================================
     MAIN STATISTICS (GLOBAL OVERVIEW)
========================================================= -->

<div class="stat-grid">

    <div class="stat-card">
        <span>Total Events</span>
        <strong><?= $stats['events'] ?></strong>
        <em>All registered events</em>
    </div>

    <div class="stat-card">
        <span>Total Guests</span>
        <strong><?= $stats['guests'] ?></strong>
        <em>Guest & VIP records</em>
    </div>

    <div class="stat-card">
        <span>Open Duties</span>
        <strong><?= $stats['duties'] ?></strong>
        <em>Pending attention</em>
    </div>

    <div class="stat-card">
        <span>Present</span>
        <strong><?= $stats['attendance'] ?></strong>
        <em>Recorded attendance</em>
    </div>

</div>


<!-- =========================================================
     WORKFLOW STATUS
========================================================= -->

<div class="three-grid" style="margin-top:20px;">

    <section class="panel">

        <div class="panel-head">

            <div>
                <h2>Approval Workflow</h2>
                <p>Events requiring workflow action.</p>
            </div>

            <span class="badge pending">
                <?= $stats['pending_approvals'] ?>
            </span>

        </div>

        <div style="padding:0 20px 20px;">

            <div class="mini-row">

                <div>
                    <strong>Draft / Pending</strong>
                    <small>Events waiting for approval</small>
                </div>

                <a
                    class="table-link"
                    href="<?= BASE_URL ?>/approvals.php"
                >
                    Review
                </a>

            </div>

            <div class="workflow-steps">
                <span class="workflow-step active">Draft</span>
                <span class="workflow-arrow" aria-hidden="true">→</span>
                <span class="workflow-step">Pending</span>
                <span class="workflow-arrow" aria-hidden="true">→</span>
                <span class="workflow-step">Approved</span>
                <span class="workflow-arrow" aria-hidden="true">→</span>
                <span class="workflow-step">Confirmed</span>
            </div>

        </div>

    </section>


    <section class="panel">

        <div class="panel-head">

            <div>
                <h2>Protocol Checklist</h2>
                <p>Outstanding protocol items.</p>
            </div>

            <span class="badge warning">
                <?= $stats['pending_protocol'] ?>
            </span>

        </div>

        <div style="padding:0 20px 20px;">

            <div class="progress-wrap">

                <div class="progress-label">

                    <span>
                        Overall pending items
                    </span>

                    <strong>
                        <?= $stats['pending_protocol'] ?>
                    </strong>

                </div>

                <div class="progress">
                    <span style="
                        width:
                        <?= $stats['pending_protocol'] > 0 ? 35 : 100 ?>%;
                    "></span>
                </div>

            </div>

            <a
                class="btn btn-light btn-sm"
                style="margin-top:14px;"
                href="<?= BASE_URL ?>/protocol.php"
            >
                Open Protocol Checklist
            </a>

        </div>

    </section>


    <section class="panel">

        <div class="panel-head">

            <div>
                <h2>Conflict Detection</h2>
                <p>Schedule collision monitoring.</p>
            </div>

            <span class="badge <?= $stats['conflicts'] > 0 ? 'cancelled' : 'completed' ?>">
                <?= $stats['conflicts'] ?>
            </span>

        </div>

        <div style="padding:0 20px 20px;">

            <?php if ($stats['conflicts'] > 0): ?>

                <div class="alert error" style="margin-bottom:12px;">
                    Schedule conflicts detected.
                </div>

                <a
                    class="table-link"
                    href="#conflicts"
                >
                    Review conflicts ↓
                </a>

            <?php else: ?>

                <div class="empty">
                    No exact schedule conflicts detected.
                </div>

            <?php endif; ?>

        </div>

    </section>

</div>


<!-- =========================================================
     LIVE EVENT COMMAND CENTER
========================================================= -->

<?php if ($focusEvent): ?>

<section class="panel" style="margin-top:20px;">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                <?= $focusEvent['status'] === 'Ongoing'
                    ? 'LIVE EVENT'
                    : 'NEXT EVENT'
                ?>
            </span>

            <h2 style="margin-top:4px;">
                <?= e($focusEvent['name']) ?>
            </h2>

            <p>
                <?= e($focusEvent['event_type']) ?>
                ·
                <?= e($focusEvent['venue']) ?>
                ·
                <?= e($focusEvent['event_date']) ?>
                ·
                <?= e(substr($focusEvent['event_time'], 0, 5)) ?>
            </p>

        </div>

        <div style="display:flex;gap:8px;align-items:center;">

            <span class="badge <?= dashboard_status_class($focusEvent['status']) ?>">
                <?= e($focusEvent['status']) ?>
            </span>

            <a
                class="btn btn-light btn-sm"
                href="<?= BASE_URL ?>/event_view.php?id=<?= (int)$focusEvent['id'] ?>"
            >
                Open Event
            </a>

        </div>

    </div>


    <!-- Live Metrics -->

    <div class="stat-grid" style="padding:0 20px 20px;">

        <div class="stat-card">

            <span>Guests</span>

            <strong>
                <?= $focusGuestTotal ?>
            </strong>

            <em>
                Registered for event
            </em>

        </div>


        <div class="stat-card">

            <span>Attendance</span>

            <strong>
                <?= $focusAttendance['present'] ?>
            </strong>

            <em>
                <?= $focusAttendance['registered'] ?> registered
            </em>

        </div>


        <div class="stat-card">

            <span>Duties</span>

            <strong>
                <?= $dutyProgress ?>%
            </strong>

            <em>
                <?= $focusDutyCompleted ?>/<?= $focusDutyTotal ?> completed
            </em>

        </div>


        <div class="stat-card">

            <span>Protocol</span>

            <strong>
                <?= $protocolProgress ?>%
            </strong>

            <em>
                <?= $focusProtocolCompleted ?>/<?= $focusProtocolTotal ?> completed
            </em>

        </div>

    </div>


    <!-- Operational Progress -->

    <div style="padding:0 20px 20px;">

        <div class="progress-wrap">

            <div class="progress-label">

                <span>
                    Overall Operational Progress
                </span>

                <strong>
                    <?= $overallOperationalProgress ?>%
                </strong>

            </div>

            <div class="progress">
                <span style="
                    width:<?= $overallOperationalProgress ?>%;
                "></span>
            </div>

        </div>

    </div>

</section>

<?php endif; ?>


<!-- =========================================================
     EVENT TASK CHECKLIST + EVENT TIMELINE
========================================================= -->

<div class="content-grid" style="margin-top:20px;">

    <!-- EVENT TASK CHECKLIST -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>Event Task Checklist</h2>

                <p>
                    Operational tasks and protocol readiness.
                </p>

            </div>

            <?php if ($focusEvent): ?>

                <a
                    href="<?= BASE_URL ?>/duties.php"
                    class="table-link"
                >
                    Manage
                </a>

            <?php endif; ?>

        </div>


        <?php if (!$focusEvent): ?>

            <div class="empty">
                No active or upcoming event available.
            </div>

        <?php else: ?>

            <div style="padding:0 20px 20px;">

                <!-- Duty checklist -->

                <?php if ($focusDuties): ?>

                    <?php foreach ($focusDuties as $duty): ?>

                        <div class="check-row">

                            <span class="check
                                <?= $duty['status'] === 'Completed'
                                    ? 'done'
                                    : ''
                                ?>"
                            >
                                <?= $duty['status'] === 'Completed'
                                    ? '✓'
                                    : ''
                                ?>
                            </span>

                            <div style="flex:1;">

                                <strong>
                                    <?= e($duty['title']) ?>
                                </strong>

                                <small>

                                    <?= e($duty['status']) ?>

                                    <?php if (!empty($duty['due_at'])): ?>

                                        · Due:
                                        <?= e($duty['due_at']) ?>

                                    <?php endif; ?>

                                </small>

                            </div>

                            <span class="badge
                                <?= dashboard_status_class(
                                    $duty['status']
                                ) ?>"
                            >
                                <?= e($duty['status']) ?>
                            </span>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty">
                        No duties have been assigned to this event yet.
                    </div>

                <?php endif; ?>


                <!-- Protocol checklist -->

                <?php if ($focusProtocol): ?>

                    <div style="
                        margin-top:18px;
                        padding-top:18px;
                        border-top:1px solid #edf0f3;
                    ">

                        <strong
                            style="
                                display:block;
                                margin-bottom:8px;
                            "
                        >
                            Protocol Readiness
                        </strong>

                        <?php foreach ($focusProtocol as $item): ?>

                            <div class="check-row">

                                <span class="check
                                    <?= (int)$item['completed'] === 1
                                        ? 'done'
                                        : ''
                                    ?>"
                                >
                                    <?= (int)$item['completed'] === 1
                                        ? '✓'
                                        : ''
                                    ?>
                                </span>

                                <div style="flex:1;">

                                    <strong>
                                        <?= e($item['item']) ?>
                                    </strong>

                                    <?php if (!empty($item['instructions'])): ?>

                                        <small>
                                            <?= e($item['instructions']) ?>
                                        </small>

                                    <?php endif; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>


                <div style="margin-top:15px;">

                    <a
                        class="btn btn-light btn-sm"
                        href="<?= BASE_URL ?>/duties.php"
                    >
                        Manage Duties
                    </a>

                    <a
                        class="btn btn-light btn-sm"
                        href="<?= BASE_URL ?>/protocol.php"
                    >
                        Manage Protocol
                    </a>

                </div>

            </div>

        <?php endif; ?>

    </section>


    <!-- EVENT TIMELINE -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>Event Timeline</h2>

                <p>
                    Key milestones for the active event.
                </p>

            </div>

        </div>


        <?php if (!$timeline): ?>

            <div class="empty">
                No timeline available.
            </div>

        <?php else: ?>

            <div class="timeline">

                <?php foreach ($timeline as $item): ?>

                    <div class="timeline-item">

                        <span class="
                            timeline-dot
                            <?= e($item['class']) ?>
                        "></span>

                        <div class="timeline-content">

                            <strong>
                                <?= e($item['title']) ?>
                            </strong>

                            <p>
                                <?= e($item['description']) ?>
                            </p>

                            <small>
                                <?= e($item['time']) ?>
                            </small>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </section>

</div>


<!-- =========================================================
     CONFLICT DETECTION
========================================================= -->

<section
    class="panel"
    id="conflicts"
    style="margin-top:20px;"
>

    <div class="panel-head">

        <div>

            <h2>Schedule Conflict Detection</h2>

            <p>
                Events sharing the same date, venue and time.
            </p>

        </div>

        <?php if ($stats['conflicts'] === 0): ?>

            <span class="badge completed">
                Clear
            </span>

        <?php else: ?>

            <span class="badge cancelled">
                <?= $stats['conflicts'] ?> Conflict(s)
            </span>

        <?php endif; ?>

    </div>


    <div class="table-wrap">

        <table>

            <thead>

                <tr>

                    <th>Event 1</th>
                    <th>Event 2</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Venue</th>
                    <th>Action</th>

                </tr>

            </thead>

            <tbody>

                <?php if (!$conflicts): ?>

                    <tr>

                        <td
                            colspan="6"
                            class="empty"
                        >
                            No exact schedule conflicts detected.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($conflicts as $conflict): ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= e($conflict['event_one']) ?>
                                </strong>

                            </td>

                            <td>

                                <strong>
                                    <?= e($conflict['event_two']) ?>
                                </strong>

                            </td>

                            <td>
                                <?= e($conflict['event_date']) ?>
                            </td>

                            <td>
                                <?= e(
                                    substr(
                                        $conflict['event_time'],
                                        0,
                                        5
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= e($conflict['venue']) ?>
                            </td>

                            <td>

                                <a
                                    class="table-link"
                                    href="<?= BASE_URL ?>/event_view.php?id=<?= (int)$conflict['event_one_id'] ?>"
                                >
                                    View
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<!-- =========================================================
     UPCOMING EVENTS + DUTY DEADLINES
========================================================= -->

<div class="content-grid" style="margin-top:20px;">

    <!-- Upcoming Events -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>Upcoming Events</h2>

                <p>
                    Next scheduled activities.
                </p>

            </div>

            <a href="<?= BASE_URL ?>/events.php">
                View all
            </a>

        </div>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>Event</th>
                        <th>Date</th>
                        <th>Venue</th>
                        <th>Status</th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (!$upcoming): ?>

                        <tr>

                            <td
                                colspan="4"
                                class="empty"
                            >
                                No upcoming events.
                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($upcoming as $event): ?>

                            <tr>

                                <td>

                                    <a
                                        class="table-link"
                                        href="<?= BASE_URL ?>/event_view.php?id=<?= (int)$event['id'] ?>"
                                    >
                                        <?= e($event['name']) ?>
                                    </a>

                                    <small>
                                        <?= e($event['event_type']) ?>
                                    </small>

                                </td>

                                <td>

                                    <?= e($event['event_date']) ?>

                                    <br>

                                    <small>
                                        <?= e(
                                            substr(
                                                $event['event_time'],
                                                0,
                                                5
                                            )
                                        ) ?>
                                    </small>

                                </td>

                                <td>
                                    <?= e($event['venue']) ?>
                                </td>

                                <td>

                                    <span class="badge
                                        <?= dashboard_status_class(
                                            $event['status']
                                        ) ?>"
                                    >
                                        <?= e($event['status']) ?>
                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- Duty Deadlines -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>Duty Deadlines</h2>

                <p>
                    Outstanding operational tasks.
                </p>

            </div>

            <a href="<?= BASE_URL ?>/duties.php">
                View all
            </a>

        </div>


        <div style="padding:0 20px 20px;">

            <?php if (!$deadlineDuties): ?>

                <div class="empty">
                    No pending duty deadlines.
                </div>

            <?php else: ?>

                <?php foreach ($deadlineDuties as $duty): ?>

                    <div class="mini-row">

                        <div>

                            <strong>
                                <?= e($duty['title']) ?>
                            </strong>

                            <small>

                                <?= e(
                                    $duty['event_name']
                                    ?? 'Event'
                                ) ?>

                                <?php if (!empty($duty['due_at'])): ?>

                                    ·
                                    <?= e($duty['due_at']) ?>

                                <?php endif; ?>

                            </small>

                        </div>

                        <span class="badge
                            <?= dashboard_status_class(
                                $duty['status']
                            ) ?>"
                        >
                            <?= e($duty['status']) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>

</div>


<!-- =========================================================
     LIVE ATTENDANCE + GUEST OVERVIEW
========================================================= -->

<?php if ($focusEvent): ?>

<div class="content-grid" style="margin-top:20px;">

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>Live Attendance</h2>

                <p>
                    <?= e($focusEvent['name']) ?>
                </p>

            </div>

            <a
                href="<?= BASE_URL ?>/attendance.php"
                class="table-link"
            >
                Open Attendance
            </a>

        </div>


        <div style="padding:0 20px 20px;">

            <div class="mini-row">

                <div>

                    <strong>
                        <?= $focusAttendance['present'] ?>
                        Present
                    </strong>

                    <small>
                        Guests checked in
                    </small>

                </div>

                <span class="badge present">
                    PRESENT
                </span>

            </div>


            <div class="mini-row">

                <div>

                    <strong>
                        <?= $focusAttendance['absent'] ?>
                        Absent
                    </strong>

                    <small>
                        Registered but not present
                    </small>

                </div>

                <span class="badge absent">
                    ABSENT
                </span>

            </div>


            <div class="progress-wrap">

                <?php
                $attendancePercent = dashboard_percent(
                    $focusAttendance['present'],
                    $focusAttendance['registered']
                );
                ?>

                <div class="progress-label">

                    <span>
                        Check-in Rate
                    </span>

                    <strong>
                        <?= $attendancePercent ?>%
                    </strong>

                </div>

                <div class="progress">

                    <span style="
                        width:<?= $attendancePercent ?>%;
                    "></span>

                </div>

            </div>

        </div>

    </section>


    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>Guest & VIP Overview</h2>

                <p>
                    Priority guest records for active event.
                </p>

            </div>

            <a
                href="<?= BASE_URL ?>/guests.php"
                class="table-link"
            >
                Manage
            </a>

        </div>


        <div style="padding:0 20px 20px;">

            <?php if (!$focusGuests): ?>

                <div class="empty">
                    No guests registered for this event.
                </div>

            <?php else: ?>

                <?php foreach ($focusGuests as $guest): ?>

                    <div class="mini-row">

                        <div>

                            <strong>
                                <?= e($guest['name']) ?>
                            </strong>

                            <small>

                                <?= e(
                                    $guest['designation']
                                    ?? ''
                                ) ?>

                                <?php if (!empty($guest['organization'])): ?>

                                    ·
                                    <?= e(
                                        $guest['organization']
                                    ) ?>

                                <?php endif; ?>

                            </small>

                        </div>

                        <span class="priority">
                            <?= e(
                                $guest['priority_level']
                            ) ?>
                        </span>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </section>

</div>

<?php endif; ?>


<!-- =========================================================
     RECENT AUDIT ACTIVITY
========================================================= -->

<section class="panel" style="margin-top:20px;">

    <div class="panel-head">

        <div>

            <h2>Recent Activity</h2>

            <p>
                Latest system changes and operational actions.
            </p>

        </div>

        <a href="<?= BASE_URL ?>/reports.php">
            Audit
        </a>

    </div>


    <div class="activity-list">

        <?php foreach ($recent as $row): ?>

            <div class="activity">

                <span class="activity-dot"></span>

                <div>

                    <strong>
                        <?= e($row['action']) ?>
                    </strong>

                    <p>
                        <?= e($row['description']) ?>
                    </p>

                    <small>

                        <?= e($row['created_at']) ?>

                        ·

                        <?= e(
                            $row['name']
                            ?? 'System'
                        ) ?>

                    </small>

                </div>

            </div>

        <?php endforeach; ?>


        <?php if (!$recent): ?>

            <div class="empty">
                No audit activity yet.
            </div>

        <?php endif; ?>

    </div>

</section>


<!-- =========================================================
     OPERATIONAL SUMMARY
========================================================= -->

<div class="three-grid" style="margin-top:20px;">

    <section class="panel">

        <div class="panel-head">

            <div>
                <h2>Event Operations</h2>
                <p>Manage scheduled activities.</p>
            </div>

        </div>


        <div class="mini-row">

            <div>

                <strong>
                    Event Management
                </strong>

                <small>
                    Create, update and monitor events
                </small>

            </div>

            <a
                class="table-link"
                href="<?= BASE_URL ?>/events.php"
            >
                Open
            </a>

        </div>


        <div class="mini-row">

            <div>

                <strong>
                    Event Calendar
                </strong>

                <small>
                    Review scheduled events
                </small>

            </div>

            <a
                class="table-link"
                href="<?= BASE_URL ?>/calendar.php"
            >
                View
            </a>

        </div>

    </section>


    <section class="panel">

        <div class="panel-head">

            <div>
                <h2>Guest & Attendance</h2>
                <p>Monitor guest operations.</p>
            </div>

        </div>


        <div class="mini-row">

            <div>

                <strong>
                    <?= $stats['guests'] ?>
                    Guest Records
                </strong>

                <small>
                    Guest & VIP management
                </small>

            </div>

            <a
                class="table-link"
                href="<?= BASE_URL ?>/guests.php"
            >
                Open
            </a>

        </div>


        <div class="mini-row">

            <div>

                <strong>
                    <?= $stats['attendance'] ?>
                    Present
                </strong>

                <small>
                    Recorded check-ins
                </small>

            </div>

            <a
                class="table-link"
                href="<?= BASE_URL ?>/attendance.php"
            >
                View
            </a>

        </div>

    </section>


    <section class="panel">

        <div class="panel-head">

            <div>
                <h2>Duty Operations</h2>
                <p>Personnel assignments.</p>
            </div>

        </div>


        <div class="mini-row">

            <div>

                <strong>
                    <?= $stats['duties'] ?>
                    Open Duties
                </strong>

                <small>
                    Duties requiring attention
                </small>

            </div>

            <a
                class="table-link"
                href="<?= BASE_URL ?>/duties.php"
            >
                Open
            </a>

        </div>


        <div class="mini-row">

            <div>

                <strong>
                    Duty Management
                </strong>

                <small>
                    Assignment and status tracking
                </small>

            </div>

            <a
                class="table-link"
                href="<?= BASE_URL ?>/duties.php"
            >
                Manage
            </a>

        </div>

    </section>

</div>


<!-- =========================================================
     QUICK ACCESS
========================================================= -->

<section
    class="panel"
    style="margin-top:20px;"
>

    <div class="panel-head">

        <div>

            <h2>Quick Access</h2>

            <p>
                Frequently used system modules.
            </p>

        </div>

    </div>


    <div class="dashboard-quick-grid">

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/events.php"
        >
            Events
        </a>

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/guests.php"
        >
            Guests & VIP
        </a>

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/duties.php"
        >
            Duties
        </a>

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/protocol.php"
        >
            Protocol
        </a>

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/attendance.php"
        >
            Attendance
        </a>

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/approvals.php"
        >
            Approvals
        </a>

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/seating.php"
        >
            Seating
        </a>

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/reports.php"
        >
            Reports
        </a>

    </div>

</section>


<!-- =========================================================
     DASHBOARD-ONLY CSS
========================================================= -->

<style>

/* Dashboard workflow card layout fix */
.three-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    align-items: stretch;
}

.three-grid > .panel {
    min-width: 0;
    height: 100%;
    overflow: hidden;
    border-radius: 22px;
}

/* Prevent content from forcing cards wider */
.three-grid .panel-head > div {
    min-width: 0;
}

.three-grid .panel-head h2 {
    overflow-wrap: anywhere;
}

.three-grid .panel > div {
    min-width: 0;
}

.three-grid .progress-wrap {
    width: 100%;
    min-width: 0;
}

.three-grid .progress {
    width: 100%;
    overflow: hidden;
    border-radius: 999px;
}

.timeline {
    padding:10px 20px 20px;
    position:relative;
}

.timeline:before {
    content:"";
    position:absolute;
    left:29px;
    top:20px;
    bottom:20px;
    width:2px;
    background:#e9edf1;
}

.timeline-item {
    position:relative;
    display:flex;
    gap:14px;
    padding:10px 0;
}

.timeline-dot {
    width:18px;
    height:18px;
    min-width:18px;
    border-radius:50%;
    background:#dce2e8;
    border:4px solid #f5f7f9;
    z-index:2;
}

.timeline-dot.done {
    background:#218739;
}

.timeline-dot.pending {
    background:#d99a00;
}

.timeline-dot.upcoming {
    background:#2866c7;
}

.timeline-content {
    padding-bottom:5px;
}

.timeline-content strong {
    display:block;
    color:var(--navy);
    font-size:13px;
}

.timeline-content p {
    margin:3px 0;
    color:#6e7984;
    font-size:11px;
}

.timeline-content small {
    color:#9aa4ae;
    font-size:10px;
}

.dashboard-quick-grid {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:10px;
    padding:20px;
}

@media (max-width:900px) {

    .dashboard-quick-grid {
        grid-template-columns:repeat(2,1fr);
    }

}

@media (max-width:600px) {

    .dashboard-quick-grid {
        grid-template-columns:1fr;
    }

}

/* Tablet */
@media (max-width: 1000px) {
    .three-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* Mobile */
@media (max-width: 650px) {
    .three-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 16px;
    }

    .three-grid > .panel {
        height: auto;
    }

}



/* Fix Approval Workflow stages */
.three-grid > .panel:first-child {
    min-width: 0;
}

.three-grid > .panel:first-child > div:last-child {
    min-width: 0;
}

/* Approval Workflow Card */
.workflow-steps {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 18px minmax(0, 1fr) 18px minmax(0, 1fr) 18px minmax(0, 1fr);
    align-items: center;
    gap: 4px;
    width: 100%;
    min-width: 0;
    margin-top: 15px;
}

.workflow-step {
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    min-height: 42px;
    padding: 8px 3px;
    border-radius: 999px;
    background: #f0f3f6;
    color: #536273;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.2;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
}

.workflow-step.active {
    background: var(--navy);
    color: #fff;
}

.workflow-arrow {
    display: block;
    text-align: center;
    color: var(--navy);
    font-size: 16px;
    line-height: 1;
}

/* Tablet and smaller screens */
@media (max-width: 1100px) {
    .workflow-steps {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 6px;
    }

    .workflow-arrow {
        display: none;
    }

    .workflow-step {
        min-height: 40px;
        padding: 7px 2px;
        font-size: 10px;
        white-space: normal;
        overflow-wrap: anywhere;
    }
}




/* Final override: Approval Workflow */
.three-grid > .panel:first-child {
    min-width: 0 !important;
    overflow: hidden !important;
}

.three-grid > .panel:first-child > div:last-child {
    padding-left: 20px !important;
    padding-right: 20px !important;
    min-width: 0 !important;
}

.workflow-steps {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) 12px minmax(0, 1fr) 12px minmax(0, 1fr) 12px minmax(0, 1fr) !important;
    gap: 3px !important;
    align-items: center !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

.workflow-steps .workflow-step {
    width: 100% !important;
    min-width: 0 !important;
    min-height: 38px !important;
    padding: 6px 2px !important;
    border-radius: 10px !important;
    box-sizing: border-box !important;
    font-size: 10px !important;
    line-height: 1.15 !important;
    text-align: center !important;
    white-space: normal !important;
    overflow-wrap: anywhere !important;
    word-break: normal !important;
}

.workflow-steps .workflow-arrow {
    width: 12px !important;
    font-size: 13px !important;
    text-align: center !important;
}

@media (max-width: 1100px) {
    .workflow-steps {
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 5px !important;
    }

    .workflow-steps .workflow-arrow {
        display: none !important;
    }
}


</style>


<?php require __DIR__ . '/includes/footer.php'; ?>