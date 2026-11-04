<?php
require_once __DIR__ . '/config/auth.php';

require_login();

$pdo = db();
$page_title = 'Events | BUP Protocol';


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | CREATE EVENT
    |--------------------------------------------------------------------------
    */

    if ($action === 'create') {

        if (!can_create_events()) {
            flash('error', 'Only Event Director or authorized Administrator can create new events.');
            redirect(BASE_URL . '/events.php');
        }

        $name = trim($_POST['name'] ?? '');
        $event_type = trim($_POST['event_type'] ?? '');
        $mode = in_array($_POST['mode'] ?? '', ['Online', 'Offline', 'Hybrid'], true) ? $_POST['mode'] : 'Offline';
        $meeting_link = trim($_POST['meeting_link'] ?? '');
        $event_date = $_POST['event_date'] ?? '';
        $event_time = $_POST['event_time'] ?? '';
        $venue = ($mode === 'Online') ? 'Online Virtual Hall' : trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status_value = $_POST['status'] ?? 'Upcoming';
        $priority = isset($_POST['priority']) ? 1 : 0;
        $budget_total = max(0, (float)($_POST['budget_total'] ?? 0));
        $event_coordinator_id = !empty($_POST['event_coordinator_id']) ? (int)$_POST['event_coordinator_id'] : null;
        $venue_lead_id = !empty($_POST['venue_lead_id']) ? (int)$_POST['venue_lead_id'] : null;
        $backup_person_id = !empty($_POST['backup_person_id']) ? (int)$_POST['backup_person_id'] : null;

        if (
            $name === '' ||
            $event_type === '' ||
            $event_date === '' ||
            $event_time === '' ||
            ($mode !== 'Online' && $venue === '')
        ) {
            flash('error', 'Please complete all required event information.');
            redirect(BASE_URL . '/events.php#create-event');
        }

        $stmt = $pdo->prepare(
            "INSERT INTO events
            (
                name,
                event_type,
                mode,
                meeting_link,
                event_date,
                event_time,
                venue,
                description,
                status,
                priority,
                budget_total,
                event_director_id,
                event_coordinator_id,
                venue_lead_id,
                backup_person_id,
                created_by
            )
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $stmt->execute([
            $name,
            $event_type,
            $mode,
            $meeting_link,
            $event_date,
            $event_time,
            $venue,
            $description,
            $status_value ?: 'Upcoming',
            $priority,
            $budget_total,
            current_user()['id'],
            $event_coordinator_id,
            $venue_lead_id,
            $backup_person_id,
            current_user()['id']
        ]);

        $id = $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO audit_logs
            (
                user_id,
                action,
                description
            )
            VALUES (?,?,?)"
        )->execute([
            current_user()['id'],
            'Event Created',
            'Created event #' . $id . ' — ' . $name . ' (' . $mode . ')'
        ]);

        if (function_exists('add_user_notification')) {
            $studentRows = $pdo->query("SELECT id FROM users WHERE role = 'Student' AND status = 'Active'")->fetchAll();
            foreach ($studentRows as $sr) {
                add_user_notification(
                    (int)$sr['id'],
                    'New University Event: ' . $name,
                    'A new official ceremony "' . $name . '" has been published for ' . date('d M Y', strtotime($event_date)) . ' at ' . $venue . '.',
                    'event',
                    BASE_URL . '/student_dashboard.php?tab=events'
                );
            }
        }

        // Handle conversion from military event directive
        $from_request_id = (int)($_POST['from_request_id'] ?? 0);
        if ($from_request_id > 0) {
            $pdo->prepare("UPDATE military_event_requests SET status = 'Converted to Event', converted_event_id = ? WHERE id = ?")
                ->execute([$id, $from_request_id]);

            $reqStmt = $pdo->prepare("SELECT * FROM military_event_requests WHERE id = ?");
            $reqStmt->execute([$from_request_id]);
            $req = $reqStmt->fetch();

            if ($req && function_exists('add_user_notification')) {
                // Notify Military Requester
                add_user_notification(
                    (int)$req['requested_by'],
                    'Military Directive Converted: ' . $req['request_id'],
                    'Civil Event Director approved and converted directive "' . $req['title'] . '" into Event #' . $id . '.',
                    'event',
                    BASE_URL . '/event_view.php?id=' . $id
                );

                // Notify Civil Event Coordinator (L2)
                $coordinators = $pdo->query("SELECT id FROM users WHERE (level = 'L2' OR role = 'Event Coordinator') AND status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($coordinators as $cid) {
                    add_user_notification(
                        (int)$cid,
                        'New Event from Military Directive: ' . $name,
                        'Event #' . $id . ' was created from Military Directive ' . $req['request_id'] . '. Begin operational coordination.',
                        'duty_assigned',
                        BASE_URL . '/event_view.php?id=' . $id
                    );
                }
            }

            if (function_exists('mongo_sync_table')) {
                mongo_sync_table('military_event_requests');
            }
        }

        flash('success', 'Event created successfully with mode: ' . $mode . '.');
        redirect(BASE_URL . '/events.php');
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE EVENT
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        if (!can_create_events() && !is_event_coordinator()) {
            flash('error', 'You do not have permission to modify event settings.');
            redirect(BASE_URL . '/events.php');
        }

        $id = (int) ($_POST['id'] ?? 0);

        $name = trim($_POST['name'] ?? '');
        $event_type = trim($_POST['event_type'] ?? '');
        $mode = in_array($_POST['mode'] ?? '', ['Online', 'Offline', 'Hybrid'], true) ? $_POST['mode'] : 'Offline';
        $meeting_link = trim($_POST['meeting_link'] ?? '');
        $event_date = $_POST['event_date'] ?? '';
        $event_time = $_POST['event_time'] ?? '';
        $venue = ($mode === 'Online') ? 'Online Virtual Hall' : trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status_value = $_POST['status'] ?? 'Upcoming';
        $priority = isset($_POST['priority']) ? 1 : 0;
        $budget_total = max(0, (float)($_POST['budget_total'] ?? 0));
        $event_coordinator_id = !empty($_POST['event_coordinator_id']) ? (int)$_POST['event_coordinator_id'] : null;
        $venue_lead_id = !empty($_POST['venue_lead_id']) ? (int)$_POST['venue_lead_id'] : null;
        $backup_person_id = !empty($_POST['backup_person_id']) ? (int)$_POST['backup_person_id'] : null;

        if (
            $id <= 0 ||
            $name === '' ||
            $event_type === '' ||
            $event_date === '' ||
            $event_time === '' ||
            ($mode !== 'Online' && $venue === '')
        ) {
            flash('error', 'Please complete all required event information.');
            redirect(BASE_URL . '/events.php?edit=' . $id . '#edit-event');
        }

        $check = $pdo->prepare("SELECT id FROM events WHERE id = ?");
        $check->execute([$id]);

        if (!$check->fetchColumn()) {
            flash('error', 'Event not found.');
            redirect(BASE_URL . '/events.php');
        }

        $stmt = $pdo->prepare(
            "UPDATE events
             SET
                name = ?,
                event_type = ?,
                mode = ?,
                meeting_link = ?,
                event_date = ?,
                event_time = ?,
                venue = ?,
                description = ?,
                status = ?,
                priority = ?,
                budget_total = ?,
                event_coordinator_id = ?,
                venue_lead_id = ?,
                backup_person_id = ?
             WHERE id = ?"
        );

        $stmt->execute([
            $name,
            $event_type,
            $mode,
            $meeting_link,
            $event_date,
            $event_time,
            $venue,
            $description,
            $status_value,
            $priority,
            $budget_total,
            $event_coordinator_id,
            $venue_lead_id,
            $backup_person_id,
            $id
        ]);

        $pdo->prepare(
            "INSERT INTO audit_logs(user_id, action, description)
            VALUES (?,?,?)"
        )->execute([
            current_user()['id'],
            'Event Updated',
            'Updated event #' . $id . ' — ' . $name
        ]);

        flash('success', 'Event updated successfully.');
        redirect(BASE_URL . '/events.php');
    }

    /*
    |--------------------------------------------------------------------------
    | CANCEL EVENT
    |--------------------------------------------------------------------------
    */

    if ($action === 'cancel') {

        if (!can_create_events()) {
            flash('error', 'Only Event Director or Administrator can cancel events.');
            redirect(BASE_URL . '/events.php');
        }

        $id = (int) ($_POST['id'] ?? 0);

        if ($id > 0) {
            $event_stmt = $pdo->prepare("SELECT name FROM events WHERE id = ?");
            $event_stmt->execute([$id]);
            $event_name = $event_stmt->fetchColumn();

            if ($event_name) {
                $pdo->prepare("UPDATE events SET status = 'Cancelled' WHERE id = ?")->execute([$id]);


                $pdo->prepare(
                    "INSERT INTO audit_logs
                    (
                        user_id,
                        action,
                        description
                    )
                    VALUES (?,?,?)"
                )->execute([
                    current_user()['id'],
                    'Event Cancelled',
                    'Cancelled event #' . $id . ' — ' . $event_name
                ]);


                flash(
                    'success',
                    'Event cancelled successfully.'
                );
            }
        }

        redirect(BASE_URL . '/events.php');
    }
}


