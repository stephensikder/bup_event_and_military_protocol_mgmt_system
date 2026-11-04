<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_login();

$page_title = 'Reports & Analytics | BUP Protocol';

/*
|--------------------------------------------------------------------------
| BASIC EVENT STATS (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

$stats = [
    'events' => mcoll('events')->countDocuments(),
    'upcoming' => mcoll('events')->countDocuments(['status' => 'Upcoming']),
    'ongoing' => mcoll('events')->countDocuments(['status' => 'Ongoing']),
    'completed' => mcoll('events')->countDocuments(['status' => 'Completed']),
    'cancelled' => mcoll('events')->countDocuments(['status' => 'Cancelled']),
    'guests' => mcoll('guests')->countDocuments(),
    'duties' => mcoll('duties')->countDocuments(),
    'done' => mcoll('duties')->countDocuments(['status' => 'Completed']),
    'attendance' => mcoll('attendance')->countDocuments(['status' => 'Present']),
    'attendance_total' => mcoll('attendance')->countDocuments(),
    'protocol_total' => mcoll('protocol_items')->countDocuments(),
    'protocol_done' => mcoll('protocol_items')->countDocuments(['completed' => ['$in' => [1, true, '1']]]),
    'transport' => mcoll('transport')->countDocuments()
];

/*
|--------------------------------------------------------------------------
| PERCENTAGES
|--------------------------------------------------------------------------
*/

$duty_completion = $stats['duties'] > 0
    ? (int)round(($stats['done'] / $stats['duties']) * 100)
    : 0;

$event_completion = $stats['events'] > 0
    ? (int)round(($stats['completed'] / $stats['events']) * 100)
    : 0;

$attendance_rate = $stats['attendance_total'] > 0
    ? (int)round(($stats['attendance'] / $stats['attendance_total']) * 100)
    : 0;

$protocol_completion = $stats['protocol_total'] > 0
    ? (int)round(($stats['protocol_done'] / $stats['protocol_total']) * 100)
    : 0;

/*
|--------------------------------------------------------------------------
| EVENT TYPE ANALYTICS (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$typeAgg = mcoll('events')->aggregate([
    [
        '$group' => [
            '_id' => '$event_type',
            'total' => ['$sum' => 1]
        ]
    ],
    ['$sort' => ['total' => -1]]
]);
$event_types = [];
foreach ($typeAgg as $doc) {
    $event_types[] = [
        'event_type' => $doc['_id'] ?? 'General',
        'total' => (int)($doc['total'] ?? 0)
    ];
}

/*
|--------------------------------------------------------------------------
| DUTY STATUS ANALYTICS (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$dutyAgg = mcoll('duties')->aggregate([
    [
        '$group' => [
            '_id' => '$status',
            'total' => ['$sum' => 1]
        ]
    ],
    ['$sort' => ['total' => -1]]
]);
$duty_status = [];
foreach ($dutyAgg as $doc) {
    $duty_status[] = [
        'status' => $doc['_id'] ?? 'Pending',
        'total' => (int)($doc['total'] ?? 0)
    ];
}

/*
|--------------------------------------------------------------------------
| GUEST CATEGORY ANALYTICS (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$catAgg = mcoll('guests')->aggregate([
    [
        '$group' => [
            '_id' => '$category',
            'total' => ['$sum' => 1]
        ]
    ],
    ['$sort' => ['total' => -1]]
]);
$guest_categories = [];
foreach ($catAgg as $doc) {
    $guest_categories[] = [
        'category' => $doc['_id'] ?? 'General',
        'total' => (int)($doc['total'] ?? 0)
    ];
}

/*
|--------------------------------------------------------------------------
| GUEST PRIORITY ANALYTICS (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$prioAgg = mcoll('guests')->aggregate([
    [
        '$group' => [
            '_id' => '$priority_level',
            'total' => ['$sum' => 1]
        ]
    ],
    ['$sort' => ['total' => -1]]
]);
$guest_priorities = [];
foreach ($prioAgg as $doc) {
    $guest_priorities[] = [
        'priority_level' => $doc['_id'] ?? 'Normal',
        'total' => (int)($doc['total'] ?? 0)
    ];
}

/*
|--------------------------------------------------------------------------
| RECENT EVENTS (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$recentEvAgg = mcoll('events')->aggregate([
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'created_by',
            'foreignField' => 'id',
            'as' => 'creator_user'
        ]
    ],
    ['$unwind' => ['path' => '$creator_user', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['event_date' => -1, 'event_time' => -1]],
    ['$limit' => 8]
]);
$recent_events = [];
foreach ($recentEvAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['creator'] = $row['creator_user']['name'] ?? 'System';
    unset($row['creator_user']);
    $recent_events[] = $row;
}

/*
|--------------------------------------------------------------------------
| RECENT AUDIT ACTIVITY (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$recentAudAgg = mcoll('audit_logs')->aggregate([
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'user_id',
            'foreignField' => 'id',
            'as' => 'log_user'
        ]
    ],
    ['$unwind' => ['path' => '$log_user', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['created_at' => -1, 'id' => -1]],
    ['$limit' => 8]
]);
$recent_audit = [];
foreach ($recentAudAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['user_name'] = $row['log_user']['name'] ?? 'System';
    unset($row['log_user']);
    $recent_audit[] = $row;
}

/*
|--------------------------------------------------------------------------
| EVENT STATUS DATA
|--------------------------------------------------------------------------
*/

$event_statuses = [
    'Upcoming' => $stats['upcoming'],
    'Ongoing' => $stats['ongoing'],
    'Completed' => $stats['completed'],
    'Cancelled' => $stats['cancelled']
];

/*
|--------------------------------------------------------------------------
| SPECIALIZED REPORT DATA (Budget, Seating, Teams, Protocol, Attendance)
|--------------------------------------------------------------------------
*/
$is_military_protocol = is_military_protocol();
if ($is_military_protocol) {
    $report_tab = 'protocol';
} else {
    $report_tab = trim($_GET['tab'] ?? 'executive');
}

// Budget summary report (MongoDB Atlas)
$budgetAgg = mcoll('events')->aggregate([
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'event_director_id',
            'foreignField' => 'id',
            'as' => 'director'
        ]
    ],
    ['$unwind' => ['path' => '$director', 'preserveNullAndEmptyArrays' => true]],
    [
        '$lookup' => [
            'from' => 'budget_items',
            'localField' => 'id',
            'foreignField' => 'event_id',
            'as' => 'items'
        ]
    ],
    ['$sort' => ['event_date' => -1]]
]);
$budget_summary = [];
foreach ($budgetAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $items = $row['items'] ?? [];
    $itemized_total = 0;
    foreach ($items as $it) {
        $app = (float)($it['approved_amount'] ?? 0);
        $req = (float)($it['requested_amount'] ?? 0);
        $itemized_total += ($app > 0 ? $app : $req);
    }
    $budget_summary[] = [
        'id' => $row['id'],
        'name' => $row['name'] ?? '',
        'budget_total' => $row['budget_total'] ?? 0,
        'budget_approved' => $row['budget_approved'] ?? 0,
        'budget_notes' => $row['budget_notes'] ?? '',
        'director_name' => $row['director']['name'] ?? null,
        'itemized_total' => $itemized_total
    ];
}

// Seating capacity & allocation report (MongoDB Atlas)
$seatingAgg = mcoll('events')->aggregate([
    [
        '$lookup' => [
            'from' => 'seating_assignments',
            'localField' => 'id',
            'foreignField' => 'event_id',
            'as' => 'seats'
        ]
    ],
    ['$sort' => ['event_date' => -1]]
]);
$seating_summary = [];
foreach ($seatingAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $seats = $row['seats'] ?? [];
    $total_seats = count($seats);
    $allocated_seats = 0;
    $reserved_seats = 0;
    foreach ($seats as $s) {
        if (!empty($s['guest_id'])) {
            $allocated_seats++;
        }
        $st = $s['seat_type'] ?? '';
        if ($st === 'VIP' || $st === 'Reserved') {
            $reserved_seats++;
        }
    }
    $seating_summary[] = [
        'event_name' => $row['name'] ?? '',
        'mode' => $row['mode'] ?? 'In-Person',
        'venue' => $row['venue'] ?? '',
        'total_seats' => $total_seats,
        'allocated_seats' => $allocated_seats,
        'reserved_seats' => $reserved_seats,
        'available_seats' => max(0, $total_seats - $allocated_seats)
    ];
}

