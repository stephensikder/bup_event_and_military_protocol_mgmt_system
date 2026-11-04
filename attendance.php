<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_login();

if (is_staff() || is_security_lead() || is_student()) {
    flash('error', 'Attendance management is restricted and not accessible for this role.');
    redirect(is_student() ? BASE_URL . '/student_dashboard.php' : BASE_URL . '/dashboard.php');
}

$page_title = 'Attendance & QR | BUP Protocol';

/*
|--------------------------------------------------------------------------
| POST ACTIONS (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | QR / TOKEN CHECK-IN
    |--------------------------------------------------------------------------
    */

    if ($action === 'checkin') {

        $token = trim($_POST['token'] ?? '');

        if ($token === '') {
            flash('error', 'Please enter an attendance token.');
            redirect(BASE_URL . '/attendance.php');
        }

        $pipeline = [
            ['$match' => ['qr_token' => $token]],
            ['$lookup' => [
                'from' => 'guests',
                'localField' => 'guest_id',
                'foreignField' => 'id',
                'as' => 'guest_docs'
            ]],
            ['$lookup' => [
                'from' => 'events',
                'localField' => 'event_id',
                'foreignField' => 'id',
                'as' => 'event_docs'
            ]],
            ['$addFields' => [
                'guest_name' => ['$ifNull' => [['$arrayElemAt' => ['$guest_docs.name', 0]], 'Guest']],
                'event_name' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.name', 0]], 'Unknown Event']]
            ]],
            ['$project' => ['guest_docs' => 0, 'event_docs' => 0]],
            ['$limit' => 1]
        ];

        $cursor = mcoll('attendance')->aggregate($pipeline);
        $row = null;
        foreach ($cursor as $doc) {
            $row = mongo_doc_to_array($doc);
            break;
        }

        if (!$row) {
            flash('error', 'QR token not found.');
            redirect(BASE_URL . '/attendance.php');
        }

        /*
        | Prevent duplicate check-in
        */

        if (($row['status'] ?? '') === 'Present') {
            flash('error', 'This guest is already marked as Present.');
            redirect(BASE_URL . '/attendance.php');
        }

        $now = date('Y-m-d H:i:s');

        mcoll('attendance')->updateOne(
            ['id' => (int)$row['id']],
            ['$set' => [
                'status' => 'Present',
                'checkin_at' => $now,
                'check_in_time' => $now
            ]]
        );

        if (!empty($row['guest_id'])) {
            mcoll('guests')->updateOne(
                ['id' => (int)$row['guest_id']],
                ['$set' => ['arrival_status' => 'Arrived', 'updated_at' => $now]]
            );
        }

        if (!empty($token)) {
            mcoll('invitations')->updateOne(
                ['qr_token' => $token],
                ['$set' => ['status' => 'Accepted', 'responded_at' => $now]]
            );
        }

        /*
        | Audit log
        */

        mongo_audit_log(
            'Guest Check-in',
            'Checked in ' .
            ($row['guest_name'] ?? 'Guest') .
            ' for event ' .
            ($row['event_name'] ?? 'Unknown Event')
        );

        flash(
            'success',
            'Attendance recorded successfully for ' .
            ($row['guest_name'] ?? 'guest') . '.'
        );

        redirect(BASE_URL . '/attendance.php');
    }


    /*
    |--------------------------------------------------------------------------
    | RESET / MARK ABSENT
    |--------------------------------------------------------------------------
    */

    if ($action === 'reset') {

        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {

            $pipeline = [
                ['$match' => ['id' => $id]],
                ['$lookup' => [
                    'from' => 'guests',
                    'localField' => 'guest_id',
                    'foreignField' => 'id',
                    'as' => 'guest_docs'
                ]],
                ['$addFields' => [
                    'guest_name' => ['$ifNull' => [['$arrayElemAt' => ['$guest_docs.name', 0]], 'Guest']]
                ]],
                ['$limit' => 1]
            ];

            $cursor = mcoll('attendance')->aggregate($pipeline);
            $record = null;
            foreach ($cursor as $doc) {
                $record = mongo_doc_to_array($doc);
                break;
            }

            if ($record) {

                mcoll('attendance')->updateOne(
                    ['id' => $id],
                    ['$set' => [
                        'status' => 'Registered',
                        'checkin_at' => null,
                        'check_in_time' => null
                    ]]
                );

                mongo_audit_log(
                    'Attendance Reset',
                    'Reset attendance for ' .
                    ($record['guest_name'] ?? 'Guest')
                );

                flash(
                    'success',
                    'Attendance status reset.'
                );
            }
        }

        redirect(BASE_URL . '/attendance.php');
    }
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$q = trim($_GET['q'] ?? '');
$event_filter = (int)($_GET['event_id'] ?? 0);
$status_filter = $_GET['status'] ?? '';