/*
|--------------------------------------------------------------------------
| SEARCH & FILTER
|--------------------------------------------------------------------------
*/

$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? '');
$type = trim($_GET['type'] ?? '');


$sql = "
    SELECT
        e.*,
        u.name AS creator
    FROM events e
    LEFT JOIN users u
        ON u.id = e.created_by
    WHERE 1
";


$params = [];


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

if ($q !== '') {

    $sql .= "
        AND (
            e.name LIKE ?
            OR e.venue LIKE ?
            OR e.description LIKE ?
        )
    ";

    $search = "%{$q}%";

    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}


/*
|--------------------------------------------------------------------------
| Status filter
|--------------------------------------------------------------------------
*/

if ($status !== '') {

    $sql .= " AND e.status = ?";

    $params[] = $status;
}


/*
|--------------------------------------------------------------------------
| Type filter
|--------------------------------------------------------------------------
*/

if ($type !== '') {

    $sql .= " AND e.event_type = ?";

    $params[] = $type;
}


$sql .= "
    ORDER BY
        CASE
            WHEN e.status = 'Ongoing' THEN 1
            WHEN e.status = 'Upcoming' THEN 2
            WHEN e.status = 'Completed' THEN 3
            WHEN e.status = 'Cancelled' THEN 4
            ELSE 5
        END,
        e.event_date ASC,
        e.event_time ASC
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$events = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| EDIT EVENT
|--------------------------------------------------------------------------
*/

