<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_login();

if (!can_approve_events()) {
    flash('error', 'Access restricted: Only Event Director has final approval authority.');
    redirect(BASE_URL . '/dashboard.php');
}

$page_title = 'Approvals | BUP Protocol';

// =====================================================
// HANDLE APPROVAL ACTION (MongoDB Atlas)
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id = (int)($_POST['id'] ?? 0);
    $decision = trim($_POST['decision'] ?? '');

    $allowedDecisions = [
        'Approved',
        'Rejected',
        'Confirmed'
    ];

    if (!$id || !in_array($decision, $allowedDecisions, true)) {
        flash('error', 'Invalid approval request.');
        redirect(BASE_URL . '/approvals.php');
    }

    // Get current event
    $eventDoc = mcoll('events')->findOne(['id' => $id]);

    if (!$eventDoc) {
        flash('error', 'Event not found.');
        redirect(BASE_URL . '/approvals.php');
    }
    $event = mongo_doc_to_array($eventDoc);

    $oldStatus = $event['approval_status'] ?? 'Draft';

    // Do not repeat same decision
    if ($oldStatus === $decision) {
        flash('error', 'This event is already marked as ' . $decision . '.');
        redirect(BASE_URL . '/approvals.php');
    }

    try {
        $now = date('Y-m-d H:i:s');

        // Update approval status
        mcoll('events')->updateOne(
            ['id' => $id],
            ['$set' => [
                'approval_status' => $decision,
                'approved_by' => current_user()['id'],
                'approved_at' => $now
            ]]
        );

        // Audit log
        $description =
            'Event "' .
            ($event['name'] ?? '') .
            '" approval changed from ' .
            $oldStatus .
            ' to ' .
            $decision .
            '.';

        mongo_audit_log(
            current_user()['id'],
            'Approval ' . $decision,
            $description
        );

        flash('success', 'Approval status updated to ' . $decision . '.');

    } catch (Throwable $e) {
        flash('error', 'Unable to update approval status.');
    }

    redirect(BASE_URL . '/approvals.php');
}

// =====================================================
// FILTERS
// =====================================================

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter = trim($_GET['type'] ?? '');

// =====================================================
// EVENT TYPES (MongoDB Atlas)
// =====================================================

$rawTypes = mcoll('events')->distinct('event_type');
$types = [];
foreach ($rawTypes as $t) {
    if (!empty($t)) {
        $types[] = (string)$t;
    }
}
sort($types);

// =====================================================
// APPROVAL STATISTICS (MongoDB Atlas)
// =====================================================

$stats = [
    'total' => mcoll('events')->countDocuments([]),
    'draft' => mcoll('events')->countDocuments(['approval_status' => 'Draft']),
    'pending' => mcoll('events')->countDocuments(['approval_status' => 'Pending']),
    'approved' => mcoll('events')->countDocuments(['approval_status' => 'Approved']),
    'rejected' => mcoll('events')->countDocuments(['approval_status' => 'Rejected']),
    'confirmed' => mcoll('events')->countDocuments(['approval_status' => 'Confirmed'])
];

// =====================================================
// BUILD EVENT QUERY (MongoDB Atlas Aggregation Pipeline)
// =====================================================

$pipeline = [];

$pipeline[] = [
    '$lookup' => [
        'from' => 'users',
        'localField' => 'approved_by',
        'foreignField' => 'id',
        'as' => 'approver_docs'
    ]
];

$pipeline[] = [
    '$addFields' => [
        'approver' => ['$ifNull' => [['$arrayElemAt' => ['$approver_docs.name', 0]], null]],
        'status_prio' => [
            '$switch' => [
                'branches' => [
                    ['case' => ['$eq' => ['$approval_status', 'Pending']], 'then' => 1],
                    ['case' => ['$eq' => ['$approval_status', 'Draft']], 'then' => 2],
                    ['case' => ['$eq' => ['$approval_status', 'Rejected']], 'then' => 3],
                    ['case' => ['$eq' => ['$approval_status', 'Approved']], 'then' => 4],
                    ['case' => ['$eq' => ['$approval_status', 'Confirmed']], 'then' => 5],
                ],
                'default' => 6
            ]
        ]
    ]
];

$pipeline[] = [
    '$project' => ['approver_docs' => 0]
];

$match = [];

if ($search !== '') {
    $regex = new MongoDB\BSON\Regex($search, 'i');
    $match['$or'] = [
        ['name' => $regex],
        ['event_type' => $regex],
        ['venue' => $regex]
    ];
}