/*
|--------------------------------------------------------------------------
| EVENTS (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

$eventsCursor = mcoll('events')->find([], ['sort' => ['event_date' => -1, 'event_time' => -1]]);
$events = [];
foreach ($eventsCursor as $eDoc) {
    $events[] = mongo_doc_to_array($eDoc);
}


/*
|--------------------------------------------------------------------------
| ATTENDANCE QUERY (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$pipeline = [];

$pipeline[] = [
    '$lookup' => [
        'from' => 'guests',
        'localField' => 'guest_id',
        'foreignField' => 'id',
        'as' => 'guest_docs'
    ]
];

$pipeline[] = [
    '$lookup' => [
        'from' => 'events',
        'localField' => 'event_id',
        'foreignField' => 'id',
        'as' => 'event_docs'
    ]
];

$pipeline[] = [
    '$addFields' => [
        'guest_name' => ['$ifNull' => [['$arrayElemAt' => ['$guest_docs.name', 0]], '']],
        'designation' => ['$ifNull' => [['$arrayElemAt' => ['$guest_docs.designation', 0]], '']],
        'organization' => ['$ifNull' => [['$arrayElemAt' => ['$guest_docs.organization', 0]], '']],
        'category' => ['$ifNull' => [['$arrayElemAt' => ['$guest_docs.category', 0]], '']],
        'priority_level' => ['$ifNull' => [['$arrayElemAt' => ['$guest_docs.priority_level', 0]], '']],
        'event_name' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.name', 0]], '']],
        'event_date' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.event_date', 0]], '']],
        'event_time' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.event_time', 0]], '']],
        'status_order' => [
            '$cond' => [
                'if' => ['$eq' => ['$status', 'Present']],
                'then' => 1,
                'else' => 2
            ]
        ]
    ]
];

$pipeline[] = [
    '$project' => [
        'guest_docs' => 0,
        'event_docs' => 0
    ]
];

$match = [];

if ($q !== '') {
    $regex = new MongoDB\BSON\Regex($q, 'i');
    $match['$or'] = [
        ['guest_name' => $regex],
        ['designation' => $regex],
        ['organization' => $regex],
        ['event_name' => $regex],
        ['qr_token' => $regex]
    ];
}

if ($event_filter > 0) {
    $match['event_id'] = $event_filter;
}

if ($status_filter !== '') {
    $match['status'] = $status_filter;
}

if (!empty($match)) {
    $pipeline[] = ['$match' => $match];
}

$pipeline[] = [
    '$sort' => [
        'status_order' => 1,
        'checkin_at' => -1,
        'id' => -1
    ]
];

$cursor = mcoll('attendance')->aggregate($pipeline);
$records = [];
foreach ($cursor as $doc) {
    $records[] = mongo_doc_to_array($doc);
}


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$total = count($records);

$present = 0;
$registered = 0;

foreach ($records as $record) {

    if (($record['status'] ?? '') === 'Present') {
        $present++;
    } else {
        $registered++;
    }
}

$attendance_rate = $total > 0
    ? round(($present / $total) * 100)
    : 0;


/*
|--------------------------------------------------------------------------
| EVENT-WISE COUNTS
|--------------------------------------------------------------------------
*/

$event_summary = [];

foreach ($records as $record) {

    $event_name = $record['event_name'] ?? 'Unknown Event';

    if (!isset($event_summary[$event_name])) {

        $event_summary[$event_name] = [
            'total' => 0,
            'present' => 0
        ];
    }

    $event_summary[$event_name]['total']++;

    if (($record['status'] ?? '') === 'Present') {
        $event_summary[$event_name]['present']++;
    }
}


require __DIR__ . '/includes/header.php';
?>