$edit_event = null;

$from_req_param = trim($_GET['from_request'] ?? '');
$mil_req = null;
if ($from_req_param !== '') {
    $rqStmt = $pdo->prepare("SELECT * FROM military_event_requests WHERE request_id = ? OR id = ?");
    $rqStmt->execute([$from_req_param, $from_req_param]);
    $mil_req = $rqStmt->fetch();
}

$edit_id = (int) ($_GET['edit'] ?? 0);


if ($edit_id > 0) {

    $stmt = $pdo->prepare(
        "SELECT *
         FROM events
         WHERE id = ?"
    );

    $stmt->execute([$edit_id]);

    $edit_event = $stmt->fetch();


    if (!$edit_event) {

        flash(
            'error',
            'The selected event could not be found.'
        );

        redirect(BASE_URL . '/events.php');
    }
}
$available_venues = [
    'Shadhinota Auditorium 1st floor',
    'Shadhinota Auditorium 2nd floor',
    'Bijoy Auditorium',
    'Main Hall',
    'Conference Room',
    'BUP Grand Auditorium',
    'Seminar Hall A',
    'VIP Protocol Lounge'
];

$coordinators = $pdo->query("SELECT id, name, designation, role FROM users WHERE (role IN ('Event Coordinator','Admin') OR level = 'L2') AND status = 'Active' ORDER BY name")->fetchAll();
$venue_leads = $pdo->query("SELECT id, name, designation, department FROM users WHERE (department IN ('Venue','Protocol','Logistics') OR level = 'L3') AND status = 'Active' ORDER BY name")->fetchAll();
$eligible_backups = $pdo->query("SELECT id, name, designation, role, department FROM users WHERE status = 'Active' ORDER BY name")->fetchAll();

require __DIR__ . '/includes/header.php';
?>


<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="page-head">

    <div>

        <span class="eyebrow">
            EVENT MANAGEMENT
        </span>

        <h1>
            Events
        </h1>

        <p>
            Create, monitor, update and cancel academic,
            official and military events.
        </p>

    </div>


    <div
        style="
            display:flex;
            gap:9px;
            flex-wrap:wrap;
        "
    >

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/calendar.php"
        >
            Event Calendar
        </a>

        <?php if (can_create_events()): ?>
        <a
            class="btn btn-primary"
            href="#create-event"
        >
            + Create Event
        </a>
        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     FILTER BAR