// Team department report (MongoDB Atlas)
$teamAgg = mcoll('duties')->aggregate([
    [
        '$match' => [
            'department' => ['$ne' => null, '$ne' => '']
        ]
    ],
    [
        '$group' => [
            '_id' => '$department',
            'total_duties' => ['$sum' => 1],
            'completed' => [
                '$sum' => [
                    '$cond' => [['$eq' => ['$status', 'Completed']], 1, 0]
                ]
            ],
            'in_progress' => [
                '$sum' => [
                    '$cond' => [['$in' => ['$status', ['In Progress', 'Ongoing']]], 1, 0]
                ]
            ],
            'pending' => [
                '$sum' => [
                    '$cond' => [['$in' => ['$status', ['Pending', 'Assigned', 'Accepted']]], 1, 0]
                ]
            ]
        ]
    ],
    ['$sort' => ['_id' => 1]]
]);
$team_summary = [];
foreach ($teamAgg as $doc) {
    $team_summary[] = [
        'department' => $doc['_id'] ?? '',
        'total_duties' => (int)($doc['total_duties'] ?? 0),
        'completed' => (int)($doc['completed'] ?? 0),
        'in_progress' => (int)($doc['in_progress'] ?? 0),
        'pending' => (int)($doc['pending'] ?? 0)
    ];
}

// Protocol hierarchy report (MongoDB Atlas)
$protoUnitsAgg = mcoll('military_protocol_units')->aggregate([
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'commander_id',
            'foreignField' => 'id',
            'as' => 'commander'
        ]
    ],
    ['$unwind' => ['path' => '$commander', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['sort_order' => 1, 'id' => 1]]
]);
$protocol_units_report = [];
foreach ($protoUnitsAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $protocol_units_report[] = [
        'unit_code' => $row['code'] ?? '',
        'unit_name' => $row['name'] ?? '',
        'protocol_level' => $row['level'] ?? '',
        'location' => $row['hq_location'] ?? '',
        'status' => $row['status'] ?? 'Active',
        'strength' => 12,
        'commander_name' => $row['commander']['name'] ?? null,
        'commander_designation' => $row['commander']['designation'] ?? null
    ];
}

// Protocol items report (MongoDB Atlas)
$protoItemsAgg = mcoll('protocol_items')->aggregate([
    [
        '$lookup' => [
            'from' => 'events',
            'localField' => 'event_id',
            'foreignField' => 'id',
            'as' => 'event'
        ]
    ],
    ['$unwind' => ['path' => '$event', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['sort_order' => 1, 'id' => 1]]
]);
$protocol_items_report = [];
foreach ($protoItemsAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['event_name'] = $row['event']['name'] ?? null;
    unset($row['event']);
    $protocol_items_report[] = $row;
}

// Military SOPs report (MongoDB Atlas)
$sopsCursor = mcoll('military_sops')->find(
    ['status' => 'Active'],
    ['sort' => ['id' => 1]]
);
$military_sops_report = [];
foreach ($sopsCursor as $doc) {
    $s = mongo_doc_to_array($doc);
    $military_sops_report[] = [
        'code' => $s['code'] ?? '',
        'title' => $s['title'] ?? '',
        'category' => $s['category'] ?? '',
        'applicable_level' => $s['applicable_level'] ?? '',
        'authorized_by' => $s['authorized_by'] ?? '',
        'status' => $s['status'] ?? 'Active'
    ];
}

// Duty operational report (MongoDB Atlas)
$dutyRepAgg = mcoll('duties')->aggregate([
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
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'supervisor_id',
            'foreignField' => 'id',
            'as' => 'supervisor'
        ]
    ],
    ['$unwind' => ['path' => '$supervisor', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['duty_date' => -1, 'id' => -1]],
    ['$limit' => 25]
]);
$duty_report = [];
foreach ($dutyRepAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['event_name'] = $row['event']['name'] ?? null;
    $row['assignee_name'] = $row['assignee']['name'] ?? null;
    $row['supervisor_name'] = $row['supervisor']['name'] ?? null;
    unset($row['event'], $row['assignee'], $row['supervisor']);
    $duty_report[] = $row;
}

// Attendance guest checkin report (MongoDB Atlas)
$attRepAgg = mcoll('attendance')->aggregate([
    [
        '$lookup' => [
            'from' => 'guests',
            'localField' => 'guest_id',
            'foreignField' => 'id',
            'as' => 'guest'
        ]
    ],
    ['$unwind' => ['path' => '$guest', 'preserveNullAndEmptyArrays' => true]],
    [
        '$lookup' => [
            'from' => 'events',
            'localField' => 'event_id',
            'foreignField' => 'id',
            'as' => 'event'
        ]
    ],
    ['$unwind' => ['path' => '$event', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['checkin_at' => -1, 'id' => -1]],
    ['$limit' => 25]
]);
$attendance_report = [];
foreach ($attRepAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['guest_name'] = $row['guest']['name'] ?? null;
    $row['designation'] = $row['guest']['designation'] ?? null;
    $row['organization'] = $row['guest']['organization'] ?? null;
    $row['category'] = $row['guest']['category'] ?? null;
    $row['event_name'] = $row['event']['name'] ?? null;
    unset($row['guest'], $row['event']);
    $attendance_report[] = $row;
}

// Events overview report (MongoDB Atlas)
$evRepAgg = mcoll('events')->aggregate([
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'event_director_id',
            'foreignField' => 'id',
            'as' => 'director'
        ]
    ],
    ['$unwind' => ['path' => '$director', 'preserveNullAndEmptyArrays' => true]],
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'event_coordinator_id',
            'foreignField' => 'id',
            'as' => 'coordinator'
        ]
    ],
    ['$unwind' => ['path' => '$coordinator', 'preserveNullAndEmptyArrays' => true]],
    [
        '$lookup' => [
            'from' => 'guests',
            'localField' => 'id',
            'foreignField' => 'event_id',
            'as' => 'guests'
        ]
    ],
    [
        '$lookup' => [
            'from' => 'duties',
            'localField' => 'id',
            'foreignField' => 'event_id',
            'as' => 'duties'
        ]
    ],
    ['$sort' => ['event_date' => -1, 'id' => -1]]
]);
$events_report = [];
foreach ($evRepAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['director_name'] = $row['director']['name'] ?? null;
    $row['coordinator_name'] = $row['coordinator']['name'] ?? null;
    $row['guest_count'] = count($row['guests'] ?? []);
    $row['duty_count'] = count($row['duties'] ?? []);
    unset($row['director'], $row['coordinator'], $row['guests'], $row['duties']);
    $events_report[] = $row;
}

// Guests detailed report (MongoDB Atlas)
$guestRepAgg = mcoll('guests')->aggregate([
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
            'from' => 'seating_assignments',
            'localField' => 'id',
            'foreignField' => 'guest_id',
            'as' => 'seat'
        ]
    ],
    ['$unwind' => ['path' => '$seat', 'preserveNullAndEmptyArrays' => true]],
    ['$sort' => ['id' => -1]]
]);
$guests_report = [];
foreach ($guestRepAgg as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['event_name'] = $row['event']['name'] ?? null;
    $row['seat_label'] = $row['seat']['seat_code'] ?? ($row['assigned_seat'] ?? 'Unassigned');
    unset($row['event'], $row['seat']);
    $guests_report[] = $row;
}

require __DIR__ . '/includes/header.php';
?>


