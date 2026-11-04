<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$pdo = db();
$page_title = 'Duties | BUP Protocol';


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | CREATE DUTY
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        if (is_staff()) {
            flash('error', 'Staff / Volunteers cannot create or assign duties.');
            redirect(BASE_URL . '/duties.php');
        }

        $event_id = (int)($_POST['event_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department = trim($_POST['department'] ?? 'Protocol');
        $level = trim($_POST['level'] ?? 'L5');
        $location = trim($_POST['location'] ?? '');
        $duty_date = !empty($_POST['duty_date']) ? $_POST['duty_date'] : null;
        $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
        $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
        $time_slot = trim($_POST['time_slot'] ?? '');
        if (!$time_slot && $start_time) {
            $time_slot = substr($start_time, 0, 5) . ($end_time ? ' - ' . substr($end_time, 0, 5) : '');
        }

        $supervisor_id = !empty($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : null;
        $backup_user_id = !empty($_POST['backup_user_id']) ? (int)$_POST['backup_user_id'] : null;
        $reports_to = trim($_POST['reports_to'] ?? '');
        $backup_person = trim($_POST['backup_person'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $priority = trim($_POST['priority'] ?? 'Medium');
        $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $status = in_array($_POST['status'] ?? '', ['Pending', 'Assigned', 'Accepted', 'In Progress', 'Completed', 'Cancelled'], true)
            ? $_POST['status']
            : ($assigned_to ? 'Assigned' : 'Pending');
        $due_at = !empty($_POST['due_at']) ? $_POST['due_at'] : null;

        if (is_event_director()) {
            $level = 'L2';
            $department = 'Co-ordination';
            $start_time = null;
            $end_time = null;
            $location = '';
            $time_slot = '';
            $assigned_to = null;
            $supervisor_id = null;
            $backup_user_id = null;
            $backup_person = '';
            $reports_to = 'Event Director';
            $status = 'Pending';
        } elseif (is_event_coordinator()) {
            $level = 'L3';
            if (strpos($_POST['level'] ?? '', 'L3-') === 0) {
                $deptFromLevel = substr($_POST['level'], 3);
                if (in_array($deptFromLevel, ['Venue', 'Protocol', 'Communication', 'Logistics', 'Security'], true)) {
                    $department = $deptFromLevel;
                }
            }
            $start_time = null;
            $end_time = null;
            $location = '';
            $time_slot = '';
            $assigned_to = null;
            $supervisor_id = null;
            $backup_user_id = null;
            $backup_person = '';
        } elseif (is_supervisor()) {
            $level = 'L5';
            $supervisor_id = (int)current_user()['id'];
            $backup_user_id = null;
            $backup_person = '';
            $status = $assigned_to ? 'Assigned' : 'Pending';
            if ($assigned_to > 0) {
                $userStaffCheck = $pdo->prepare("SELECT role, level, department FROM users WHERE id = ?");
                $userStaffCheck->execute([$assigned_to]);
                $uData = $userStaffCheck->fetch();
                if (!$uData || ($uData['role'] !== 'Staff / Volunteer' && $uData['level'] !== 'L5') || ($uData['role'] ?? '') === 'Student') {
                    flash('error', 'Supervisors can only assign duties to Staff members.');
                    redirect(BASE_URL . '/duties.php');
                }
                if (!empty($uData['department'])) {
                    $department = $uData['department'];
                }
            }
        } elseif (is_team_lead()) {
            $department = current_department();
            if (!in_array($level, ['L4', 'L5'], true)) {
                $level = 'L5';
            }
            if ($assigned_to > 0) {
                $userDeptCheck = $pdo->prepare("SELECT department, role, level FROM users WHERE id = ?");
                $userDeptCheck->execute([$assigned_to]);
                $uData = $userDeptCheck->fetch();
                if (!$uData || strcasecmp((string)$uData['department'], $department) !== 0 || ($uData['role'] ?? '') === 'Student') {
                    flash('error', 'You can only assign personnel within your own department (' . $department . ').');
                    redirect(BASE_URL . '/duties.php');
                }
            }
        }

        if ($event_id <= 0 || $title === '') {
            flash('error', 'Event and duty title are required.');
            redirect(BASE_URL . '/duties.php');
        }

        $stmt = $pdo->prepare("
            INSERT INTO duties
            (
                event_id,
                title,
                department,
                level,
                duty_date,
                start_time,
                end_time,
                description,
                notes,
                location,
                time_slot,
                reports_to,
                supervisor_id,
                backup_person,
                backup_user_id,
                priority,
                assigned_to,
                assigned_by,
                status,
                due_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $event_id,
            $title,
            $department,
            $level,
            $duty_date,
            $start_time,
            $end_time,
            $description,
            $notes,
            $location,
            $time_slot,
            $reports_to,
            $supervisor_id,
            $backup_person,
            $backup_user_id,
            $priority,
            $assigned_to,
            current_user()['id'],
            $status,
            $due_at
        ]);

        $duty_id = $pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO audit_logs(user_id, action, description)
            VALUES (?, ?, ?)
        ")->execute([
            current_user()['id'],
            'Duty Created',
            'Created duty #' . $duty_id . ' — ' . $title . ' [' . $department . ' ' . $level . ']'
        ]);

        if (function_exists('notify_duty_assignment')) {
            notify_duty_assignment((int)$duty_id, $title, $department, $level, (int)$assigned_to, (int)current_user()['id']);
        }

        flash('success', 'Duty created successfully under ' . $department . ' (' . $level . ').');
        redirect(BASE_URL . '/duties.php');
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE DUTY
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        if (is_event_director()) {
            flash('error', 'Event Directors do not edit duties directly.');
            redirect(BASE_URL . '/duties.php');
        }

        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            flash('error', 'Invalid duty ID.');
            redirect(BASE_URL . '/duties.php');
        }

        // If staff, only allow updating status of own duty
        if (is_staff()) {
            $check = $pdo->prepare("SELECT assigned_to FROM duties WHERE id = ?");
            $check->execute([$id]);
            if ((int)$check->fetchColumn() !== (int)current_user()['id']) {
                flash('error', 'You can only update status for your own assigned duty.');
                redirect(BASE_URL . '/duties.php');
            }

            $new_status = in_array($_POST['status'] ?? '', ['Accepted', 'In Progress', 'Completed'], true)
                ? $_POST['status']
                : 'In Progress';

            $pdo->prepare("UPDATE duties SET status = ? WHERE id = ?")->execute([$new_status, $id]);
            if (function_exists('notify_duty_status_change')) {
                notify_duty_status_change($id, $new_status, (int)current_user()['id']);
            }
            flash('success', 'Your duty status has been updated to: ' . $new_status);
            redirect(BASE_URL . '/duties.php');
        }

        $event_id = (int)($_POST['event_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $department = trim($_POST['department'] ?? 'Protocol');
        $level = trim($_POST['level'] ?? 'L5');
        $location = trim($_POST['location'] ?? '');
        $duty_date = !empty($_POST['duty_date']) ? $_POST['duty_date'] : null;
        $start_time = !empty($_POST['start_time']) ? $_POST['start_time'] : null;
        $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
        $time_slot = trim($_POST['time_slot'] ?? '');
        if (!$time_slot && $start_time) {
            $time_slot = substr($start_time, 0, 5) . ($end_time ? ' - ' . substr($end_time, 0, 5) : '');
        }

        $supervisor_id = !empty($_POST['supervisor_id']) ? (int)$_POST['supervisor_id'] : null;
        $backup_user_id = !empty($_POST['backup_user_id']) ? (int)$_POST['backup_user_id'] : null;
        $reports_to = trim($_POST['reports_to'] ?? '');
        $backup_person = trim($_POST['backup_person'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $priority = trim($_POST['priority'] ?? 'Medium');
        $assigned_to = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $status = in_array($_POST['status'] ?? '', ['Pending', 'Assigned', 'Accepted', 'In Progress', 'Completed', 'Cancelled'], true)
            ? $_POST['status']
            : 'Pending';
        $due_at = !empty($_POST['due_at']) ? $_POST['due_at'] : null;

        if (is_supervisor()) {
            $checkDept = $pdo->prepare("SELECT department FROM duties WHERE id = ?");
            $checkDept->execute([$id]);
            $existingDept = (string)$checkDept->fetchColumn();
            if (strcasecmp($existingDept, current_department()) !== 0) {
                flash('error', 'Supervisors can only modify duties in their own department.');
                redirect(BASE_URL . '/duties.php');
            }
            $department = current_department();
            $level = 'L5';
            $supervisor_id = (int)current_user()['id'];
            if ($assigned_to > 0) {
                $userStaffCheck = $pdo->prepare("SELECT role, level, department FROM users WHERE id = ?");
                $userStaffCheck->execute([$assigned_to]);
                $uData = $userStaffCheck->fetch();
                if (!$uData || ($uData['role'] !== 'Staff / Volunteer' && $uData['level'] !== 'L5') || strcasecmp((string)$uData['department'], $department) !== 0) {
                    flash('error', 'Supervisors can only assign duties to Staff members within their own department (' . $department . ').');
                    redirect(BASE_URL . '/duties.php');
                }
            }
        } elseif (is_team_lead()) {
            $checkDept = $pdo->prepare("SELECT department FROM duties WHERE id = ?");
            $checkDept->execute([$id]);
            $existingDept = (string)$checkDept->fetchColumn();
            if (strcasecmp($existingDept, current_department()) !== 0) {
                flash('error', 'Department Leads can only modify duties in their own department.');
                redirect(BASE_URL . '/duties.php');
            }
            $department = current_department();
        }

        if ($event_id <= 0 || $title === '') {
            flash('error', 'Invalid duty information.');
            redirect(BASE_URL . '/duties.php');
        }

        $stmt = $pdo->prepare("
            UPDATE duties
            SET
                event_id = ?,
                title = ?,
                department = ?,
                level = ?,
                duty_date = ?,
                start_time = ?,
                end_time = ?,
                description = ?,
                notes = ?,
                location = ?,
                time_slot = ?,
                reports_to = ?,
                supervisor_id = ?,
                backup_person = ?,
                backup_user_id = ?,
                priority = ?,
                assigned_to = ?,
                status = ?,
                due_at = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $event_id,
            $title,
            $department,
            $level,
            $duty_date,
            $start_time,
            $end_time,
            $description,
            $notes,
            $location,
            $time_slot,
            $reports_to,
            $supervisor_id,
            $backup_person,
            $backup_user_id,
            $priority,
            $assigned_to,
            $status,
            $due_at,
            $id
        ]);

        $pdo->prepare("
            INSERT INTO audit_logs(user_id, action, description)
            VALUES (?, ?, ?)
        ")->execute([
            current_user()['id'],
            'Duty Updated',
            'Updated duty #' . $id . ' — ' . $title . ' [' . $status . ']'
        ]);

        flash('success', 'Duty updated successfully.');
        redirect(BASE_URL . '/duties.php');
    }

    /*
    | Quick Status Toggle
    */
    if ($action === 'quick_status') {
        if (is_event_director()) {
            flash('error', 'Event Directors can only monitor duty status in real time.');
            redirect(BASE_URL . '/duties.php');
        }
        $id = (int)($_POST['id'] ?? 0);
        $new_status = trim($_POST['status'] ?? 'Completed');

        if ($id > 0) {
            if (is_staff()) {
                $check = $pdo->prepare("SELECT assigned_to FROM duties WHERE id = ?");
                $check->execute([$id]);
                if ((int)$check->fetchColumn() !== (int)current_user()['id']) {
                    flash('error', 'You can only update your own assigned duty.');
                    redirect(BASE_URL . '/duties.php');
                }
            }

            $pdo->prepare("UPDATE duties SET status = ? WHERE id = ?")->execute([$new_status, $id]);
            if (function_exists('notify_duty_status_change')) {
                notify_duty_status_change($id, $new_status, (int)current_user()['id']);
            }
            $pdo->prepare("INSERT INTO audit_logs(user_id, action, description) VALUES (?, ?, ?)")
                ->execute([current_user()['id'], 'Duty Status Changed', 'Set duty #' . $id . ' status to ' . $new_status]);
            flash('success', 'Duty status set to ' . $new_status . '!');
        }
        redirect(BASE_URL . '/duties.php');
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE DUTY
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {

            $stmt = $pdo->prepare("
                SELECT title, department
                FROM duties
                WHERE id = ?
            ");

            $stmt->execute([$id]);
            $duty = $stmt->fetch();

            if ($duty) {
                if (is_team_lead() && strcasecmp($duty['department'], current_department()) !== 0) {
                    flash('error', 'Department Leads can only delete duties in their own department.');
                    redirect(BASE_URL . '/duties.php');
                }

                $pdo->prepare("
                    DELETE FROM duties
                    WHERE id = ?
                ")->execute([$id]);

                $pdo->prepare("
                    INSERT INTO audit_logs(user_id, action, description)
                    VALUES (?, ?, ?)
                ")->execute([
                    current_user()['id'],
                    'Duty Deleted',
                    'Deleted duty #' . $id . ' — ' . $duty['title']
                ]);

                flash('success', 'Duty deleted successfully.');
            }
        }

        redirect(BASE_URL . '/duties.php');
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
$assignee_filter = (int)($_GET['assigned_to'] ?? 0);
$dept_filter = trim($_GET['dept'] ?? '');
$lvl_filter = trim($_GET['lvl'] ?? '');


/*
|--------------------------------------------------------------------------
| EVENTS
|--------------------------------------------------------------------------
*/

$events = $pdo->query("
    SELECT id, name
    FROM events
    ORDER BY event_date DESC, event_time DESC
")->fetchAll();


/*
|--------------------------------------------------------------------------
| USERS
|--------------------------------------------------------------------------
*/

if (is_supervisor()) {
    $stmtUsers = $pdo->query("
        SELECT id, name, role, level, department, designation
        FROM users
        WHERE status = 'Active' 
          AND (role = 'Staff / Volunteer' OR level = 'L5')
          AND role != 'Student'
        ORDER BY department ASC, name ASC
    ");
    $users = $stmtUsers->fetchAll();
} elseif (is_team_lead()) {
    $stmtUsers = $pdo->prepare("
        SELECT id, name, role, level, department, designation
        FROM users
        WHERE status = 'Active' 
          AND level IN ('L4', 'L5')
          AND department = ?
          AND role != 'Student'
        ORDER BY level ASC, name ASC
    ");
    $stmtUsers->execute([current_department()]);
    $users = $stmtUsers->fetchAll();
} else {
    $users = $pdo->query("
        SELECT id, name, role, level, department, designation
        FROM users
        WHERE status = 'Active'
          AND role != 'Student'
        ORDER BY level ASC, name ASC
    ")->fetchAll();
}

$supervisors_list = $pdo->query("
    SELECT id, name, designation, department, role, level
    FROM users
    WHERE (role IN ('Supervisor', 'Department Lead', 'Event Coordinator', 'Event Director', 'Admin') OR level IN ('L1','L2','L3','L4'))
      AND status = 'Active'
      AND role != 'Student'
    ORDER BY level ASC, name ASC
")->fetchAll();

$backup_users_list = $pdo->query("
    SELECT id, name, role, department, designation
    FROM users
    WHERE status = 'Active'
      AND role != 'Student'
    ORDER BY name ASC
")->fetchAll();


/*
|--------------------------------------------------------------------------
| EDIT DUTY
|--------------------------------------------------------------------------
*/

$edit_id = (int)($_GET['edit'] ?? 0);
$edit_duty = null;

if (is_event_director()) {
    $edit_id = 0;
} elseif ($edit_id > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM duties
        WHERE id = ?
    ");

    $stmt->execute([$edit_id]);
    $edit_duty = $stmt->fetch();

    if (!$edit_duty) {
        flash('error', 'Duty not found.');
        redirect(BASE_URL . '/duties.php');
    }

    if (is_team_lead() && strcasecmp($edit_duty['department'], current_department()) !== 0) {
        flash('error', 'Department Leads can only edit duties within their own department.');
        redirect(BASE_URL . '/duties.php');
    }
}


/*
|--------------------------------------------------------------------------
| DUTY QUERY
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        d.*,
        e.name AS event_name,
        u.name AS assignee,
        u.role AS assignee_role,
        u.level AS assignee_level,
        sup.name AS supervisor_name,
        bk.name AS backup_user_name
    FROM duties d
    LEFT JOIN events e ON e.id = d.event_id
    LEFT JOIN users u ON u.id = d.assigned_to
    LEFT JOIN users sup ON sup.id = d.supervisor_id
    LEFT JOIN users bk ON bk.id = d.backup_user_id
    WHERE 1
";

$params = [];

// Role-based hierarchy access restrictions
if (is_staff()) {
    $sql .= " AND (d.assigned_to = ? OR (d.level = 'L5' AND (d.department = ? OR d.assigned_to = 0)))";
    $params[] = current_user()['id'];
    $params[] = current_department();
} elseif (is_supervisor()) {
    // Supervisor sees L4 and L5 ground duties to oversee field operations
    $sql .= " AND d.level IN ('L4', 'L5')";
} elseif (is_team_lead()) {
    // Department Lead sees L3, L4, L5 duties within their department
    $sql .= " AND (d.department = ? OR d.assigned_to = ?) AND d.level IN ('L3', 'L4', 'L5')";
    $params[] = current_department();
    $params[] = current_user()['id'];
}


/* Search */

if ($q !== '') {

    $sql .= "
        AND (
            d.title LIKE ?
            OR d.description LIKE ?
            OR e.name LIKE ?
            OR u.name LIKE ?
        )
    ";

    $search = "%{$q}%";

    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}


/* Event */

if ($event_filter > 0) {

    $sql .= " AND d.event_id = ?";
    $params[] = $event_filter;
}


/* Status */

if ($status_filter !== '') {

    $sql .= " AND d.status = ?";
    $params[] = $status_filter;
}


/* Assignee */

if ($assignee_filter > 0) {

    $sql .= " AND d.assigned_to = ?";
    $params[] = $assignee_filter;
}

/* Department */
if ($dept_filter !== '') {
    $sql .= " AND d.department = ?";
    $params[] = $dept_filter;
}

/* Command Level */
if ($lvl_filter !== '') {
    $sql .= " AND d.level = ?";
    $params[] = $lvl_filter;
}


/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
*/

$sql .= "
    ORDER BY
        CASE
            WHEN d.status = 'Ongoing' THEN 1
            WHEN d.status = 'Pending' THEN 2
            WHEN d.status = 'Assigned' THEN 3
            WHEN d.status = 'Completed' THEN 4
            ELSE 5
        END,
        d.due_at IS NULL,
        d.due_at ASC
";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$duties = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$total_duties = count($duties);

$pending_count = 0;
$assigned_count = 0;
$ongoing_count = 0;
$completed_count = 0;

foreach ($duties as $duty) {

    if ($duty['status'] === 'Pending') {
        $pending_count++;
    }

    if ($duty['status'] === 'Assigned') {
        $assigned_count++;
    }

    if ($duty['status'] === 'Ongoing') {
        $ongoing_count++;
    }

    if ($duty['status'] === 'Completed') {
        $completed_count++;
    }
}


require __DIR__ . '/includes/header.php';
?>


<div class="page-head">

    <div>

        <span class="eyebrow">DUTY MANAGEMENT</span>

        <h1>Duties</h1>

        <p>
            Create, assign and track operational duties for each event.
        </p>

    </div>

    <?php if (can_assign_duties() && !is_staff()): ?>
    <a
        class="btn btn-primary"
        href="#duty-form"
    >
        + Create Duty
    </a>
    <?php endif; ?>

</div>


<!-- SUMMARY -->

<div class="stat-grid">

    <div class="stat-card">

        <span>Total Duties</span>

        <strong><?= $total_duties ?></strong>

        <em>Current filtered records</em>

    </div>


    <div class="stat-card">

        <span>Pending</span>

        <strong><?= $pending_count ?></strong>

        <em>Waiting to be handled</em>

    </div>


    <div class="stat-card">

        <span>Ongoing</span>

        <strong><?= $ongoing_count ?></strong>

        <em>Currently in progress</em>

    </div>


    <div class="stat-card">

        <span>Completed</span>

        <strong><?= $completed_count ?></strong>

        <em>Successfully completed</em>

    </div>

</div>


<!-- FILTER -->

<section class="panel">

    <div class="panel-head">

        <div>

            <h2>Duty Search & Filter</h2>

            <p>
                Find duties by event, assignee or status.
            </p>

        </div>

    </div>


<!-- CHAIN OF COMMAND FLOW BANNER -->
<section class="panel" style="margin-bottom:24px; padding:20px 24px; background:linear-gradient(135deg, #051d14 0%, #082d21 50%, #0d3b2b 100%); color:#ffffff; border-radius:18px; border:1px solid rgba(212,175,55,0.25); box-shadow:0 10px 30px rgba(6,40,30,0.12);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px; border-bottom:1px solid rgba(255,255,255,0.12); padding-bottom:12px;">
        <div>
            <span style="font-size:11px; font-weight:800; letter-spacing:1.8px; color:#f5d98d; text-transform:uppercase;">OPERATIONAL CHAIN OF COMMAND</span>
            <h2 style="margin:4px 0 0; color:#ffffff; font-size:19px;">Duty Assignment Flow (5 Levels &times; 5 Departments)</h2>
        </div>
        <div style="font-size:12px; color:rgba(255,255,255,0.75);">
            Current Role: <strong style="color:#f5d98d;"><?= e(current_user()['role'] ?? 'User') ?></strong> (<?= e(current_level()) ?>)
        </div>
    </div>

    <!-- 5 Levels visual cards -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px;">
        <div style="background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.15); border-radius:10px; padding:12px 14px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span style="background:#f5d98d; color:#082d21; font-weight:800; font-size:10px; padding:2px 6px; border-radius:4px; font-family:monospace;">L1</span>
                <strong style="font-size:13px; color:#ffffff;">Event Director</strong>
            </div>
            <p style="margin:0; font-size:11px; color:#cbd5e1; line-height:1.4;">Decision making, approval &amp; escalation</p>
        </div>

        <div style="background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.15); border-radius:10px; padding:12px 14px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span style="background:#60a5fa; color:#0f172a; font-weight:800; font-size:10px; padding:2px 6px; border-radius:4px; font-family:monospace;">L2</span>
                <strong style="font-size:13px; color:#ffffff;">Coordinator</strong>
            </div>
            <p style="margin:0; font-size:11px; color:#cbd5e1; line-height:1.4;">Aligns all teams &amp; monitors progress</p>
        </div>

        <div style="background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.15); border-radius:10px; padding:12px 14px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span style="background:#34d399; color:#064e3b; font-weight:800; font-size:10px; padding:2px 6px; border-radius:4px; font-family:monospace;">L3</span>
                <strong style="font-size:13px; color:#ffffff;">Dept Leads (5)</strong>
            </div>
            <p style="margin:0; font-size:11px; color:#cbd5e1; line-height:1.4;">Venue, Security, Logistics, Protocol, Comm</p>
        </div>

        <div style="background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.15); border-radius:10px; padding:12px 14px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span style="background:#fbbf24; color:#78350f; font-weight:800; font-size:10px; padding:2px 6px; border-radius:4px; font-family:monospace;">L4</span>
                <strong style="font-size:13px; color:#ffffff;">Supervisors</strong>
            </div>
            <p style="margin:0; font-size:11px; color:#cbd5e1; line-height:1.4;">On-site setup check, patrols &amp; incidents</p>
        </div>

        <div style="background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.15); border-radius:10px; padding:12px 14px;">
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span style="background:#a78bfa; color:#2e1065; font-weight:800; font-size:10px; padding:2px 6px; border-radius:4px; font-family:monospace;">L5</span>
                <strong style="font-size:13px; color:#ffffff;">Staff / Volunteer</strong>
            </div>
            <p style="margin:0; font-size:11px; color:#cbd5e1; line-height:1.4;">Direct task execution &amp; ground operations</p>
        </div>
    </div>
</section>

<!-- FILTER -->
<section class="panel">

    <div class="panel-head">
        <div>
            <h2>Duty Search &amp; Filter</h2>
            <p>Filter duty allocations by department, command level, assignee or status.</p>
        </div>
    </div>

    <form method="get" class="filter-form">

        <input
            type="text"
            name="q"
            value="<?= e($q) ?>"
            placeholder="Search duty, location, assignee..."
        >

        <select name="event_id">
            <option value="">All Events</option>
            <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= $event_filter == $ev['id'] ? 'selected' : '' ?>>
                    <?= e($ev['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if (is_team_lead()): ?>
            <select name="dept">
                <option value="<?= e(current_department()) ?>" selected><?= e(current_department()) ?> Department</option>
            </select>
        <?php else: ?>
            <select name="dept">
                <option value="">All Departments</option>
                <option value="Venue" <?= $dept_filter === 'Venue' ? 'selected' : '' ?>>Venue Management</option>
                <option value="Security" <?= $dept_filter === 'Security' ? 'selected' : '' ?>>Safety &amp; Security</option>
                <option value="Logistics" <?= $dept_filter === 'Logistics' ? 'selected' : '' ?>>Transport &amp; Supply</option>
                <option value="Protocol" <?= $dept_filter === 'Protocol' ? 'selected' : '' ?>>Guest &amp; VIP Handling</option>
                <option value="Communication" <?= $dept_filter === 'Communication' ? 'selected' : '' ?>>Media &amp; Information</option>
            </select>
        <?php endif; ?>

        <?php if (is_staff()): ?>
            <select name="lvl">
                <option value="L5" selected>Level L5 (Staff / Volunteer)</option>
            </select>
        <?php elseif (is_supervisor()): ?>
            <select name="lvl">
                <option value="">All Levels (L4, L5)</option>
                <option value="L4" <?= $lvl_filter === 'L4' ? 'selected' : '' ?>>L4 (Supervisor)</option>
                <option value="L5" <?= $lvl_filter === 'L5' ? 'selected' : '' ?>>L5 (Staff / Volunteer)</option>
            </select>
        <?php elseif (is_team_lead()): ?>
            <select name="lvl">
                <option value="">All Levels (L3, L4, L5)</option>
                <option value="L3" <?= $lvl_filter === 'L3' ? 'selected' : '' ?>>L3 (Department Lead)</option>
                <option value="L4" <?= $lvl_filter === 'L4' ? 'selected' : '' ?>>L4 (Supervisor)</option>
                <option value="L5" <?= $lvl_filter === 'L5' ? 'selected' : '' ?>>L5 (Staff / Volunteer)</option>
            </select>
        <?php else: ?>
            <select name="lvl">
                <option value="">All Levels</option>
                <option value="L1" <?= $lvl_filter === 'L1' ? 'selected' : '' ?>>L1 (Event Director)</option>
                <option value="L2" <?= $lvl_filter === 'L2' ? 'selected' : '' ?>>L2 (Event Coordinator)</option>
                <option value="L3" <?= $lvl_filter === 'L3' ? 'selected' : '' ?>>L3 (Department Lead)</option>
                <option value="L4" <?= $lvl_filter === 'L4' ? 'selected' : '' ?>>L4 (Supervisor)</option>
                <option value="L5" <?= $lvl_filter === 'L5' ? 'selected' : '' ?>>L5 (Staff / Volunteer)</option>
            </select>
        <?php endif; ?>

        <select name="assigned_to">
            <option value="">All Assignees</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $assignee_filter == $u['id'] ? 'selected' : '' ?>>
                    <?= e($u['name']) ?> (<?= e($u['role']) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status">
            <option value="">All Status</option>
            <?php foreach (['Pending', 'Assigned', 'Ongoing', 'Completed'] as $st): ?>
                <option value="<?= e($st) ?>" <?= $status_filter === $st ? 'selected' : '' ?>>
                    <?= e($st) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-dark" type="submit">Filter</button>
        <a class="btn btn-light" href="<?= BASE_URL ?>/duties.php">Reset</a>

    </form>

</section>


<!-- DUTY REGISTER -->
<section class="panel">

    <div class="panel-head">
        <div>
            <h2>Duty Register</h2>
            <p><?= count($duties) ?> duty records found across command levels</p>
        </div>
    </div>

    <div class="table-wrap">

        <table>
            <thead>
                <tr>
                    <th>Duty &amp; Department</th>
                    <th>Level</th>
                    <th>Location &amp; Time</th>
                    <th>Chain of Command</th>
                    <th>Priority &amp; Due</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ($duties as $d): ?>
                <?php
                $deptColors = [
                    'Venue' => ['bg' => '#dcfce7', 'text' => '#166534', 'label' => 'Venue Management'],
                    'Security' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'label' => 'Safety & Security'],
                    'Logistics' => ['bg' => '#fef3c7', 'text' => '#92400e', 'label' => 'Transport & Supply'],
                    'Protocol' => ['bg' => '#f3e8ff', 'text' => '#6b21a8', 'label' => 'Guest & VIP Handling'],
                    'Communication' => ['bg' => '#cffafe', 'text' => '#155e75', 'label' => 'Media & Info'],
                ];
                $dc = $deptColors[$d['department'] ?? ''] ?? ['bg' => '#f1f5f9', 'text' => '#475569', 'label' => $d['department'] ?? 'General'];

                $levelColors = [
                    'L1' => ['bg' => '#082d21', 'text' => '#f5d98d', 'label' => 'L1 · Command'],
                    'L2' => ['bg' => '#2563eb', 'text' => '#ffffff', 'label' => 'L2 · Coordinator'],
                    'L3' => ['bg' => '#059669', 'text' => '#ffffff', 'label' => 'L3 · Dept Lead'],
                    'L4' => ['bg' => '#d97706', 'text' => '#ffffff', 'label' => 'L4 · Supervisor'],
                    'L5' => ['bg' => '#6366f1', 'text' => '#ffffff', 'label' => 'L5 · Staff / Vol'],
                ];
                $lc = $levelColors[$d['level'] ?? 'L5'] ?? ['bg' => '#64748b', 'text' => '#ffffff', 'label' => $d['level'] ?? 'L5'];
                ?>
                <tr>
                    <td>
                        <div style="margin-bottom:4px;">
                            <span style="display:inline-block; font-size:10px; font-weight:700; padding:2px 7px; border-radius:4px; background:<?= $dc['bg'] ?>; color:<?= $dc['text'] ?>;">
                                <?= e($dc['label']) ?>
                            </span>
                        </div>
                        <strong style="display:block; font-size:14px; color:#082d21;">
                            <?= e($d['title']) ?>
                        </strong>
                        <small style="color:#64748b; display:block; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            <?= e($d['description'] ?: 'No description provided') ?>
                        </small>
                        <small style="color:#a47b2c; font-weight:600;">
                            Event: <?= e($d['event_name'] ?? 'General') ?>
                        </small>
                    </td>

                    <td>
                        <span style="display:inline-block; font-size:11px; font-weight:800; padding:3px 8px; border-radius:6px; background:<?= $lc['bg'] ?>; color:<?= $lc['text'] ?>; font-family:monospace;">
                            <?= e($lc['label']) ?>
                        </span>
                    </td>

                    <td>
                        <?php if (!empty($d['duty_date'])): ?>
                            <div style="font-weight:700; color:#1e293b; font-size:12px;">
                                📅 <?= e(date('d M Y', strtotime($d['duty_date']))) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($d['start_time'])): ?>
                            <div style="font-size:11px; color:#475569; font-weight:600; margin-top:2px;">
                                🕒 <?= e(substr($d['start_time'], 0, 5)) ?><?= !empty($d['end_time']) ? ' – ' . e(substr($d['end_time'], 0, 5)) : '' ?>
                            </div>
                        <?php elseif (!empty($d['time_slot'])): ?>
                            <div style="font-size:11px; color:#64748b; font-weight:600; margin-top:2px;">
                                🕒 <?= e($d['time_slot']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($d['location'])): ?>
                            <div style="color:#0369a1; font-size:11px; margin-top:3px; font-weight:500;">
                                📍 <?= e($d['location']) ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div style="font-size:12px;">
                            <?php if ($d['assignee']): ?>
                                <strong style="color:#0f172a;"><?= e($d['assignee']) ?></strong>
                                <?php if (!empty($d['assignee_role'])): ?>
                                    <span style="font-size:10px; color:#64748b; display:block;">(<?= e($d['assignee_role']) ?>)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge neutral">Unassigned</span>
                            <?php endif; ?>
                        </div>

                        <?php $sup_name = $d['supervisor_name'] ?: $d['reports_to']; ?>
                        <?php if (!empty($sup_name)): ?>
                            <small style="display:block; color:#475569; font-size:11px; margin-top:3px;">
                                Supervisor: <strong style="color:#1e293b;"><?= e($sup_name) ?></strong>
                            </small>
                        <?php endif; ?>

                        <?php $bk_name = $d['backup_user_name'] ?: $d['backup_person']; ?>
                        <?php if (!empty($bk_name)): ?>
                            <small style="display:block; color:#64748b; font-size:10px; margin-top:1px;">
                                Backup: <span style="color:#a16207; font-weight:600;"><?= e($bk_name) ?></span>
                            </small>
                        <?php endif; ?>

                        <?php if (!empty($d['notes'])): ?>
                            <small style="display:block; color:#0d9488; font-size:10px; margin-top:2px; font-style:italic;">
                                ℹ️ <?= e(substr($d['notes'], 0, 50)) ?><?= strlen($d['notes']) > 50 ? '...' : '' ?>
                            </small>
                        <?php endif; ?>
                    </td>

                    <td>
                        <?php
                        $priColors = [
                            'Urgent' => '#ef4444',
                            'High' => '#f97316',
                            'Medium' => '#d97706',
                            'Normal' => '#64748b',
                            'Low' => '#64748b'
                        ];
                        $pc = $priColors[$d['priority'] ?? 'Normal'] ?? '#64748b';
                        ?>
                        <span style="display:inline-block; font-size:10px; font-weight:700; color:<?= $pc ?>; text-transform:uppercase; letter-spacing:0.5px;">
                            • <?= e($d['priority'] ?? 'Normal') ?>
                        </span>
                        <?php if ($d['due_at']): ?>
                            <div style="font-size:12px; font-weight:600; color:#334155; margin-top:2px;">
                                <?= e(date('d M Y', strtotime($d['due_at']))) ?>
                            </div>
                            <small style="color:#64748b;">
                                <?= e(date('h:i A', strtotime($d['due_at']))) ?>
                            </small>
                        <?php else: ?>
                            <div style="color:#94a3b8; font-size:12px;">—</div>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                            <?php
                            $stClass = 'neutral';
                            $st = $d['status'];
                            if (in_array($st, ['Completed'], true)) $stClass = 'completed';
                            elseif (in_array($st, ['In Progress', 'Ongoing'], true)) $stClass = 'ongoing';
                            elseif (in_array($st, ['Accepted', 'Assigned'], true)) $stClass = 'assigned';
                            elseif (in_array($st, ['Pending'], true)) $stClass = 'pending';
                            elseif (in_array($st, ['Cancelled'], true)) $stClass = 'rejected';
                            ?>
                            <span class="badge <?= $stClass ?>">
                                <?= e($d['status']) ?>
                            </span>

                            <!-- Role-aware Quick Action Buttons -->
                            <?php if (!is_event_director()): ?>
                                <?php if ($d['status'] === 'Pending' || $d['status'] === 'Assigned'): ?>
                                    <form method="post" style="display:inline; margin:0;">
                                        <input type="hidden" name="action" value="quick_status">
                                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                        <input type="hidden" name="status" value="Accepted">
                                        <button type="submit" class="btn btn-light" style="font-size:10px; padding:3px 8px; min-height:0; line-height:1.2; background:#e0e7ff; color:#3730a3; border-color:#c7d2fe;">
                                            Accept &check;
                                        </button>
                                    </form>
                                <?php elseif ($d['status'] === 'Accepted'): ?>
                                    <form method="post" style="display:inline; margin:0;">
                                        <input type="hidden" name="action" value="quick_status">
                                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                        <input type="hidden" name="status" value="In Progress">
                                        <button type="submit" class="btn btn-light" style="font-size:10px; padding:3px 8px; min-height:0; line-height:1.2; background:#fef3c7; color:#92400e; border-color:#fde68a;">
                                            Start &rarr;
                                        </button>
                                    </form>
                                <?php elseif ($d['status'] === 'In Progress' || $d['status'] === 'Ongoing'): ?>
                                    <form method="post" style="display:inline; margin:0;">
                                        <input type="hidden" name="action" value="quick_status">
                                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                        <input type="hidden" name="status" value="Completed">
                                        <button type="submit" class="btn btn-primary" style="font-size:10px; padding:3px 8px; min-height:0; line-height:1.2; background:#16a34a; border-color:#16a34a; color:#fff;">
                                            &check; Complete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>

                    <td>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <?php if (is_event_director()): ?>
                                <form class="inline-form" method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="link-danger" onclick="return confirm('Delete this duty?')">
                                        Delete
                                    </button>
                                </form>
                            <?php elseif (can_assign_duties() && !is_staff()): ?>
                                <a class="table-link" href="<?= BASE_URL ?>/duties.php?edit=<?= $d['id'] ?>#duty-form">
                                    Edit
                                </a>

                                <form class="inline-form" method="post" style="display:inline;">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="link-danger" onclick="return confirm('Delete this duty?')">
                                        Delete
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="font-size:11px; color:#64748b;">Assigned Duty</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$duties): ?>
                <tr>
                    <td colspan="7" class="empty">
                        No duty records found matching criteria.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

    </div>

</section>


<!-- CREATE / EDIT DUTY -->
<?php if (can_assign_duties() && !is_staff()): ?>
<?php
$is_l1 = is_event_director();
$is_l2 = is_event_coordinator();
$is_l3 = is_team_lead();
$is_l4 = is_supervisor();
?>
<section id="duty-form" class="panel form-panel">

    <div class="panel-head">
        <div>
            <h2><?= ($edit_duty && !$is_l1) ? 'Edit Duty Assignment' : 'Create & Allocate Duty' ?></h2>
            <p>
                <?php if ($is_l1): ?>
                    Direct duty allocation to Event Coordinator (L2) for strategic alignment.
                <?php elseif ($is_l2): ?>
                    Allocate high-level department operational duties to Leads (L3), Supervisors (L4) or Staff (L5).
                <?php elseif ($is_l4): ?>
                    Ground duty delegation &mdash; assign specific tasks directly to active Staff &amp; Volunteers (L5).
                <?php else: ?>
                    Allocate or update duty assignments according to the Chain of Command hierarchy.
                <?php endif; ?>
            </p>
        </div>

        <?php if ($edit_duty && !$is_l1): ?>
            <a class="btn btn-light" href="<?= BASE_URL ?>/duties.php">
                Cancel Edit
            </a>
        <?php endif; ?>
    </div>

    <form method="post" class="form-grid">

        <input type="hidden" name="action" value="<?= ($edit_duty && !$is_l1) ? 'update' : 'add' ?>">

        <?php if ($edit_duty && !$is_l1): ?>
            <input type="hidden" name="id" value="<?= $edit_duty['id'] ?>">
        <?php endif; ?>

        <label>
            Event
            <select name="event_id" required>
                <option value="">Select event</option>
                <?php foreach ($events as $ev): ?>
                    <option
                        value="<?= $ev['id'] ?>"
                        <?= (($edit_duty && $edit_duty['event_id'] == $ev['id']) || (!$edit_duty && $event_filter == $ev['id'])) ? 'selected' : '' ?>
                    >
                        <?= e($ev['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            Duty Title
            <input
                type="text"
                name="title"
                value="<?= e($edit_duty['title'] ?? '') ?>"
                placeholder="e.g. VIP Dais Escort & Stage Protocol"
                required
            >
        </label>

        <?php if ($is_l1): ?>
            <!-- L1 assigns directly to L2 Coordinator, department omitted -->
            <input type="hidden" name="department" value="Co-ordination">
        <?php elseif ($is_l2): ?>
            <!-- L2 assigns to specific L3 lead, selected via Command Level -->
            <input type="hidden" name="department" id="l2_hidden_dept" value="<?= e($edit_duty['department'] ?? 'Venue') ?>">
        <?php elseif ($is_l3): ?>
            <label>
                Department / Team
                <input type="text" value="<?= e(current_department()) ?>" readonly style="background:#f1f5f9; cursor:not-allowed; font-weight:600; color:#0f172a;">
                <input type="hidden" name="department" value="<?= e(current_department()) ?>">
            </label>
        <?php else: ?>
            <label>
                Department / Team
                <select name="department" required>
                    <option value="Venue" <?= (($edit_duty['department'] ?? '') === 'Venue') ? 'selected' : '' ?>>
                        Venue Management (Setup, AV, Seating)
                    </option>
                    <option value="Security" <?= (($edit_duty['department'] ?? '') === 'Security') ? 'selected' : '' ?>>
                        Safety &amp; Security (Access control, Guard cordon)
                    </option>
                    <option value="Logistics" <?= (($edit_duty['department'] ?? '') === 'Logistics') ? 'selected' : '' ?>>
                        Logistics &amp; Transport (Vehicles, supplies)
                    </option>
                    <option value="Protocol" <?= (($edit_duty['department'] ?? 'Protocol') === 'Protocol') ? 'selected' : '' ?>>
                        Military &amp; VIP Protocol (Dignitaries, Ceremonial)
                    </option>
                    <option value="Communication" <?= (($edit_duty['department'] ?? '') === 'Communication') ? 'selected' : '' ?>>
                        Media &amp; Information (Announcements, Reception)
                    </option>
                </select>
            </label>
        <?php endif; ?>

        <label>
            Command Level
            <select name="level" required onchange="if(document.getElementById('l2_hidden_dept') && this.value.startsWith('L3-')) { document.getElementById('l2_hidden_dept').value = this.value.replace('L3-', ''); }">
                <?php if ($is_l1): ?>
                    <option value="L2" selected>L2 — Event Coordinator</option>
                <?php elseif ($is_l2): ?>
                    <option value="L3-Venue" <?= (($edit_duty['department'] ?? '') === 'Venue') ? 'selected' : '' ?>>L3 — Venue Lead</option>
                    <option value="L3-Protocol" <?= (($edit_duty['department'] ?? '') === 'Protocol') ? 'selected' : '' ?>>L3 — Protocol Lead</option>
                    <option value="L3-Communication" <?= (($edit_duty['department'] ?? '') === 'Communication') ? 'selected' : '' ?>>L3 — Communication Lead</option>
                    <option value="L3-Logistics" <?= (($edit_duty['department'] ?? '') === 'Logistics') ? 'selected' : '' ?>>L3 — Logistics Lead</option>
                    <option value="L3-Security" <?= (($edit_duty['department'] ?? '') === 'Security') ? 'selected' : '' ?>>L3 — Security Lead</option>
                <?php elseif ($is_l3): ?>
                    <option value="L4" <?= (($edit_duty['level'] ?? '') === 'L4') ? 'selected' : '' ?>>L4 — Supervisor (Ground Supervision)</option>
                    <option value="L5" <?= (($edit_duty['level'] ?? 'L5') === 'L5') ? 'selected' : '' ?>>L5 — Staff / Volunteer (Execution)</option>
                <?php elseif ($is_l4): ?>
                    <option value="L5" selected>L5 — Staff / Volunteer (Execution)</option>
                <?php else: ?>
                    <option value="L2">L2 — Event Coordinator</option>
                    <option value="L3">L3 — Department / Team Lead</option>
                    <option value="L4">L4 — Supervisor (Ground Supervision)</option>
                    <option value="L5" selected>L5 — Staff / Volunteer (Execution)</option>
                <?php endif; ?>
            </select>
        </label>

        <label>
            Duty Date
            <input
                type="date"
                name="duty_date"
                value="<?= e($edit_duty['duty_date'] ?? '') ?>"
            >
        </label>

        <?php if (!$is_l1 && !$is_l2): ?>
            <label>
                Start Time
                <input
                    type="time"
                    name="start_time"
                    value="<?= e(substr($edit_duty['start_time'] ?? '', 0, 5)) ?>"
                >
            </label>

            <label>
                End Time
                <input
                    type="time"
                    name="end_time"
                    value="<?= e(substr($edit_duty['end_time'] ?? '', 0, 5)) ?>"
                >
            </label>

            <label>
                Location
                <input
                    type="text"
                    name="location"
                    value="<?= e($edit_duty['location'] ?? '') ?>"
                    placeholder="e.g. Main Gate, VIP Dais, Auditorium Row A"
                >
            </label>

            <label>
                <?= $is_l4 ? 'Assign Staff' : 'Assign Personnel' ?>
                <select name="assigned_to">
                    <option value="">-- Unassigned --</option>
                    <?php foreach ($users as $u): ?>
                        <option
                            value="<?= $u['id'] ?>"
                            <?= (($edit_duty['assigned_to'] ?? '') == $u['id']) ? 'selected' : '' ?>
                        >
                            <?= e($u['name']) ?> &bull; <?= e($u['level']) ?> (<?= e($u['role']) ?><?= !empty($u['department']) ? ' - ' . e($u['department']) : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <?php if (!$is_l1 && !$is_l2 && !$is_l4): ?>
            <label>
                Supervisor / Reporting Person
                <select name="supervisor_id">
                    <option value="">-- Select Reporting Officer --</option>
                    <?php foreach ($supervisors_list as $sup): ?>
                        <option
                            value="<?= $sup['id'] ?>"
                            <?= (($edit_duty['supervisor_id'] ?? '') == $sup['id']) ? 'selected' : '' ?>
                        >
                            <?= e($sup['name']) ?> (<?= e($sup['designation'] ?: $sup['role']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Backup Person (Fallback Responsibility)
                <select name="backup_user_id">
                    <option value="">-- Select Backup Person --</option>
                    <?php foreach ($backup_users_list as $bk): ?>
                        <option
                            value="<?= $bk['id'] ?>"
                            <?= (($edit_duty['backup_user_id'] ?? '') == $bk['id']) ? 'selected' : '' ?>
                        >
                            <?= e($bk['name']) ?> &bull; <?= e($bk['role']) ?> (<?= e($bk['department']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <label>
            Priority
            <select name="priority">
                <option value="Normal" <?= (($edit_duty['priority'] ?? 'Normal') === 'Normal') ? 'selected' : '' ?>>Normal</option>
                <option value="High" <?= (($edit_duty['priority'] ?? '') === 'High') ? 'selected' : '' ?>>High</option>
                <option value="Urgent" <?= (($edit_duty['priority'] ?? '') === 'Urgent') ? 'selected' : '' ?>>Urgent (Immediate Attention)</option>
            </select>
        </label>

        <?php if (!$is_l1 && !$is_l4): ?>
            <label>
                Status
                <select name="status">
                    <?php foreach (['Pending', 'Assigned', 'Accepted', 'In Progress', 'Completed', 'Cancelled'] as $st): ?>
                        <option value="<?= e($st) ?>" <?= (($edit_duty['status'] ?? 'Pending') === $st) ? 'selected' : '' ?>>
                            <?= e($st) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>

        <label class="span-2">
            Notes &amp; Operational Checkpoints
            <textarea
                name="notes"
                rows="2"
                placeholder="Specific tactical instructions, radio callsigns, or escalation guidelines..."
            ><?= e($edit_duty['notes'] ?? '') ?></textarea>
        </label>

        <label class="span-2">
            Description &amp; Overview
            <textarea
                name="description"
                rows="3"
                placeholder="Describe the operational responsibility..."
            ><?= e($edit_duty['description'] ?? '') ?></textarea>
        </label>

        <div class="span-2">
            <button class="btn btn-primary" type="submit">
                <?= ($edit_duty && !$is_l1) ? 'Update Duty Assignment' : 'Allocate Duty' ?>
            </button>
            <?php if ($edit_duty && !$is_l1): ?>
                <a class="btn btn-light" href="<?= BASE_URL ?>/duties.php">Cancel</a>
            <?php endif; ?>
        </div>

    </form>

</section>
<?php endif; ?>


<style>
/* ==========================================
   DUTIES PAGE — CARD SPACING FIX
   CSS inside duties.php only
========================================== */

/* Page heading */
.page-head {
    margin-bottom: 28px;
}

/* Summary cards */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

/* Separate all duty page sections */
.main-content > .panel,
.main-content > section.panel {
    margin-bottom: 26px;
}

/* Panel layout */
.main-content .panel {
    min-width: 0;
    border-radius: 20px;
    overflow: hidden;
}

/* Panel header */
.main-content .panel-head {
    padding: 24px 28px;
    margin: 0;
    border-bottom: 1px solid #e5eaf1;
}

/* Filter form */
.main-content .filter-form {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.2fr)
        repeat(3, minmax(150px, 1fr))
        auto auto;
    align-items: center;
    gap: 12px;
    padding: 18px 22px;
}

.main-content .filter-form input,
.main-content .filter-form select {
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    min-height: 58px;
    margin: 0;
}

.main-content .filter-form .btn {
    min-height: 58px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}

/* Duty register table */
.main-content .table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    margin: 0;
}

.main-content .table-wrap table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}

.main-content .table-wrap th,
.main-content .table-wrap td {
    padding: 17px 20px;
    vertical-align: middle;
    line-height: 1.55;
}

.main-content .table-wrap tbody tr:last-child td {
    border-bottom: 0;
}

/* Create / Edit Duty form */
.form-panel {
    margin-top: 26px;
}

.main-content .form-panel .panel-head {
    margin-bottom: 0;
}

.main-content .form-panel form.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px 24px;
    padding: 26px 28px 30px;
}

.main-content .form-panel form.form-grid > label {
    display: flex;
    flex-direction: column;
    gap: 9px;
    min-width: 0;
}

.main-content .form-panel form.form-grid input,
.main-content .form-panel form.form-grid select,
.main-content .form-panel form.form-grid textarea {
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
}

.main-content .form-panel form.form-grid .span-2 {
    grid-column: 1 / -1;
}

/* Tablet */
@media (max-width: 1200px) {
    .stat-grid {
        gap: 16px;
    }

    .main-content .filter-form {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .main-content .table-wrap th,
    .main-content .table-wrap td {
        padding: 15px;
    }
}

/* Mobile */
@media (max-width: 720px) {
    .page-head {
        margin-bottom: 20px;
    }

    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 22px;
    }

    .main-content > .panel,
    .main-content > section.panel {
        margin-bottom: 20px;
    }

    .main-content .panel-head {
        padding: 20px;
    }

    .main-content .filter-form {
        grid-template-columns: 1fr;
        gap: 12px;
        padding: 16px;
    }

    .main-content .filter-form input,
    .main-content .filter-form select,
    .main-content .filter-form .btn {
        width: 100%;
        min-height: 50px;
    }

    .main-content .form-panel form.form-grid {
        grid-template-columns: 1fr;
        gap: 18px;
        padding: 20px;
    }

    .main-content .form-panel form.form-grid .span-2 {
        grid-column: 1;
    }

    .main-content .table-wrap table {
        min-width: 850px;
    }
}

@media (max-width: 420px) {
    .stat-grid {
        gap: 10px;
    }

    .main-content .panel-head {
        padding: 16px;
    }

    .main-content .form-panel form.form-grid {
        padding: 16px;
    }
}
</style>






<?php require __DIR__ . '/includes/footer.php'; ?>