========================================================= -->

<div class="filter-bar">

    <form
        method="get"
        class="filter-form"
    >

        <input
            name="q"
            value="<?= e($q) ?>"
            placeholder="Search event, venue or description..."
        >


        <select name="type">

            <option value="">
                All types
            </option>

            <?php
            foreach (
                [
                    'Academic',
                    'Official',
                    'Military',
                    'Ceremonial',
                    'Seminar',
                    'Other'
                ] as $t
            ):
            ?>

                <option
                    value="<?= e($t) ?>"
                    <?= $type === $t ? 'selected' : '' ?>
                >
                    <?= e($t) ?>
                </option>

            <?php endforeach; ?>

        </select>


        <select name="status">

            <option value="">
                All status
            </option>

            <?php
            foreach (
                [
                    'Upcoming',
                    'Ongoing',
                    'Completed',
                    'Cancelled'
                ] as $s
            ):
            ?>

                <option
                    value="<?= e($s) ?>"
                    <?= $status === $s ? 'selected' : '' ?>
                >
                    <?= e($s) ?>
                </option>

            <?php endforeach; ?>

        </select>


        <button
            class="btn btn-dark"
            type="submit"
        >
            Filter
        </button>


        <?php if ($q !== '' || $type !== '' || $status !== ''): ?>

            <a
                class="btn btn-light"
                href="<?= BASE_URL ?>/events.php"
            >
                Reset
            </a>

        <?php endif; ?>

    </form>

</div>


<!-- =========================================================
     EVENT REGISTER
========================================================= -->

<section class="panel">

    <div class="panel-head">

        <div>

            <h2>
                Event Register
            </h2>

            <p>
                <?= count($events) ?>
                record<?= count($events) === 1 ? '' : 's' ?>
                found
            </p>

        </div>

    </div>


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
                        Venue
                    </th>

                    <th>
                        Priority
                    </th>

                    <th>
                        Status
                    </th>

                    <th>
                        Action
                    </th>

                </tr>

            </thead>


            <tbody>

            <?php foreach ($events as $event): ?>

                <tr>

                    <!-- Event -->

                    <td>

                        <strong>
                            <?= e($event['name']) ?>
                        </strong>

                        <small>
                            <?= e($event['event_type']) ?>
                        </small>

                    </td>


                    <!-- Schedule -->

                    <td>

                        <?= e($event['event_date']) ?>

                        <br>

                        <small>
                            <?= e(substr($event['event_time'], 0, 5)) ?>
                        </small>

                    </td>


                    <!-- Venue & Mode -->

                    <td>
                        <strong><?= e($event['venue']) ?></strong>
                        <?php
                        $m = $event['mode'] ?? 'Offline';
                        $mColors = ['Online' => '#0284c7', 'Offline' => '#059669', 'Hybrid' => '#7c3aed'];
                        ?>
                        <div style="margin-top:3px;">
                            <span class="badge" style="background:<?= $mColors[$m] ?? '#475569' ?>; color:#fff; font-size:10px; padding:2px 6px;"><?= e($m) ?></span>
                            <?php if (!empty($event['meeting_link']) && $m !== 'Offline'): ?>
                                <a href="<?= e($event['meeting_link']) ?>" target="_blank" style="font-size:11px; color:#3b82f6; margin-left:4px;">Join Link &nearr;</a>
                            <?php endif; ?>
                        </div>
                    </td>


                    <!-- Priority -->

                    <td>

                        <?php if ($event['priority']): ?>

                            <span class="priority">
                                High Priority
                            </span>

                        <?php else: ?>

                            <span
                                style="
                                    color:#8b96a4;
                                    font-size:10px;
                                "
                            >
                                Normal
                            </span>

                        <?php endif; ?>

                    </td>


                    <!-- Status -->

                    <td>

                        <?php

                        $status_class = strtolower(
                            trim($event['status'])
                        );

                        if (
                            !in_array(
                                $status_class,
                                [
                                    'upcoming',
                                    'ongoing',
                                    'completed',
                                    'cancelled',
                                    'approved',
                                    'rejected'
                                ],
                                true
                            )
                        ) {
                            $status_class = 'neutral';
                        }

                        ?>

                        <span
                            class="badge <?= e($status_class) ?>"
                        >
                            <?= e($event['status']) ?>
                        </span>

                    </td>


                    <!-- Actions -->

                    <td>

                        <a
                            class="table-link"
                            href="event_view.php?id=<?= (int) $event['id'] ?>"
                        >
                            View
                        </a>


                        <?php if ($event['status'] !== 'Cancelled'): ?>

                            <?php if (can_create_events() || is_event_coordinator()): ?>
                            <a
                                class="table-link"
                                href="<?= BASE_URL ?>/events.php?edit=<?= (int) $event['id'] ?>#edit-event"
                            >
                                Edit
                            </a>
                            <?php endif; ?>


                            <?php if (can_create_events()): ?>
                            <form
                                class="inline-form"
                                method="post"
                            >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="cancel"
                                >

                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int) $event['id'] ?>"
                                >

                                <button
                                    type="submit"
                                    class="link-danger"
                                    onclick="return confirm('Cancel this event?')"
                                >
                                    Cancel
                                </button>

                            </form>
                            <?php endif; ?>

                        <?php endif; ?>

                    </td>

                </tr>

            <?php endforeach; ?>


            <?php if (!$events): ?>

                <tr>

                    <td
                        colspan="6"
                        class="empty"
                    >
                        No matching events found.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<!-- =========================================================
     EDIT EVENT