<div class="page-head">

    <div>

        <span class="eyebrow">
            ATTENDANCE & CHECK-IN
        </span>

        <h1>
            Live Attendance
        </h1>

        <p>
            QR-ready check-in and guest attendance tracking.
        </p>

    </div>

</div>


<!-- SUMMARY -->

<div class="stat-grid">

    <div class="stat-card">

        <span>Present</span>

        <strong>
            <?= $present ?>
        </strong>

        <em>
            Recorded check-ins
        </em>

    </div>


    <div class="stat-card">

        <span>Registered</span>

        <strong>
            <?= $registered ?>
        </strong>

        <em>
            Awaiting check-in
        </em>

    </div>


    <div class="stat-card">

        <span>Total Records</span>

        <strong>
            <?= $total ?>
        </strong>

        <em>
            Attendance records
        </em>

    </div>


    <div class="stat-card">

        <span>Attendance Rate</span>

        <strong>
            <?= $attendance_rate ?>%
        </strong>

        <em>
            Current attendance
        </em>

    </div>

</div>


<!-- QR CHECK-IN -->

<section class="panel">

    <div class="panel-head">

        <div>

            <h2>
                QR / Token Check-in
            </h2>

            <p>
                Scan or enter an attendance token to record guest arrival.
            </p>

        </div>

    </div>


    <form
        method="post"
        class="filter-form"
    >

        <input
            type="hidden"
            name="action"
            value="checkin"
        >


        <input
            type="text"
            name="token"
            value="<?= e($_GET['token'] ?? '') ?>"
            placeholder="Enter QR / attendance token..."
            autocomplete="off"
            required
        >


        <button
            class="btn btn-primary"
            type="submit"
        >
            ✓ Confirm Check-in
        </button>

    </form>


    <div class="qr-note">

        <strong>QR Ready:</strong>
        The attendance system uses the existing
        <code>qr_token</code> field. A QR scanner can submit
        the scanned token through this check-in form.

    </div>

</section>


<!-- FILTER -->

<section class="panel">

    <div class="panel-head">

        <div>

            <h2>
                Attendance Search & Filter
            </h2>

            <p>
                Find attendance records by guest, event or status.
            </p>

        </div>

    </div>


    <form
        method="get"
        class="filter-form"
    >


        <input
            type="text"
            name="q"
            value="<?= e($q) ?>"
            placeholder="Search guest, event, organization..."
        >


        <select name="event_id">

            <option value="">
                All Events
            </option>


            <?php foreach ($events as $event): ?>

                <option
                    value="<?= $event['id'] ?>"
                    <?= $event_filter == $event['id']
                        ? 'selected'
                        : ''
                    ?>
                >

                    <?= e($event['name']) ?>

                </option>

            <?php endforeach; ?>

        </select>


        <select name="status">

            <option value="">
                All Status
            </option>

            <option
                value="Present"
                <?= $status_filter === 'Present'
                    ? 'selected'
                    : ''
                ?>
            >
                Present
            </option>

            <option
                value="Registered"
                <?= $status_filter === 'Registered'
                    ? 'selected'
                    : ''
                ?>
            >
                Registered
            </option>

        </select>


        <button
            class="btn btn-dark"
            type="submit"
        >
            Filter
        </button>


        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/attendance.php"
        >
            Reset
        </a>

    </form>

</section>


<!-- EVENT SUMMARY -->

<?php if ($event_summary): ?>

<section class="panel">

    <div class="panel-head">

        <div>

            <h2>
                Event Attendance Overview
            </h2>

            <p>
                Live attendance breakdown by event.
            </p>

        </div>

    </div>


    <div class="table-wrap">

        <table>

            <thead>

                <tr>

                    <th>Event</th>

                    <th>Registered</th>

                    <th>Present</th>

                    <th>Rate</th>

                </tr>

            </thead>


            <tbody>

            <?php foreach ($event_summary as $event_name => $summary): ?>

                <?php
                $event_rate = $summary['total'] > 0
                    ? round(
                        $summary['present']
                        / $summary['total']
                        * 100
                    )
                    : 0;
                ?>

                <tr>

                    <td>

                        <strong>
                            <?= e($event_name) ?>
                        </strong>

                    </td>


                    <td>
                        <?= $summary['total'] ?>
                    </td>


                    <td>

                        <strong>
                            <?= $summary['present'] ?>
                        </strong>

                    </td>


                    <td>

                        <span class="badge neutral">
                            <?= $event_rate ?>%
                        </span>

                    </td>

                </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