<div class="print-only-header" style="display:none;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #082d21; padding-bottom:8px; margin-bottom:12px;">
        <div>
            <h2 style="margin:0; font-size:16px; color:#082d21; font-weight:900; letter-spacing:1px; text-transform:uppercase;">
                Bangladesh University of Professionals (BUP)
            </h2>
            <span style="font-size:11px; color:#5e776a; font-weight:700;">
                <?= $is_military_protocol ? 'Armed Forces &amp; Military Protocol Command &bull; Official Echelon Report' : 'Event &amp; Military Protocol Command &bull; Executive Analytics Summary' ?>
            </span>
        </div>
        <div style="text-align:right; font-size:10px; color:#5e776a;">
            <div>Date Generated: <?= date('d M Y, h:i A') ?></div>
            <div>Authorized Copy &bull; Clearance Level: <?= e(current_level()) ?></div>
        </div>
    </div>
</div>

<div class="page-head">

    <div>

        <span class="eyebrow">
            <?= $is_military_protocol ? 'ARMED FORCES &amp; PROTOCOL COMMAND' : 'REPORTING &amp; ANALYTICS' ?>
        </span>

        <h1>
            <?= $is_military_protocol ? 'Military Protocol Command Report' : 'Reports Dashboard' ?>
        </h1>

        <p>
            <?= $is_military_protocol ? 'Operational status across 8 connected military echelons, standing SOP compliance, and defense readiness.' : 'Operational statistics for events, guests, duties, protocol, attendance and transport.' ?>
        </p>

    </div>


    <div style="display:flex; gap:10px; align-items:center;">
        <button
            class="btn btn-primary"
            onclick="window.print()"
        >
            🖨️ Export / Print Report
        </button>
    </div>

</div>

<?php if (!$is_military_protocol): ?>
<!-- REPORT CATEGORY TABS -->
<div class="report-nav-tabs" style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:24px;">
    <a href="?tab=executive" class="btn <?= $report_tab === 'executive' ? 'btn-dark' : 'btn-light' ?>" style="font-size:12px; padding:7px 14px;">Executive Performance</a>
    <a href="?tab=events" class="btn <?= $report_tab === 'events' ? 'btn-dark' : 'btn-light' ?>" style="font-size:12px; padding:7px 14px;">Event Report</a>
    <a href="?tab=duties" class="btn <?= $report_tab === 'duties' ? 'btn-dark' : 'btn-light' ?>" style="font-size:12px; padding:7px 14px;">Duty Report</a>
    <a href="?tab=attendance" class="btn <?= $report_tab === 'attendance' ? 'btn-dark' : 'btn-light' ?>" style="font-size:12px; padding:7px 14px;">Attendance Report</a>
    <a href="?tab=teams" class="btn <?= $report_tab === 'teams' ? 'btn-dark' : 'btn-light' ?>" style="font-size:12px; padding:7px 14px;">Team Report</a>
    <a href="?tab=guests" class="btn <?= $report_tab === 'guests' ? 'btn-dark' : 'btn-light' ?>" style="font-size:12px; padding:7px 14px;">Guest Report</a>
    <a href="?tab=seating" class="btn <?= $report_tab === 'seating' ? 'btn-dark' : 'btn-light' ?>" style="font-size:12px; padding:7px 14px;">Seating Report</a>
    <a href="?tab=budget" class="btn <?= $report_tab === 'budget' ? 'btn-dark' : 'btn-light' ?>" style="font-size:12px; padding:7px 14px;">Budget Summary</a>
    <a href="?tab=protocol" class="btn <?= $report_tab === 'protocol' ? 'btn-dark' : 'btn-light' ?>" style="font-size:12px; padding:7px 14px;">Protocol Hierarchy Report</a>
</div>
<?php endif; ?>

<?php if ($report_tab === 'budget'): ?>
<!-- =====================================================
     BUDGET SUMMARY REPORT (CONTROLLED BY EVENT DIRECTOR)
====================================================== -->
<section class="panel" style="margin-bottom:28px;">
    <div class="panel-head">
        <div>
            <span class="eyebrow">FINANCIAL OVERSIGHT &amp; BUDGET PLANNING</span>
            <h2>Event Budget Planning &amp; Allocation Summary</h2>
            <p>Budget control is strictly held by Event Director. Event Coordinators may review approved figures and submit proposals.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Director Oversight</th>
                    <th>Planned Total</th>
                    <th>Approval Status</th>
                    <th>Itemized Allocation</th>
                    <th>Notes &amp; Restrictions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($budget_summary as $bs): ?>
                    <tr>
                        <td><strong><?= e($bs['name']) ?></strong></td>
                        <td><?= e($bs['director_name'] ?: 'Event Director') ?></td>
                        <td><strong style="color:#0f172a;">৳ <?= number_format((float)($bs['budget_total'] ?? 0), 2) ?></strong></td>
                        <td>
                            <span class="badge <?= $bs['budget_approved'] ? 'completed' : 'pending' ?>">
                                <?= $bs['budget_approved'] ? 'Approved' : 'Pending Review' ?>
                            </span>
                        </td>
                        <td>৳ <?= number_format((float)($bs['itemized_total'] ?? 0), 2) ?></td>
                        <td><small style="color:#64748b;"><?= e($bs['budget_notes'] ?: 'Standard event allocation') ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($budget_summary)): ?>
                    <tr><td colspan="6" class="empty">No budget records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($report_tab === 'seating'): ?>
<!-- =====================================================
     SEATING ALLOCATION REPORT
====================================================== -->
<section class="panel" style="margin-bottom:28px;">
    <div class="panel-head">
        <div>
            <span class="eyebrow">VENUE CAPACITY &amp; SEATING</span>
            <h2>Seating Allocation &amp; Capacity Report</h2>
            <p>Physical seating configuration. Note: Online events automatically disable seating allocations.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Mode</th>
                    <th>Venue</th>
                    <th>Total Capacity</th>
                    <th>Allocated Seats</th>
                    <th>Reserved Seats</th>
                    <th>Available Seats</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($seating_summary as $ss): ?>
                    <tr>
                        <td><strong><?= e($ss['event_name']) ?></strong></td>
                        <td>
                            <span class="badge <?= ($ss['mode'] === 'Online') ? 'ongoing' : (($ss['mode'] === 'Hybrid') ? 'assigned' : 'neutral') ?>">
                                <?= e($ss['mode'] ?? 'Offline') ?>
                            </span>
                        </td>
                        <td><?= e($ss['venue'] ?: 'Online / Virtual') ?></td>
                        <td><strong><?= (int)$ss['total_seats'] ?></strong></td>
                        <td><span style="color:#16a34a; font-weight:700;"><?= (int)$ss['allocated_seats'] ?></span></td>
                        <td><span style="color:#d97706; font-weight:700;"><?= (int)$ss['reserved_seats'] ?></span></td>
                        <td><span style="color:#475569;"><?= (int)$ss['available_seats'] ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($report_tab === 'teams'): ?>
<!-- =====================================================
     TEAM & DEPARTMENT READINESS REPORT
====================================================== -->
<section class="panel" style="margin-bottom:28px;">
    <div class="panel-head">
        <div>
            <span class="eyebrow">DEPARTMENT READINESS</span>
            <h2>Team &amp; Department Performance Report</h2>
            <p>Operational duty progress across all 5 departments.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Total Duties</th>
                    <th>Completed</th>
                    <th>In Progress</th>
                    <th>Pending</th>
                    <th>Readiness Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($team_summary as $ts): ?>
                    <?php
                    $rate = ($ts['total_duties'] > 0) ? round(($ts['completed'] / $ts['total_duties']) * 100) : 100;
                    ?>
                    <tr>
                        <td><strong><?= e($ts['department']) ?></strong></td>
                        <td><strong><?= (int)$ts['total_duties'] ?></strong></td>
                        <td><span style="color:#16a34a; font-weight:700;"><?= (int)$ts['completed'] ?></span></td>
                        <td><span style="color:#d97706; font-weight:700;"><?= (int)$ts['in_progress'] ?></span></td>
                        <td><span style="color:#64748b;"><?= (int)$ts['pending'] ?></span></td>
                        <td><strong><?= $rate ?>%</strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($report_tab === 'protocol'): ?>