if ($statusFilter !== '') {
    $match['approval_status'] = $statusFilter;
}

if ($typeFilter !== '') {
    $match['event_type'] = $typeFilter;
}

if (!empty($match)) {
    $pipeline[] = ['$match' => $match];
}

$pipeline[] = [
    '$sort' => [
        'status_prio' => 1,
        'event_date' => 1,
        'event_time' => 1,
        'id' => -1
    ]
];

$cursor = mcoll('events')->aggregate($pipeline);
$events = [];
foreach ($cursor as $doc) {
    $events[] = mongo_doc_to_array($doc);
}


// =====================================================
// HEADER
// =====================================================

require __DIR__ . '/includes/header.php';

?>


<div class="page-head">

    <div>

        <span class="eyebrow">
            EVENT WORKFLOW
        </span>

        <h1>
            Approvals
        </h1>

        <p>
            Review events and move them through approval and confirmation stages.
        </p>

    </div>

</div>


<!-- =====================================================
     APPROVAL STATISTICS
====================================================== -->

<div class="stat-grid approval-stats">

    <div class="stat-card">

        <span>Total Events</span>

        <strong>
            <?= $stats['total'] ?>
        </strong>

        <em>
            All workflow records
        </em>

    </div>


    <div class="stat-card">

        <span>Pending Review</span>

        <strong>
            <?= $stats['pending'] ?>
        </strong>

        <em>
            Awaiting approval
        </em>

    </div>


    <div class="stat-card">

        <span>Approved</span>

        <strong>
            <?= $stats['approved'] ?>
        </strong>

        <em>
            Approval completed
        </em>

    </div>


    <div class="stat-card">

        <span>Rejected</span>

        <strong>
            <?= $stats['rejected'] ?>
        </strong>

        <em>
            Requires review
        </em>

    </div>


    <div class="stat-card">

        <span>Confirmed</span>

        <strong>
            <?= $stats['confirmed'] ?>
        </strong>

        <em>
            Final workflow stage
        </em>

    </div>

</div>


<!-- =====================================================
     WORKFLOW OVERVIEW
====================================================== -->

<section class="panel approval-flow-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                APPROVAL WORKFLOW
            </span>

            <h2>
                Event Approval Pipeline
            </h2>

        </div>

    </div>


    <div class="approval-flow">

        <div class="approval-step">

            <span class="approval-number">
                01
            </span>

            <strong>
                Draft
            </strong>

            <small>
                Event preparation
            </small>

        </div>


        <div class="approval-arrow">
            →
        </div>


        <div class="approval-step">

            <span class="approval-number">
                02
            </span>

            <strong>
                Pending
            </strong>

            <small>
                Awaiting review
            </small>

        </div>


        <div class="approval-arrow">
            →
        </div>


        <div class="approval-step">

            <span class="approval-number">
                03
            </span>

            <strong>
                Approved
            </strong>

            <small>
                Event approved
            </small>

        </div>


        <div class="approval-arrow">
            →
        </div>


        <div class="approval-step">

            <span class="approval-number">
                04
            </span>

            <strong>
                Confirmed
            </strong>

            <small>
                Ready for execution
            </small>

        </div>

    </div>

</section>


<!-- =====================================================
     FILTERS
====================================================== -->

<section class="panel approval-filter-panel">

    <div class="panel-head">

        <div>

            <h2>
                Search & Filter
            </h2>

            <p>
                Find events by name, type or approval status.
            </p>

        </div>

    </div>


    <form
        method="get"
        class="filter-form approval-filter-form"
    >

        <input
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Search event, venue or type..."
        >


        <select name="status">

            <option value="">
                All Approval Status
            </option>

            <option
                value="Draft"
                <?= $statusFilter === 'Draft' ? 'selected' : '' ?>
            >
                Draft
            </option>

            <option
                value="Pending"
                <?= $statusFilter === 'Pending' ? 'selected' : '' ?>
            >
                Pending
            </option>

            <option
                value="Approved"
                <?= $statusFilter === 'Approved' ? 'selected' : '' ?>
            >
                Approved
            </option>

            <option
                value="Rejected"
                <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>
            >
                Rejected
            </option>

            <option
                value="Confirmed"
                <?= $statusFilter === 'Confirmed' ? 'selected' : '' ?>
            >
                Confirmed
            </option>

        </select>


        <select name="type">

            <option value="">
                All Event Types
            </option>

            <?php foreach ($types as $type): ?>

                <option
                    value="<?= e($type) ?>"
                    <?= $typeFilter === $type ? 'selected' : '' ?>
                >
                    <?= e($type) ?>
                </option>

            <?php endforeach; ?>

        </select>


        <button class="btn btn-primary">
            Apply Filter
        </button>


        <?php if ($search || $statusFilter || $typeFilter): ?>

            <a
                href="<?= BASE_URL ?>/approvals.php"
                class="btn btn-light"
            >
                Reset
            </a>

        <?php endif; ?>

    </form>