</section>

<?php endif; ?>


<!-- RECENT CHECKINS -->

<section class="panel">

    <div class="panel-head">

        <div>

            <h2>
                Attendance Register
            </h2>

            <p>
                <?= count($records) ?>
                attendance records found.
            </p>

        </div>

    </div>


    <div class="table-wrap">

        <table>

            <thead>

                <tr>

                    <th>Guest</th>

                    <th>Event</th>

                    <th>Category</th>

                    <th>Priority</th>

                    <th>Status</th>

                    <th>Check-in Time</th>

                    <th>Action</th>

                </tr>

            </thead>


            <tbody>

            <?php foreach ($records as $r): ?>

                <tr>


                    <td>

                        <strong>
                            <?= e(
                                $r['guest_name']
                                ?? 'Unknown Guest'
                            ) ?>
                        </strong>

                        <small>

                            <?= e(
                                $r['designation']
                                ?? ''
                            ) ?>


                            <?php if (!empty($r['organization'])): ?>

                                · <?= e(
                                    $r['organization']
                                ) ?>

                            <?php endif; ?>

                        </small>

                    </td>


                    <td>

                        <?= e(
                            $r['event_name']
                            ?? 'Unknown Event'
                        ) ?>

                    </td>


                    <td>

                        <span class="badge neutral">

                            <?= e(
                                $r['category']
                                ?? '—'
                            ) ?>

                        </span>

                    </td>


                    <td>

                        <?= e(
                            $r['priority_level']
                            ?? 'Normal'
                        ) ?>

                    </td>


                    <td>

                        <span
                            class="badge <?= strtolower(
                                $r['status']
                            ) ?>"
                        >

                            <?= e(
                                $r['status']
                            ) ?>

                        </span>

                    </td>


                    <td>

                        <?php if ($r['checkin_at']): ?>

                            <?= e(
                                date(
                                    'd M Y',
                                    strtotime(
                                        $r['checkin_at']
                                    )
                                )
                            ) ?>

                            <small>

                                <?= e(
                                    date(
                                        'h:i A',
                                        strtotime(
                                            $r['checkin_at']
                                        )
                                    )
                                ) ?>

                            </small>

                        <?php else: ?>

                            —

                        <?php endif; ?>

                    </td>


                    <td>

                        <?php if ($r['status'] === 'Present'): ?>

                            <form
                                method="post"
                                class="inline-form"
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="reset"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= $r['id'] ?>"
                                >


                                <button
                                    type="submit"
                                    class="link-danger"
                                    onclick="return confirm('Reset this attendance record?')"
                                >
                                    Reset
                                </button>

                            </form>

                        <?php else: ?>

                            <span class="muted">
                                Awaiting
                            </span>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>


            <?php if (!$records): ?>

                <tr>

                    <td
                        colspan="7"
                        class="empty"
                    >
                        No attendance records found.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<style>
/* ==========================================
   ATTENDANCE PAGE — BUP PROTOCOL UI UPGRADE
========================================== */

:root {
    --att-navy: #082d21;
    --att-blue: #0f7652;
    --att-blue-light: #eaf8f2;
    --att-gold: #d4af37;
    --att-green: #16805d;
    --att-red: #dc4c55;
    --att-text: #082d21;
    --att-muted: #5e776a;
    --att-border: #dce6e1;
    --att-surface: #ffffff;
}

.main-content:has(.stat-grid) {
    background: #f4f8f6;
}

/* Page heading */

.page-head {
    margin-bottom: 26px;
}

.page-head .eyebrow {
    display: inline-block;
    color: var(--att-gold);
    font-weight: 800;
    letter-spacing: 2px;
    font-size: 11px;
}

.page-head h1 {
    color: var(--att-text);
    font-size: clamp(28px, 3vw, 38px);
    font-weight: 800;
    letter-spacing: -1px;
    margin: 10px 0;
}

.page-head p {
    color: var(--att-muted);
    font-size: 15px;
}

/* Statistics cards */

.stat-grid {
    gap: 18px;
    margin-bottom: 24px;
}