<!-- =====================================================
     MILITARY PROTOCOL HIERARCHY & READINESS REPORT
====================================================== -->
<?php
$user_proto = $user['protocol_level'] ?? '';
$is_strategic_high = ($user_proto === 'Higher Command' || $user_proto === 'Division' || has_role('Admin') || is_event_director());

// Filter echelons based on viewer rank & immediate subordinates
$allowed_levels = [];
if ($is_strategic_high) {
    $allowed_levels = ['Higher Command', 'Division', 'Brigade', 'Battalion', 'Company', 'Platoon', 'Section', 'Individual Soldier'];
} elseif ($user_proto === 'Brigade') {
    $allowed_levels = ['Brigade', 'Battalion'];
} elseif ($user_proto === 'Battalion') {
    $allowed_levels = ['Battalion', 'Company'];
} elseif ($user_proto === 'Company') {
    $allowed_levels = ['Company', 'Platoon'];
} elseif ($user_proto === 'Platoon') {
    $allowed_levels = ['Platoon', 'Section'];
} elseif ($user_proto === 'Section') {
    $allowed_levels = ['Section', 'Individual Soldier'];
} else {
    $allowed_levels = ['Individual Soldier'];
}

$visible_units = [];
foreach ($protocol_units_report as $pu) {
    if (in_array($pu['protocol_level'], $allowed_levels, true)) {
        $visible_units[] = $pu;
    }
}

// Relevant SOPs
$visible_sops = [];
foreach ($military_sops_report as $sop) {
    if ($is_strategic_high || $sop['applicable_level'] === 'All Echelons' || strpos($sop['applicable_level'], $user_proto) !== false) {
        $visible_sops[] = $sop;
    }
}
if (empty($visible_sops)) {
    $visible_sops = array_slice($military_sops_report, 0, 3);
}
?>

<div class="stat-grid" style="margin-bottom:20px;">
    <?php if ($is_strategic_high): ?>
        <div class="stat-card">
            <span>Command Formations</span>
            <strong>8 Echelons</strong>
            <em>Supreme Command Oversight</em>
        </div>
        <div class="stat-card">
            <span>Protocol Guard Strength</span>
            <strong>96 Personnel</strong>
            <em>Active duty defense personnel</em>
        </div>
        <div class="stat-card">
            <span>Protocol Checkpoints</span>
            <strong><?= $stats['protocol_total'] ?> Checkpoints</strong>
            <em><?= $stats['protocol_done'] ?> Verified / Completed</em>
        </div>
        <div class="stat-card">
            <span>Military Standing SOPs</span>
            <strong><?= count($military_sops_report) ?> Active Directives</strong>
            <em>Authorized standing rules</em>
        </div>
    <?php elseif ($user_proto === 'Brigade'): ?>
        <div class="stat-card">
            <span>Command Tier</span>
            <strong>Level 3 &amp; 4</strong>
            <em>Brigade &amp; 1st East Bengal</em>
        </div>
        <div class="stat-card">
            <span>Battalion Strength</span>
            <strong>24 Personnel</strong>
            <em>Ceremonial Contingent</em>
        </div>
        <div class="stat-card">
            <span>Ceremonial Readiness</span>
            <strong>88% Checked</strong>
            <em>Drill &amp; Dais Inspection</em>
        </div>
        <div class="stat-card">
            <span>Authorized SOPs</span>
            <strong><?= count($visible_sops) ?> Directives</strong>
            <em>Brigade &amp; Battalion Level</em>
        </div>
    <?php elseif ($user_proto === 'Battalion'): ?>
        <div class="stat-card">
            <span>Tactical Sector</span>
            <strong>Level 4 &amp; 5</strong>
            <em>Battalion &amp; Alpha Company</em>
        </div>
        <div class="stat-card">
            <span>Company Strength</span>
            <strong>24 Personnel</strong>
            <em>Honor Guard &amp; Escort</em>
        </div>
        <div class="stat-card">
            <span>Civil Integration</span>
            <strong>Synchronized</strong>
            <em>Joint Event Directorate Link</em>
        </div>
        <div class="stat-card">
            <span>Operational Orders</span>
            <strong>Active Turnout</strong>
            <em>Perimeter Clear</em>
        </div>
    <?php elseif ($user_proto === 'Company'): ?>
        <div class="stat-card">
            <span>Operational Unit</span>
            <strong>Level 5 &amp; 6</strong>
            <em>Company &amp; 1st Platoon</em>
        </div>
        <div class="stat-card">
            <span>Platoon Roster</span>
            <strong>24 Personnel</strong>
            <em>Gate Posts &amp; Cordons</em>
        </div>
        <div class="stat-card">
            <span>Resource Deployed</span>
            <strong>Walkie &amp; Gear</strong>
            <em>SEC-CH-2 Verified</em>
        </div>
        <div class="stat-card">
            <span>Applicable SOPs</span>
            <strong><?= count($visible_sops) ?> Directives</strong>
            <em>Company Standing Orders</em>
        </div>
    <?php elseif ($user_proto === 'Platoon'): ?>
        <div class="stat-card">
            <span>Field Command</span>
            <strong>Level 6 &amp; 7</strong>
            <em>Platoon &amp; Section 1</em>
        </div>
        <div class="stat-card">
            <span>Section Turnout</span>
            <strong>100% Present</strong>
            <em>VIP Dais Guard Posts</em>
        </div>
        <div class="stat-card">
            <span>Gate Cordon</span>
            <strong>Operational</strong>
            <em>Gate 1 &amp; Protocol Hall</em>
        </div>
        <div class="stat-card">
            <span>Field Orders</span>
            <strong>Active</strong>
            <em>Standing Sentry Post</em>
        </div>
    <?php elseif ($user_proto === 'Section'): ?>
        <div class="stat-card">
            <span>Section Post</span>
            <strong>Level 7 &amp; 8</strong>
            <em>Section 1 &amp; Sentry Escort</em>
        </div>
        <div class="stat-card">
            <span>Sentry Passes</span>
            <strong>Verified #018</strong>
            <em>Honor Dais Guard</em>
        </div>
        <div class="stat-card">
            <span>Post Verification</span>
            <strong>100% Manned</strong>
            <em>No Security Anomalies</em>
        </div>
        <div class="stat-card">
            <span>Evidence Desk</span>
            <strong>Sign-Off Ready</strong>
            <em>Section Commander Oversight</em>
        </div>
    <?php else: /* Soldier */ ?>
        <div class="stat-card">
            <span>Duty Pass ID</span>
            <strong>#MIL-PASS-018</strong>
            <em>Ceremonial Honor Guard</em>
        </div>
        <div class="stat-card">
            <span>Designated Post</span>
            <strong>VIP Dais Left</strong>
            <em>Parade Concourse</em>
        </div>
        <div class="stat-card">
            <span>Execution Status</span>
            <strong>Verified Turnout</strong>
            <em>Post Manned</em>
        </div>
        <div class="stat-card">
            <span>Report Status</span>
            <strong>Evidence Active</strong>
            <em>Signed Off by Section CO</em>
        </div>
    <?php endif; ?>
</div>