</section>


<!-- =====================================================
     APPROVAL REGISTER
====================================================== -->

<section class="panel approval-register">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                APPROVAL REGISTER
            </span>

            <h2>
                Event Review Queue
            </h2>

        </div>

        <span class="record-count">
            <?= count($events) ?> record<?= count($events) !== 1 ? 's' : '' ?>
        </span>

    </div>


    <?php if ($events): ?>

        <div class="table-wrap">

            <table>

                <thead>

                    <tr>

                        <th>
                            Event
                        </th>

                        <th>
                            Schedule
                        </th>

                        <th>
                            Priority
                        </th>

                        <th>
                            Approval
                        </th>

                        <th>
                            Approved By
                        </th>

                        <th>
                            Approval Time
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>

                    <?php foreach ($events as $event): ?>

                        <tr>

                            <!-- EVENT -->

                            <td>

                                <strong>
                                    <?= e($event['name']) ?>
                                </strong>

                                <small>
                                    <?= e($event['event_type']) ?>
                                    ·
                                    <?= e($event['venue']) ?>
                                </small>

                            </td>


                            <!-- SCHEDULE -->

                            <td>

                                <?= e(
                                    date(
                                        'd M Y',
                                        strtotime($event['event_date'])
                                    )
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


                            <!-- PRIORITY -->

                            <td>

                                <?php if ((int)$event['priority'] === 1): ?>

                                    <span class="priority">
                                        High Priority
                                    </span>

                                <?php else: ?>

                                    <span class="muted">
                                        Normal
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <?php

                                $statusClass =
                                    strtolower(
                                        $event['approval_status']
                                    );

                                ?>

                                <span
                                    class="badge approval-<?= e($statusClass) ?>"
                                >
                                    <?= e($event['approval_status']) ?>
                                </span>

                            </td>


                            <!-- APPROVER -->

                            <td>

                                <?php if (!empty($event['approver'])): ?>

                                    <strong>
                                        <?= e($event['approver']) ?>
                                    </strong>

                                <?php else: ?>

                                    <span class="muted">
                                        Not reviewed
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- APPROVAL TIME -->

                            <td>

                                <?php if (!empty($event['approved_at'])): ?>

                                    <?= e(
                                        date(
                                            'd M Y',
                                            strtotime(
                                                $event['approved_at']
                                            )
                                        )
                                    ) ?>

                                    <small>
                                        <?= e(
                                            date(
                                                'h:i A',
                                                strtotime(
                                                    $event['approved_at']
                                                )
                                            )
                                        ) ?>
                                    </small>

                                <?php else: ?>

                                    <span class="muted">
                                        —
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <div class="approval-actions">

                                    <?php if ($event['approval_status'] !== 'Approved'): ?>

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Approve this event?')"
                                        >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$event['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="decision"
                                                value="Approved"
                                                class="btn btn-sm btn-primary"
                                            >
                                                Approve
                                            </button>

                                        </form>

                                    <?php endif; ?>


                                    <?php if ($event['approval_status'] !== 'Rejected'): ?>

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Reject this event?')"
                                        >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$event['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="decision"
                                                value="Rejected"
                                                class="btn btn-sm btn-danger"
                                            >
                                                Reject
                                            </button>

                                        </form>

                                    <?php endif; ?>


                                    <?php if (
                                        $event['approval_status'] === 'Approved'
                                        &&
                                        $event['approval_status'] !== 'Confirmed'
                                    ): ?>

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Confirm this approved event?')"
                                        >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$event['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="decision"
                                                value="Confirmed"
                                                class="btn btn-sm btn-dark"
                                            >
                                                Confirm
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="empty">

            <strong>
                No approval records found
            </strong>

            <p>
                Try changing the search or filter criteria.
            </p>

            <a
                href="<?= BASE_URL ?>/approvals.php"
                class="btn btn-light"
            >
                Clear Filters
            </a>

        </div>

    <?php endif; ?>

</section>


<style>

/* =========================================
   APPROVAL WORKFLOW
========================================= */

.approval-flow-panel {
    margin-top: 18px;
}

.approval-flow {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    flex-wrap: wrap;
}

.approval-step {
    min-width: 145px;
    padding: 17px;
    text-align: center;
    border: 1px solid rgba(148,163,184,.15);
    border-radius: 14px;
    background: rgba(148,163,184,.04);
}

.approval-step strong {
    display: block;
    margin-top: 6px;
}

.approval-step small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
}

.approval-number {
    display: inline-grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: rgba(201,163,76,.13);
    color: var(--gold);
    font-size: 11px;
    font-weight: 900;
}

.approval-arrow {
    color: var(--muted);
    font-size: 22px;
}


/* =========================================
   FILTER
========================================= */

.approval-filter-panel {
    margin-top: 18px;
}

.approval-filter-form {
    align-items: center;
}

.approval-filter-form input,
.approval-filter-form select {
    min-width: 180px;
}


/* =========================================
   REGISTER
========================================= */

.approval-register {
    margin-top: 18px;
}

.record-count {
    font-size: 12px;
    color: var(--muted);
}

.approval-register table small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
}

.approval-actions {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.approval-actions form {
    margin: 0;
}


/* =========================================
   APPROVAL BADGES
========================================= */

.approval-pending {
    background: rgba(234,179,8,.13);
    color: #ca8a04;
}

.approval-approved {
    background: rgba(34,197,94,.13);
    color: #16a34a;
}

.approval-rejected {
    background: rgba(239,68,68,.13);
    color: #dc2626;
}

.approval-confirmed {
    background: rgba(37,99,235,.13);
    color: #2563eb;
}

.approval-draft {
    background: rgba(148,163,184,.13);
    color: var(--muted);
}


/* =========================================
   RESPONSIVE
========================================= */

@media (max-width: 1000px) {

    .approval-flow {
        justify-content: flex-start;
    }

    .approval-arrow {
        display: none;
    }

}

@media (max-width: 720px) {

    .approval-flow {
        display: grid;
        grid-template-columns: 1fr;
    }

    .approval-step {
        width: 100%;
    }

    .approval-filter-form {
        align-items: stretch;
    }

    .approval-filter-form input,
    .approval-filter-form select {
        width: 100%;
        min-width: 0;
    }

    .approval-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .approval-actions .btn {
        width: 100%;
    }

}






/* ==========================================
   APPROVALS — LAYOUT & SPACING OVERRIDES
========================================== */

/* Summary cards: 5 cards in one balanced row */
.stat-grid.approval-stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 30px;
}

.stat-grid.approval-stats .stat-card {
    min-width: 0;
    margin: 0;
    padding: 24px;
    box-sizing: border-box;
}

/* Space between main sections */
.approval-flow-panel,
.approval-filter-panel,
.approval-register {
    margin-top: 24px;
    margin-bottom: 24px;
    border-radius: 20px;
    overflow: hidden;
}

/* Workflow steps */
.approval-flow {
    padding: 24px;
    gap: 16px;
}

.approval-step {
    flex: 1 1 150px;
    max-width: 230px;
    min-width: 0;
    min-height: 150px;
    padding: 22px 16px;
    box-sizing: border-box;
}

/* Filter form */
.approval-filter-form {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.2fr)
        repeat(2, minmax(150px, 1fr))
        auto auto;
    gap: 12px;
    padding: 18px 22px;
}

.approval-filter-form input,
.approval-filter-form select {
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    min-height: 54px;
}

.approval-filter-form .btn {
    min-height: 54px;
    white-space: nowrap;
}

/* Register table */
.approval-register .table-wrap {
    width: 100%;
    overflow-x: auto;
}

.approval-register table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.approval-register th,
.approval-register td {
    padding: 16px 20px;
    vertical-align: middle;
    line-height: 1.5;
}

/* Tablet */
@media (max-width: 1100px) {
    .stat-grid.approval-stats {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .approval-filter-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* Mobile */
@media (max-width: 720px) {
    .stat-grid.approval-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 22px;
    }

    .stat-grid.approval-stats .stat-card {
        padding: 18px;
    }

    .approval-flow-panel,
    .approval-filter-panel,
    .approval-register {
        margin-top: 18px;
        margin-bottom: 18px;
    }

    .approval-flow {
        padding: 16px;
    }

    .approval-step {
        width: 100%;
        max-width: none;
    }

    .approval-filter-form {
        grid-template-columns: 1fr;
        padding: 16px;
    }

    .approval-filter-form input,
    .approval-filter-form select,
    .approval-filter-form .btn {
        width: 100%;
    }

    .approval-register table {
        min-width: 1000px;
    }
}

</style>


<?php require __DIR__ . '/includes/footer.php'; ?>