.stat-grid .stat-card {
    position: relative;
    overflow: hidden;
    padding: 25px 24px;
    min-height: 155px;
    border: 1px solid var(--att-border);
    border-radius: 20px;
    background: linear-gradient(145deg, #ffffff 65%, #f5f8ff);
    box-shadow: 0 8px 28px rgba(16, 36, 58, .045);
    transition: transform .2s ease, box-shadow .2s ease;
}

.stat-grid .stat-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    width: 5px;
    height: 100%;
    background: var(--att-blue);
}

.stat-grid .stat-card:nth-child(1)::before {
    background: var(--att-green);
}

.stat-grid .stat-card:nth-child(2)::before {
    background: var(--att-gold);
}

.stat-grid .stat-card:nth-child(3)::before {
    background: var(--att-blue);
}

.stat-grid .stat-card:nth-child(4)::before {
    background: #7857d9;
}

.stat-grid .stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 14px 32px rgba(16, 36, 58, .09);
}

.stat-grid .stat-card > span {
    color: var(--att-muted);
    font-size: 13px;
    font-weight: 650;
}

.stat-grid .stat-card > strong {
    display: block;
    color: var(--att-text);
    font-size: 36px;
    font-weight: 850;
    margin: 12px 0;
    letter-spacing: -1px;
}

.stat-grid .stat-card > em {
    color: #91a0b1;
    font-size: 12px;
    font-style: normal;
}

/* Main panels */

.panel {
    border: 1px solid var(--att-border);
    border-radius: 21px;
    background: var(--att-surface);
    box-shadow: 0 8px 30px rgba(16, 36, 58, .045);
    overflow: hidden;
    margin-bottom: 22px;
}