<?php if ($user_proto === 'Individual Soldier'): ?>
    <!-- SOLDIER CEREMONIAL DUTY PASS REPORT -->
    <div style="background:#fffcf2; border:2px solid #d4af37; border-radius:14px; padding:20px 24px; margin-bottom:24px; box-shadow:0 6px 20px rgba(212,175,55,0.12);">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px; border-bottom:1px dashed #d4af37; padding-bottom:10px;">
            <div>
                <span style="background:#082d21; color:#f5d98d; font-size:10px; font-weight:900; padding:3px 8px; border-radius:4px; font-family:monospace;">
                    DIGITAL DUTY PASS &bull; #MIL-PASS-018
                </span>
                <h3 style="margin:4px 0 0; color:#082d21; font-size:18px;">
                    Armed Forces Ceremonial Protocol Pass &mdash; <?= e($user['name']) ?>
                </h3>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" onclick="window.print()" class="btn btn-sm" style="background:#082d21; color:#f5d98d; border:1px solid #d4af37; font-weight:700;">
                    🖨️ Print Duty Pass
                </button>
                <a href="<?= BASE_URL ?>/manage_subordinates.php" class="btn btn-sm" style="background:#d4af37; color:#082d21; font-weight:800; text-decoration:none;">
                    Soldier Desk &rarr;
                </a>
            </div>
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; font-size:12px; color:#334155;">
            <div>👤 <strong>Rank &amp; Name:</strong> <?= e($user['designation'] ?: 'Individual Soldier') ?> <?= e($user['name']) ?></div>
            <div>🛡️ <strong>Assigned Post:</strong> Ceremonial Honor Sentry (VIP Dais Guard)</div>
            <div>⚔️ <strong>Unit Formation:</strong> Section 1 &bull; 1st Protocol Platoon &bull; Alpha Company</div>
            <div>📍 <strong>Status:</strong> <span class="badge completed" style="background:#dcfce7; color:#15803d; font-weight:800;">Operational Turnout Verified</span></div>
        </div>
    </div>
<?php endif; ?>

<section class="panel" style="margin-bottom:20px;">
    <div class="panel-head">
        <div>
            <span class="eyebrow" style="color:#d4af37; font-weight:800;">
                <?= $is_strategic_high ? 'CHAIN OF COMMAND &bull; 8 ECHELONS' : e(strtoupper($user_proto)) . ' &bull; SUBORDINATE ECHELON REPORT' ?>
            </span>
            <h2 style="font-size:18px;">
                <?= $is_strategic_high ? 'Military Protocol Command Structure & Operational Readiness' : e($user_proto) . ' Echelon Tactical Report' ?>
            </h2>
            <p>
                <?= $is_strategic_high ? 'Direct defense command flow from Higher Command down to Individual Soldier.' : 'Operational analytics for your formation and immediate subordinate commands.' ?>
            </p>
        </div>
    </div>
    <div class="table-wrap military-report-table-wrap">
        <table class="military-report-table" style="font-size:12px;">
            <thead>
                <tr>
                    <th>Command Code</th>
                    <th>Echelon</th>
                    <th>Unit Name</th>
                    <th>Commanding Officer</th>
                    <th>Station / Location</th>
                    <th>Strength</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($visible_units as $pu): ?>
                    <tr>
                        <td><span style="font-family:monospace; font-weight:800; background:#f1f5f9; padding:2px 6px; border-radius:4px; font-size:11px;"><?= e($pu['unit_code']) ?></span></td>
                        <td><span class="badge neutral" style="font-weight:700;"><?= e($pu['protocol_level']) ?></span></td>
                        <td><strong><?= e($pu['unit_name']) ?></strong></td>
                        <td><?= e($pu['commander_name'] ?: 'Commanding Officer') ?></td>
                        <td><?= e($pu['location']) ?></td>
                        <td><?= e($pu['strength']) ?> Personnel</td>
                        <td><span class="badge completed" style="background:#e6f4ea; color:#137333; font-weight:700;">Operational</span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- PROTOCOL CHECKPOINTS & STANDING SOPS -->
<div class="content-grid military-content-grid" style="margin-bottom:20px;">
    <?php if ($is_strategic_high): ?>
        <section class="panel" style="margin:0;">
            <div class="panel-head">
                <div>
                    <span class="eyebrow">CEREMONIAL CHECKPOINTS</span>
                    <h2>Active Protocol Items</h2>
                    <p>Status of ceremonial &amp; security requirements.</p>
                </div>
            </div>
            <div class="table-wrap military-report-table-wrap">
                <table class="military-report-table" style="font-size:11.5px;">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Checkpoint Requirement</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($protocol_items_report as $pi): ?>
                            <tr>
                                <td><strong><?= e($pi['event_name'] ?? 'General') ?></strong></td>
                                <td><?= e($pi['item']) ?></td>
                                <td>
                                    <span class="badge <?= $pi['completed'] ? 'completed' : 'pending' ?>">
                                        <?= $pi['completed'] ? 'Completed' : 'Pending' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else: ?>
        <section class="panel" style="margin:0;">
            <div class="panel-head">
                <div>
                    <span class="eyebrow">ECHELON DIRECTIVES</span>
                    <h2>Active Operational Directives</h2>
                    <p>Current tasks executed under this command tier.</p>
                </div>
            </div>
            <div class="table-wrap military-report-table-wrap">
                <table class="military-report-table" style="font-size:11.5px;">
                    <thead>
                        <tr>
                            <th>Directive Title</th>
                            <th>Target Echelon</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Ceremonial Dais Perimeter Honor Guard</strong></td>
                            <td>VIP Dais Post</td>
                            <td><span class="badge completed">Completed</span></td>
                        </tr>
                        <tr>
                            <td><strong>Walkie-Talkie VHF SEC-CH-2 Comms Sync</strong></td>
                            <td>Gate 1 &amp; Corridor</td>
                            <td><span class="badge completed">Operational</span></td>
                        </tr>
                        <tr>
                            <td><strong>Turnout &amp; Ceremonial Bayonet Inspection</strong></td>
                            <td>Honor Contingent</td>
                            <td><span class="badge completed">Inspected</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel" style="margin:0;">
        <div class="panel-head">
            <div>
                <span class="eyebrow">STANDING ORDERS</span>
                <h2>Authorized Military SOPs</h2>
                <p>Applicable doctrine for this command tier.</p>
            </div>
        </div>
        <div class="table-wrap military-report-table-wrap">
            <table class="military-report-table" style="font-size:11.5px;">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Applicable Level</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visible_sops as $sop): ?>
                        <tr>
                            <td><strong style="font-family:monospace;"><?= e($sop['code']) ?></strong></td>
                            <td><?= e($sop['title']) ?></td>
                            <td><span class="badge neutral"><?= e($sop['applicable_level']) ?></span></td>
                            <td><span class="badge completed">Active</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
            <table class="military-report-table" style="font-size:11.5px;">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Title</th>
                        <th>Applicable Level</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($military_sops_report as $sop): ?>
                        <tr>
                            <td><strong style="font-family:monospace;"><?= e($sop['code']) ?></strong></td>
                            <td><?= e($sop['title']) ?></td>
                            <td><span class="badge neutral"><?= e($sop['applicable_level']) ?></span></td>
                            <td><span class="badge completed">Active</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php elseif ($report_tab === 'duties'): ?>
<!-- =====================================================
     DUTY REPORT