========================================================= -->

<?php if ($edit_event): ?>

<section
    id="edit-event"
    class="panel form-panel"
    style="margin-top:20px;"
>

    <div class="panel-head">

        <div>

            <h2>
                Edit Event
            </h2>

            <p>
                Update the selected event information.
            </p>

        </div>


        <a
            class="btn btn-light btn-sm"
            href="<?= BASE_URL ?>/events.php"
        >
            Close
        </a>

    </div>


    <form
        method="post"
        class="form-grid"
    >

        <input
            type="hidden"
            name="action"
            value="update"
        >

        <input
            type="hidden"
            name="id"
            value="<?= (int) $edit_event['id'] ?>"
        >

        <label>
            Event name
            <input
                name="name"
                required
                value="<?= e($edit_event['name']) ?>"
            >
        </label>

        <label>
            Event type
            <select name="event_type" required>
                <?php foreach (['Academic', 'Official', 'Military', 'Ceremonial', 'Seminar', 'Other'] as $t): ?>
                    <option value="<?= e($t) ?>" <?= $edit_event['event_type'] === $t ? 'selected' : '' ?>>
                        <?= e($t) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Event Mode / Status
            <select name="mode" id="edit_mode_select" onchange="toggleEventModeEdit(this.value)" required>
                <?php foreach (['Offline', 'Online', 'Hybrid'] as $m): ?>
                    <option value="<?= e($m) ?>" <?= ($edit_event['mode'] ?? 'Offline') === $m ? 'selected' : '' ?>>
                        <?= e($m) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label id="edit_meeting_link_wrap" style="<?= ($edit_event['mode'] ?? 'Offline') === 'Offline' ? 'display:none;' : '' ?>">
            Online Meeting / Stream Link
            <input
                type="url"
                name="meeting_link"
                placeholder="https://meet.google.com/... or Zoom link"
                value="<?= e($edit_event['meeting_link'] ?? '') ?>"
            >
        </label>

        <label>
            Date
            <input
                type="date"
                name="event_date"
                required
                value="<?= e($edit_event['event_date']) ?>"
            >
        </label>

        <label>
            Time
            <input
                type="time"
                name="event_time"
                required
                value="<?= e(substr($edit_event['event_time'], 0, 5)) ?>"
            >
        </label>

        <label id="edit_venue_wrap" class="span-2" style="<?= ($edit_event['mode'] ?? 'Offline') === 'Online' ? 'display:none;' : '' ?>">
            Venue (Auditorium / Hall)
            <select name="venue" id="edit_venue_select">
                <?php foreach ($available_venues as $v): ?>
                    <option value="<?= e($v) ?>" <?= ($edit_event['venue'] ?? '') === $v ? 'selected' : '' ?>>
                        <?= e($v) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Operational Command Level
            <select name="command_level">
                <option value="Event Coordinator" selected>Event Coordinator (L2)</option>
            </select>
            <small style="color:#94a3b8; font-size:11px;">Direct line of command from Event Director.</small>
        </label>

        <label>
            Assigned Event Coordinator
            <select name="event_coordinator_id">
                <option value="">-- Select Coordinator --</option>
                <?php foreach ($coordinators as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ((int)($edit_event['event_coordinator_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>>
                        <?= e($c['name']) ?> (<?= e($c['designation'] ?: $c['role']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Budget Planning (Estimated BDT)
            <input
                type="number"
                step="100"
                min="0"
                name="budget_total"
                value="<?= e((string)($edit_event['budget_total'] ?? 0)) ?>"
            >
        </label>

        <label>
            Status
            <select name="status">
                <?php foreach (['Upcoming', 'Ongoing', 'Completed', 'Cancelled'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $edit_event['status'] === $s ? 'selected' : '' ?>>
                        <?= e($s) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="span-2">
            Description
            <textarea name="description" rows="3"><?= e($edit_event['description']) ?></textarea>
        </label>

        <label class="checkbox-label span-2">
            <input
                type="checkbox"
                name="priority"
                <?= $edit_event['priority'] ? 'checked' : '' ?>
            >
            Mark as priority event
        </label>

        <div class="span-2" style="display:flex; gap:10px; margin-top:10px;">
            <button class="btn btn-primary" type="submit">
                Save Changes
            </button>
            <a class="btn btn-light" href="<?= BASE_URL ?>/events.php">
                Cancel
            </a>
        </div>

    </form>

</section>

<?php endif; ?>


<!-- =========================================================
     CREATE EVENT (RESTRICTED TO EVENT DIRECTOR & ADMIN)
========================================================= -->

<?php if (can_create_events()): ?>
<section
    id="create-event"
    class="panel form-panel"
    style="margin-top:20px;"
>

    <div class="panel-head">
        <div>
            <h2><?= $mil_req ? 'Create Event from Military Directive' : 'Create New Event' ?></h2>
            <p>Define event hierarchy, mode (Online/Offline/Hybrid), venue, coordinator and operational scope.</p>
        </div>
    </div>

    <?php if ($mil_req): ?>
        <div style="margin:16px 20px 0; background:linear-gradient(135deg, #051d14 0%, #082d21 100%); color:#ffffff; padding:16px 20px; border-radius:12px; border:2px solid #d4af37; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>
                <span style="background:#d4af37; color:#082d21; font-size:10px; font-weight:900; padding:2px 6px; border-radius:4px; font-family:monospace;">MILITARY DIRECTIVE AUTHORIZATION</span>
                <h3 style="margin:4px 0 2px; color:#ffffff; font-size:16px;">Converting Directive <?= e($mil_req['request_id']) ?>: <?= e($mil_req['title']) ?></h3>
                <p style="margin:0; font-size:12px; color:#cbd5e1;">Pre-populated from Armed Forces Higher Command directive. Upon creation, this event will be linked and status updated to 'Converted to Event'.</p>
            </div>
            <a href="<?= BASE_URL ?>/events.php" class="btn btn-sm btn-light" style="font-size:11px;">Clear Pre-fill</a>
        </div>
    <?php endif; ?>

    <form
        method="post"
        class="form-grid"
    >

        <input
            type="hidden"
            name="action"
            value="create"
        >

        <?php if ($mil_req): ?>
            <input type="hidden" name="from_request_id" value="<?= (int)$mil_req['id'] ?>">
        <?php endif; ?>

        <label>
            Event name
            <input
                name="name"
                required
                value="<?= e($mil_req['title'] ?? '') ?>"
                placeholder="e.g. BUP Annual Convocation"
            >
        </label>

        <label>
            Event type
            <select name="event_type" required>
                <?php
                $selType = $mil_req ? 'Military' : 'Academic';
                foreach (['Academic', 'Official', 'Military', 'Ceremonial', 'Seminar', 'Other'] as $t): ?>
                    <option value="<?= e($t) ?>" <?= ($selType === $t) ? 'selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Event Mode / Status
            <select name="mode" id="create_mode_select" onchange="toggleEventModeCreate(this.value)" required>
                <option value="Offline" selected>Offline (Physical Auditorium & Seating)</option>
                <option value="Online">Online (Virtual Webinar / Meeting Only)</option>
                <option value="Hybrid">Hybrid (Auditorium & Live Stream)</option>
            </select>
        </label>

        <label id="create_meeting_link_wrap" style="display:none;">
            Online Meeting / Stream Link
            <input
                type="url"
                name="meeting_link"
                placeholder="https://meet.google.com/... or Zoom link"
            >
        </label>

        <label>
            Date
            <input
                type="date"
                name="event_date"
                value="<?= e($mil_req['proposed_date'] ?? '') ?>"
                required
            >
        </label>

        <label>
            Time
            <input
                type="time"
                name="event_time"
                value="09:00"
                required
            >
        </label>

        <label id="create_venue_wrap" class="span-2">
            Venue (Auditorium / Hall)
            <select name="venue" id="create_venue_select">
                <?php foreach ($available_venues as $v): ?>
                    <option value="<?= e($v) ?>" <?= ($mil_req && strpos($mil_req['proposed_venue'], $v) !== false) ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Operational Command Level
            <select name="command_level">
                <option value="Event Coordinator" selected>Event Coordinator (L2)</option>
            </select>
            <small style="color:#94a3b8; font-size:11px;">Direct line of command from Event Director.</small>
        </label>

        <label>
            Assign Event Coordinator
            <select name="event_coordinator_id">
                <option value="">-- Select Coordinator --</option>
                <?php foreach ($coordinators as $c): ?>
                    <option value="<?= (int)$c['id'] ?>">
                        <?= e($c['name']) ?> (<?= e($c['designation'] ?: $c['role']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Budget Planning (Estimated BDT)
            <input
                type="number"
                step="100"
                min="0"
                name="budget_total"
                value="0"
                placeholder="Estimated Budget in BDT"
            >
        </label>

        <label>
            Status
            <select name="status">
                <option value="Upcoming" selected>Upcoming</option>
                <option value="Ongoing">Ongoing</option>
                <option value="Completed">Completed</option>
            </select>
        </label>

        <label class="span-2">
            Description
            <?php
            $defaultDesc = '';
            if ($mil_req) {
                $defaultDesc = "MILITARY DIRECTIVE [{$mil_req['request_id']}]\n" .
                    "Objectives: " . $mil_req['objectives'] . "\n\n" .
                    "Required Protocol Standards: " . $mil_req['required_protocol'] . "\n\n" .
                    "Attached SOP Doctrines: " . $mil_req['attached_sops'] . "\n\n" .
                    "Tactical Instructions: " . $mil_req['special_instructions'];
            }
            ?>
            <textarea
                name="description"
                rows="4"
                placeholder="Describe the event objectives, VIP protocol requirements..."
            ><?= e($defaultDesc) ?></textarea>
        </label>

        <label class="checkbox-label span-2">
            <input
                type="checkbox"
                name="priority"
                <?= ($mil_req && in_array($mil_req['priority'], ['Priority', 'Immediate', 'Flash'])) ? 'checked' : '' ?>
            >
            Mark as priority event
        </label>

        <div class="span-2">
            <button
                class="btn btn-primary"
                type="submit"
            >
                <?= $mil_req ? 'Create Event from Directive' : 'Create Event' ?>
            </button>
        </div>

    </form>

</section>
<?php endif; ?>

<script>
function toggleEventModeCreate(mode) {
    const venueWrap = document.getElementById('create_venue_wrap');
    const meetingWrap = document.getElementById('create_meeting_link_wrap');
    if (mode === 'Online') {
        venueWrap.style.display = 'none';
        meetingWrap.style.display = 'block';
    } else if (mode === 'Hybrid') {
        venueWrap.style.display = 'block';
        meetingWrap.style.display = 'block';
    } else {
        venueWrap.style.display = 'block';
        meetingWrap.style.display = 'none';
    }
}

function toggleEventModeEdit(mode) {
    const venueWrap = document.getElementById('edit_venue_wrap');
    const meetingWrap = document.getElementById('edit_meeting_link_wrap');
    if (mode === 'Online') {
        venueWrap.style.display = 'none';
        meetingWrap.style.display = 'block';
    } else if (mode === 'Hybrid') {
        venueWrap.style.display = 'block';
        meetingWrap.style.display = 'block';
    } else {
        venueWrap.style.display = 'block';
        meetingWrap.style.display = 'none';
    }
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>