.panel-head {
    padding: 23px 26px;
    border-bottom: 1px solid var(--att-border);
    background: linear-gradient(120deg, #ffffff, #f8faff);
}

.panel-head h2 {
    color: var(--att-text);
    font-size: 20px;
    font-weight: 800;
    margin: 0 0 7px;
}

.panel-head p {
    color: var(--att-muted);
    font-size: 13px;
    margin: 0;
}

/* QR check-in panel */

.panel:has(input[name="token"]) {
    border-top: 4px solid var(--att-gold);
    background:
        radial-gradient(
            circle at 100% 0%,
            rgba(200, 164, 93, .09),
            transparent 35%
        ),
        #ffffff;
}

.panel:has(input[name="token"]) .panel-head {
    padding-top: 25px;
    background: transparent;
}

.panel:has(input[name="token"]) .filter-form {
    padding: 22px 24px;
    display: flex;
    align-items: stretch;
    gap: 12px;
}

.panel:has(input[name="token"]) input[name="token"] {
    min-width: 0;
    flex: 1;
    min-height: 58px;
    padding: 15px 18px;
    border: 1px solid #d6e0ec;
    border-radius: 14px;
    background: #ffffff;
    color: var(--att-text);
    font-size: 15px;
    box-shadow: inset 0 1px 2px rgba(16, 36, 58, .025);
}

.panel:has(input[name="token"]) input[name="token"]:focus {
    outline: none;
    border-color: var(--att-blue);
    box-shadow: 0 0 0 4px rgba(36, 99, 235, .10);
}

.panel:has(input[name="token"]) .btn-primary {
    min-height: 58px;
    padding: 0 25px;
    border: 0;
    border-radius: 14px;
    background: var(--att-navy);
    color: #ffffff;
    font-weight: 800;
    box-shadow: 0 7px 17px rgba(16, 36, 58, .15);
    transition: background .2s, transform .2s;
}

.panel:has(input[name="token"]) .btn-primary:hover {
    background: var(--att-blue);
    transform: translateY(-1px);
}

/* QR note */

.qr-note {
    margin: 0 24px 22px;
    padding: 16px 18px;
    border: 1px solid #dce9fb;
    border-radius: 13px;
    background: var(--att-blue-light);
    color: #526c8c;
    font-size: 12px;
    line-height: 1.8;
}

.qr-note strong {
    color: #2457a6;
}

.qr-note code {
    padding: 3px 6px;
    border-radius: 5px;
    background: #dceaff;
    color: #214f9b;
}

/* Search & filter form */

.panel:has(select[name="event_id"]) .filter-form {
    padding: 22px 24px;
    display: grid;
    grid-template-columns: minmax(160px, 1.2fr) minmax(170px, 1.2fr) minmax(150px, 1fr) auto auto;
    gap: 12px;
    align-items: center;
}

.panel:has(select[name="event_id"]) .filter-form input,
.panel:has(select[name="event_id"]) .filter-form select {
    width: 100%;
    min-width: 0;
    min-height: 53px;
    box-sizing: border-box;
    border: 1px solid #dce4ee;
    border-radius: 12px;
    background: #ffffff;
    padding: 12px 14px;
    color: var(--att-text);
    font-size: 13px;
}

.panel:has(select[name="event_id"]) .filter-form input:focus,
.panel:has(select[name="event_id"]) .filter-form select:focus {
    outline: none;
    border-color: var(--att-blue);
    box-shadow: 0 0 0 3px rgba(36, 99, 235, .09);
}

.panel:has(select[name="event_id"]) .filter-form .btn {
    min-height: 50px;
    border-radius: 12px;
    font-weight: 750;
    white-space: nowrap;
}

/* Event overview progress */

.panel:has(.table-wrap) .badge.neutral {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    border-radius: 8px;
    background: #edf3ff;
    color: #315da8;
    font-size: 11px;
    font-weight: 750;
}

/* Tables */

.table-wrap {
    overflow-x: auto;
    width: 100%;
}

.table-wrap table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.table-wrap thead th {
    padding: 17px 20px;
    background: #f5f8fc;
    border-bottom: 1px solid var(--att-border);
    color: #6c7c90;
    font-size: 11px;
    font-weight: 850;
    letter-spacing: .6px;
    text-transform: uppercase;
    white-space: nowrap;
}

.table-wrap tbody td {
    padding: 17px 20px;
    border-bottom: 1px solid #edf1f6;
    color: #506176;
    font-size: 13px;
    vertical-align: middle;
}

.table-wrap tbody tr {
    transition: background .15s ease;
}

.table-wrap tbody tr:hover {
    background: #f7faff;
}

.table-wrap tbody tr:last-child td {
    border-bottom: 0;
}

.table-wrap td strong {
    color: var(--att-text);
    font-weight: 750;
}

.table-wrap td small {
    display: block;
    margin-top: 5px;
    color: #91a0b1;
    font-size: 11px;
}

/* Status badges */

.badge.present {
    background: #e5f7ed;
    color: #14764b;
}

.badge.registered {
    background: #fff4dc;
    color: #98650b;
}

.badge.absent {
    background: #ffebed;
    color: #c33845;
}

/* Priority labels */

.priority {
    display: inline-flex;
    align-items: center;
    padding: 5px 9px;
    border-radius: 7px;
    background: #fff0ee;
    color: #c34c42;
    font-size: 11px;
    font-weight: 750;
}

/* Reset action */

.link-danger {
    padding: 7px 11px;
    border: 1px solid #f5d4d6;
    border-radius: 8px;
    background: #fff5f5;
    color: #cb3946;
    font-size: 12px;
    font-weight: 750;
    cursor: pointer;
    text-decoration: none;
}

.link-danger:hover {
    background: #ffe5e7;
}

/* Empty state */

.empty {
    padding: 40px 20px !important;
    text-align: center;
    color: #91a0b1 !important;
    font-size: 14px !important;
}

/* Responsive */

@media (max-width: 1100px) {
    .panel:has(select[name="event_id"]) .filter-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .panel:has(select[name="event_id"]) .filter-form input {
        grid-column: 1 / -1;
    }
}

@media (max-width: 650px) {
    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .stat-grid .stat-card {
        min-height: 130px;
        padding: 19px 16px;
        border-radius: 15px;
    }

    .stat-grid .stat-card > strong {
        font-size: 29px;
    }

    .panel-head {
        padding: 19px;
    }

    .panel:has(input[name="token"]) .filter-form {
        flex-direction: column;
        padding: 17px;
    }

    .panel:has(input[name="token"]) .btn-primary {
        width: 100%;
    }

    .qr-note {
        margin: 0 17px 17px;
    }

    .panel:has(select[name="event_id"]) .filter-form {
        grid-template-columns: 1fr;
        padding: 17px;
    }

    .panel:has(select[name="event_id"]) .filter-form input {
        grid-column: auto;
    }

    .table-wrap thead th,
    .table-wrap tbody td {
        padding: 13px 14px;
    }
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>