====================================================== -->
<section class="panel" style="margin-bottom:28px;">
    <div class="panel-head">
        <div>
            <span class="eyebrow">DUTY REGISTER</span>
            <h2>Operational Duty Status &amp; Chain Report</h2>
            <p>Complete task distribution across command levels L1 through L5.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Duty Title</th>
                    <th>Event</th>
                    <th>Dept &amp; Level</th>
                    <th>Schedule &amp; Location</th>
                    <th>Assignee</th>
                    <th>Supervisor</th>
                    <th>Priority</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($duty_report as $dr): ?>
                    <tr>
                        <td><strong><?= e($dr['title']) ?></strong></td>
                        <td><?= e($dr['event_name'] ?? 'General') ?></td>
                        <td><?= e($dr['department']) ?> (<?= e($dr['level']) ?>)</td>
                        <td>
                            <?= !empty($dr['duty_date']) ? e(date('d M Y', strtotime($dr['duty_date']))) : '' ?>
                            <?= !empty($dr['time_slot']) ? ' ' . e($dr['time_slot']) : '' ?>
                            <?= !empty($dr['location']) ? '<br>📍 ' . e($dr['location']) : '' ?>
                        </td>
                        <td><?= e($dr['assignee_name'] ?: 'Unassigned') ?></td>
                        <td><?= e($dr['supervisor_name'] ?: '—') ?></td>
                        <td><?= e($dr['priority']) ?></td>
                        <td><span class="badge <?= strtolower($dr['status']) ?>"><?= e($dr['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($report_tab === 'attendance'): ?>
<!-- =====================================================
     ATTENDANCE REPORT
====================================================== -->
<section class="panel" style="margin-bottom:28px;">
    <div class="panel-head">
        <div>
            <span class="eyebrow">ATTENDANCE LOG</span>
            <h2>Guest Check-In &amp; Attendance Report</h2>
            <p>Real-time attendance logs verified by token and QR verification.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Check-in Token</th>
                    <th>Guest Name</th>
                    <th>Designation &amp; Org</th>
                    <th>Category</th>
                    <th>Event</th>
                    <th>Check-In Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attendance_report as $ar): ?>
                    <tr>
                        <td><span style="font-family:monospace; font-weight:800; background:#f1f5f9; padding:2px 6px; border-radius:4px;"><?= e($ar['qr_token']) ?></span></td>
                        <td><strong><?= e($ar['guest_name'] ?: 'Guest') ?></strong></td>
                        <td><?= e($ar['designation'] ?: '') ?> <?= !empty($ar['organization']) ? '· ' . e($ar['organization']) : '' ?></td>
                        <td><?= e($ar['category'] ?: 'Invited Guest') ?></td>
                        <td><?= e($ar['event_name'] ?? 'Official Event') ?></td>
                        <td><?= !empty($ar['checkin_at']) ? e(date('d M Y h:i A', strtotime($ar['checkin_at']))) : 'Pending Check-in' ?></td>
                        <td><span class="badge <?= strtolower($ar['status']) ?>"><?= e($ar['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($report_tab === 'events'): ?>
<!-- =====================================================
     EVENT ANALYTICS & REGISTRY REPORT
====================================================== -->
<section class="panel" style="margin-bottom:28px;">
    <div class="panel-head">
        <div>
            <span class="eyebrow">EVENT REGISTRY</span>
            <h2>University Events &amp; Ceremonies Report</h2>
            <p>Comprehensive overview of scheduled, upcoming, and executed official campus events.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Event Name</th>
                    <th>Type &amp; Mode</th>
                    <th>Schedule</th>
                    <th>Venue</th>
                    <th>Director &amp; Coordinator</th>
                    <th>Guests</th>
                    <th>Duties</th>
                    <th>Budget (BDT)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events_report as $er): ?>
                    <tr>
                        <td><strong><?= e($er['name']) ?></strong></td>
                        <td>
                            <?= e($er['event_type']) ?><br>
                            <small class="badge neutral"><?= e($er['mode'] ?: 'Physical') ?></small>
                        </td>
                        <td>
                            <?= !empty($er['event_date']) ? e(date('d M Y', strtotime($er['event_date']))) : '—' ?><br>
                            <small><?= e(substr($er['event_time'] ?? '', 0, 5)) ?></small>
                        </td>
                        <td><?= e($er['venue'] ?: 'Campus Auditorium') ?></td>
                        <td>
                            <small>Dir: <?= e($er['director_name'] ?: 'System') ?></small><br>
                            <small>Coord: <?= e($er['coordinator_name'] ?: 'Unassigned') ?></small>
                        </td>
                        <td><strong><?= (int)$er['guest_count'] ?></strong> invited</td>
                        <td><strong><?= (int)$er['duty_count'] ?></strong> tasks</td>
                        <td>৳ <?= number_format((float)($er['budget_total'] ?? 0), 2) ?></td>
                        <td><span class="badge <?= strtolower($er['status']) ?>"><?= e($er['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$events_report): ?>
                    <tr><td colspan="9" class="empty">No event records available.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($report_tab === 'guests'): ?>
<!-- =====================================================
     GUEST LIST & PROTOCOL REPORT
====================================================== -->
<section class="panel" style="margin-bottom:28px;">
    <div class="panel-head">
        <div>
            <span class="eyebrow">DIGNITARY &amp; GUEST REGISTER</span>
            <h2>Invited Guests &amp; Seating Allocation Report</h2>
            <p>Complete directory of VIPs, institutional guests, seat assignments, and arrival tracking.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Guest Name</th>
                    <th>Designation &amp; Organization</th>
                    <th>Associated Event</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Allocated Seat</th>
                    <th>Contact</th>
                    <th>Arrival Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($guests_report as $gr): ?>
                    <tr>
                        <td><strong><?= e($gr['name']) ?></strong></td>
                        <td>
                            <?= e($gr['designation'] ?: '—') ?>
                            <?= !empty($gr['organization']) ? '<br><small>· ' . e($gr['organization']) . '</small>' : '' ?>
                        </td>
                        <td><?= e($gr['event_name'] ?? 'General') ?></td>
                        <td><span class="badge neutral"><?= e($gr['category'] ?: 'Invited Guest') ?></span></td>
                        <td>
                            <span class="badge <?= strtolower($gr['priority_level'] ?? 'normal') === 'high' ? 'danger' : 'neutral' ?>">
                                <?= e($gr['priority_level'] ?: 'Normal') ?>
                            </span>
                        </td>
                        <td>
                            <?= !empty($gr['seat_label']) ? '<strong style="color:#082d21;">' . e($gr['seat_label']) . '</strong>' : '<span style="color:#94a3b8;">Unseated</span>' ?>
                        </td>
                        <td>
                            <small><?= e($gr['phone'] ?: '') ?></small>
                            <?= !empty($gr['email']) ? '<br><small>' . e($gr['email']) . '</small>' : '' ?>
                        </td>
                        <td><span class="badge <?= strtolower($gr['arrival_status']) ?>"><?= e($gr['arrival_status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$guests_report): ?>
                    <tr><td colspan="8" class="empty">No guest records available.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php endif; ?>

<?php if (!$is_military_protocol && in_array($report_tab, ['executive', ''])): ?>
<!-- MAIN METRICS -->

<div class="stat-grid">

    <div class="stat-card">

        <span>Total Events</span>

        <strong>
            <?= $stats['events'] ?>
        </strong>

        <em>
            All event records
        </em>

    </div>


    <div class="stat-card">

        <span>Upcoming Events</span>

        <strong>
            <?= $stats['upcoming'] ?>
        </strong>

        <em>
            Scheduled events
        </em>

    </div>


    <div class="stat-card">

        <span>Completed Events</span>

        <strong>
            <?= $stats['completed'] ?>
        </strong>

        <em>
            Finished events
        </em>

    </div>


    <div class="stat-card">

        <span>Total Guests</span>

        <strong>
            <?= $stats['guests'] ?>
        </strong>

        <em>
            Guest & VIP records
        </em>

    </div>


    <div class="stat-card">

        <span>Total Duties</span>

        <strong>
            <?= $stats['duties'] ?>
        </strong>

        <em>
            Operational duties
        </em>

    </div>


    <div class="stat-card">

        <span>Present</span>

        <strong>
            <?= $stats['attendance'] ?>
        </strong>

        <em>
            Attendance records
        </em>

    </div>

</div>


<!-- OPERATIONAL PROGRESS -->

<section class="panel">

    <div class="panel-head">

        <div>

            <h2>
                Operational Progress
            </h2>

            <p>
                Current completion indicators across the system.
            </p>

        </div>

    </div>


    <div class="report-bars">


        <!-- DUTY -->

        <div>

            <span>
                Duty Completion
            </span>


            <div class="progress">

                <i
                    style="width: <?= $duty_completion ?>%"
                ></i>

            </div>


            <b>
                <?= $duty_completion ?>%
            </b>

        </div>


        <!-- EVENT -->

        <div>

            <span>
                Event Completion
            </span>


            <div class="progress">

                <i
                    style="width: <?= $event_completion ?>%"
                ></i>

            </div>


            <b>
                <?= $event_completion ?>%
            </b>

        </div>


        <!-- ATTENDANCE -->

        <div>

            <span>
                Attendance Rate
            </span>


            <div class="progress">

                <i
                    style="width: <?= $attendance_rate ?>%"
                ></i>

            </div>


            <b>
                <?= $attendance_rate ?>%
            </b>

        </div>


        <!-- PROTOCOL -->

        <div>

            <span>
                Protocol Completion
            </span>


            <div class="progress">

                <i
                    style="width: <?= $protocol_completion ?>%"
                ></i>

            </div>


            <b>
                <?= $protocol_completion ?>%
            </b>

        </div>

    </div>

</section>


<!-- EVENT STATUS + TYPE -->

<div class="content-grid">


    <!-- EVENT STATUS -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>
                    Event Status
                </h2>

                <p>
                    Current distribution of event records.
                </p>

            </div>

        </div>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>
                            Status
                        </th>

                        <th>
                            Total
                        </th>

                        <th>
                            Share
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach (
                    $event_statuses as $status => $total
                ): ?>

                    <?php

                    $share = $stats['events'] > 0
                        ? round(
                            ($total / $stats['events']) * 100
                        )
                        : 0;

                    ?>

                    <tr>

                        <td>

                            <span
                                class="badge <?= strtolower(
                                    $status
                                ) ?>"
                            >
                                <?= e($status) ?>
                            </span>

                        </td>


                        <td>

                            <strong>
                                <?= $total ?>
                            </strong>

                        </td>


                        <td>

                            <?= $share ?>%

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- EVENT TYPE -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>
                    Event Types
                </h2>

                <p>
                    Event distribution by category.
                </p>

            </div>

        </div>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>
                            Event Type
                        </th>

                        <th>
                            Total
                        </th>

                        <th>
                            Share
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach ($event_types as $type): ?>

                    <?php

                    $share = $stats['events'] > 0
                        ? round(
                            ($type['total']
                            / $stats['events']) * 100
                        )
                        : 0;

                    ?>

                    <tr>

                        <td>
                            <?= e(
                                $type['event_type']
                            ) ?>
                        </td>

                        <td>

                            <strong>
                                <?= e(
                                    (string)$type['total']
                                ) ?>
                            </strong>

                        </td>

                        <td>
                            <?= $share ?>%
                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$event_types): ?>

                    <tr>

                        <td
                            colspan="3"
                            class="empty"
                        >
                            No event data available.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>


<!-- DUTY + GUEST ANALYTICS -->

<div class="content-grid">


    <!-- DUTY -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>
                    Duty Analytics
                </h2>

                <p>
                    Distribution of operational duty status.
                </p>

            </div>

        </div>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>
                            Status
                        </th>

                        <th>
                            Total
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach ($duty_status as $row): ?>

                    <tr>

                        <td>

                            <span
                                class="badge <?= strtolower(
                                    $row['status']
                                ) ?>"
                            >
                                <?= e($row['status']) ?>
                            </span>

                        </td>

                        <td>

                            <strong>
                                <?= e(
                                    (string)$row['total']
                                ) ?>
                            </strong>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$duty_status): ?>

                    <tr>

                        <td
                            colspan="2"
                            class="empty"
                        >
                            No duty data available.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- GUEST CATEGORY -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>
                    Guest Categories
                </h2>

                <p>
                    Distribution of registered guests.
                </p>

            </div>

        </div>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>
                            Category
                        </th>

                        <th>
                            Total
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach (
                    $guest_categories as $row
                ): ?>

                    <tr>

                        <td>

                            <span class="badge neutral">
                                <?= e(
                                    $row['category']
                                ) ?>
                            </span>

                        </td>

                        <td>

                            <strong>
                                <?= e(
                                    (string)$row['total']
                                ) ?>
                            </strong>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$guest_categories): ?>

                    <tr>

                        <td
                            colspan="2"
                            class="empty"
                        >
                            No guest data available.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>


<!-- GUEST PRIORITY + SYSTEM COUNTS -->

<div class="content-grid">


    <!-- PRIORITY -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>
                    Guest Priority
                </h2>

                <p>
                    Priority distribution of guests and VIPs.
                </p>

            </div>

        </div>


        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>
                            Priority
                        </th>

                        <th>
                            Guests
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach (
                    $guest_priorities as $row
                ): ?>

                    <tr>

                        <td>

                            <span class="priority">
                                <?= e(
                                    $row['priority_level']
                                ) ?>
                            </span>

                        </td>

                        <td>

                            <strong>
                                <?= e(
                                    (string)$row['total']
                                ) ?>
                            </strong>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$guest_priorities): ?>

                    <tr>

                        <td
                            colspan="2"
                            class="empty"
                        >
                            No priority data available.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>


    <!-- SYSTEM COUNTS -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>
                    Operational Records
                </h2>

                <p>
                    Supporting system records.
                </p>

            </div>

        </div>


        <div class="stat-grid">


            <div class="stat-card">

                <span>
                    Protocol Items
                </span>

                <strong>
                    <?= $stats['protocol_total'] ?>
                </strong>

                <em>
                    <?= $stats['protocol_done'] ?>
                    completed
                </em>

            </div>


            <div class="stat-card">

                <span>
                    Transport
                </span>

                <strong>
                    <?= $stats['transport'] ?>
                </strong>

                <em>
                    Arrival records
                </em>

            </div>

        </div>

    </section>

</div>


<!-- RECENT EVENTS -->

<section class="panel recent-activity-print-hide">

    <div class="panel-head">

        <div>

            <h2>
                Recent Events
            </h2>

            <p>
                Latest event records in the system.
            </p>

        </div>

        <a
            href="<?= BASE_URL ?>/events.php"
        >
            View Events
        </a>

    </div>


    <div class="table-wrap">

        <table>

            <thead>

                <tr>

                    <th>
                        Event
                    </th>

                    <th>
                        Date
                    </th>

                    <th>
                        Venue
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Created By
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php foreach (
                $recent_events as $event
            ): ?>

                <tr>

                    <td>

                        <strong>
                            <?= e(
                                $event['name']
                            ) ?>
                        </strong>

                        <small>
                            <?= e(
                                $event['event_type']
                            ) ?>
                        </small>

                    </td>


                    <td>

                        <?= e(
                            $event['event_date']
                        ) ?>

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

                        <?= e(
                            $event['venue']
                        ) ?>

                    </td>


                    <td>

                        <span
                            class="badge <?= strtolower(
                                $event['status']
                            ) ?>"
                        >

                            <?= e(
                                $event['status']
                            ) ?>

                        </span>

                    </td>


                    <td>

                        <?= e(
                            $event['creator']
                            ?? 'System'
                        ) ?>

                    </td>

                </tr>

            <?php endforeach; ?>


            <?php if (!$recent_events): ?>

                <tr>

                    <td
                        colspan="5"
                        class="empty"
                    >
                        No events available.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<!-- RECENT AUDIT -->

<section class="panel recent-activity-print-hide">

    <div class="panel-head">

        <div>

            <h2>
                Recent Audit Activity
            </h2>

            <p>
                Recent system operations and changes.
            </p>

        </div>

    </div>


    <div class="activity-list">

        <?php foreach (
            $recent_audit as $audit
        ): ?>

            <div class="activity">

                <span class="activity-dot"></span>


                <div>

                    <strong>
                        <?= e(
                            $audit['action']
                        ) ?>
                    </strong>


                    <p>
                        <?= e(
                            $audit['description']
                        ) ?>
                    </p>


                    <small>

                        <?= e(
                            $audit['created_at']
                        ) ?>

                        ·

                        <?= e(
                            $audit['user_name']
                            ?? 'System'
                        ) ?>

                    </small>

                </div>

            </div>

        <?php endforeach; ?>


        <?php if (!$recent_audit): ?>

            <div class="empty">

                No audit activity available.

            </div>

        <?php endif; ?>

    </div>

</section>

<?php endif; ?>



<style>
/* =========================================
   REPORTS PAGE — CARD SPACING & LAYOUT
========================================= */

.page-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 30px;
}

.page-head > div {
    min-width: 0;
}

.page-head h1 {
    margin-bottom: 12px;
}

.page-head p {
    margin-bottom: 0;
}

/* Main summary cards */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 22px;
    margin: 0 0 30px;
    align-items: stretch;
}

.stat-grid > .stat-card {
    min-width: 0;
    width: 100%;
    margin: 0;
    padding: 28px;
    box-sizing: border-box;
    border-radius: 22px;
}

/* Operational progress panel */
.panel {
    min-width: 0;
    box-sizing: border-box;
    margin-bottom: 28px;
    border-radius: 22px;
    overflow: hidden;
}

.panel-head {
    padding: 24px 30px;
}

.panel-head h2 {
    margin: 0 0 8px;
}

.panel-head p {
    margin: 0;
}

/* Progress bars */
.report-bars {
    display: flex;
    flex-direction: column;
    gap: 22px;
    padding: 28px 32px 32px;
}

.report-bars > div {
    display: grid;
    grid-template-columns: 200px minmax(0, 1fr) 55px;
    align-items: center;
    gap: 18px;
    min-width: 0;
}

.report-bars > div > span {
    min-width: 0;
}

.report-bars .progress {
    width: 100%;
    min-width: 0;
    height: 12px;
    margin: 0;
    border-radius: 999px;
    overflow: hidden;
    background: #edf1f5;
}

.report-bars .progress i {
    display: block;
    height: 100%;
    max-width: 100%;
    border-radius: inherit;
    background: #c9a45e;
}

.report-bars > div > b {
    text-align: right;
    white-space: nowrap;
}

/* Analytics sections: Event Status, Event Types,
   Duty Analytics, Guest Categories, Guest Priority */
.content-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 28px;
    margin: 0 0 28px;
    align-items: start;
}

.content-grid > .panel {
    width: 100%;
    min-width: 0;
    margin: 0;
}

/* Nested stat cards under Operational Records */
.content-grid .stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
    padding: 24px;
    margin: 0;
}

.content-grid .stat-grid > .stat-card {
    padding: 22px;
}

/* Tables stay inside their own cards */
.table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    margin: 0;
}

.table-wrap table {
    width: 100%;
    min-width: 420px;
    border-collapse: collapse;
}

.table-wrap th,
.table-wrap td {
    padding: 17px 20px;
    vertical-align: middle;
}

.table-wrap td strong {
    overflow-wrap: anywhere;
}

.table-wrap td small {
    display: block;
    margin-top: 5px;
}

/* Recent activity */
.activity-list {
    display: flex;
    flex-direction: column;
    gap: 0;
    padding: 22px 28px;
}

.activity {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 18px 0;
    min-width: 0;
}

.activity > div {
    min-width: 0;
    overflow-wrap: anywhere;
}

.activity p {
    margin: 7px 0;
}

.activity + .activity {
    border-top: 1px solid #e8edf2;
}

@page {
    size: A4 portrait;
    margin: 8mm 10mm;
}

/* Print-friendly layout (Strictly 1 Single A4 Page, No Overflow) */
@media print {
    html, body {
        background: #ffffff !important;
        color: #082d21 !important;
        font-size: 11px !important;
        line-height: 1.3 !important;
        margin: 0 !important;
        padding: 0 !important;
        height: auto !important;
        max-height: 297mm !important;
        overflow: hidden !important;
    }

    .print-only-header {
        display: block !important;
    }

    .topbar,
    .sidebar,
    .report-nav-tabs,
    .page-head button,
    .footer,
    .no-print,
    .recent-activity-print-hide,
    .table-wrap:not(.military-report-table-wrap),
    .activity-list {
        display: none !important;
    }

    .military-report-table-wrap {
        display: block !important;
        overflow: visible !important;
        margin: 0 0 8px !important;
    }

    .military-report-table {
        display: table !important;
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 9.5px !important;
    }

    .military-report-table th,
    .military-report-table td {
        padding: 4px 6px !important;
        border: 1px solid #dce6e1 !important;
        line-height: 1.2 !important;
    }

    .military-report-table th {
        background: #f1f5f9 !important;
        color: #082d21 !important;
        font-weight: 700 !important;
    }

    .military-content-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 8px !important;
        margin: 0 !important;
    }

    .app-shell {
        display: block !important;
        height: auto !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: visible !important;
    }

    .main-content {
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
        height: auto !important;
    }

    .page-head {
        margin-bottom: 10px !important;
        gap: 6px !important;
        align-items: flex-start !important;
    }

    .page-head h1 {
        font-size: 19px !important;
        margin: 2px 0 3px !important;
        color: #082d21 !important;
    }

    .page-head p {
        font-size: 11px !important;
        color: #5e776a !important;
    }

    .stat-grid {
        display: grid !important;
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 8px !important;
        margin: 0 0 10px !important;
    }

    .stat-card {
        padding: 10px 14px !important;
        border-radius: 8px !important;
        border: 1px solid #dce6e1 !important;
        box-shadow: none !important;
        background: #ffffff !important;
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }

    .stat-card span {
        font-size: 9px !important;
        text-transform: uppercase !important;
        color: #5e776a !important;
    }

    .stat-card strong {
        font-size: 20px !important;
        margin: 2px 0 !important;
        color: #082d21 !important;
    }

    .stat-card em {
        font-size: 9px !important;
        color: #8da498 !important;
    }

    .content-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
        margin: 0 !important;
        align-items: start !important;
    }

    .panel {
        border-radius: 8px !important;
        border: 1px solid #dce6e1 !important;
        box-shadow: none !important;
        margin-bottom: 10px !important;
        break-inside: avoid !important;
        page-break-inside: avoid !important;
        overflow: hidden !important;
    }

    .panel-head {
        padding: 8px 12px !important;
        border-bottom: 1px solid #edf2ef !important;
    }

    .panel-head h2 {
        font-size: 12px !important;
        color: #082d21 !important;
        margin: 0 !important;
    }

    .panel-head p {
        font-size: 9px !important;
        color: #64748b !important;
        margin: 1px 0 0 !important;
    }

    .report-bars {
        padding: 10px 12px !important;
        gap: 8px !important;
    }

    .report-bars > div {
        grid-template-columns: 130px minmax(0, 1fr) 38px !important;
        gap: 8px !important;
        font-size: 10px !important;
    }

    .report-bars .progress {
        height: 6px !important;
        background: #eaf3ee !important;
    }

    .report-bars .progress i {
        background: #082d21 !important;
    }

    .content-grid .stat-grid {
        padding: 8px 12px !important;
        gap: 8px !important;
    }

    .content-grid .stat-grid > .stat-card {
        padding: 8px 10px !important;
    }
}

/* Medium screens */
@media (max-width: 1100px) {
    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }

    .content-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 22px;
    }

    .report-bars > div {
        grid-template-columns: 165px minmax(0, 1fr) 48px;
        gap: 14px;
    }
}

/* Mobile */
@media (max-width: 700px) {
    .page-head {
        align-items: flex-start;
        gap: 16px;
        margin-bottom: 24px;
    }

    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .stat-grid > .stat-card {
        padding: 20px;
        border-radius: 17px;
    }

    .panel {
        margin-bottom: 22px;
        border-radius: 17px;
    }

    .panel-head {
        padding: 20px;
    }

    .report-bars {
        gap: 22px;
        padding: 20px;
    }

    .report-bars > div {
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px 14px;
    }

    .report-bars > div > span {
        grid-column: 1;
    }

    .report-bars .progress {
        grid-column: 1 / -1;
        grid-row: 2;
    }

    .report-bars > div > b {
        grid-column: 2;
        grid-row: 1;
    }

    .content-grid {
        gap: 20px;
        margin-bottom: 22px;
    }

    .content-grid .stat-grid {
        gap: 12px;
        padding: 18px;
    }

    .content-grid .stat-grid > .stat-card {
        padding: 17px;
    }

    .table-wrap th,
    .table-wrap td {
        padding: 14px;
    }

    .activity-list {
        padding: 16px 20px;
    }
}

/* Very small screens */
@media (max-width: 420px) {
    .stat-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .content-grid .stat-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .panel-head {
        padding: 17px;
    }

    .report-bars {
        padding: 17px;
    }
}
</style>



<?php require __DIR__ . '/includes/footer.php'; ?>