<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_login();

if (!can_create_accounts() && !is_event_director() && !has_role('Admin')) {
    flash('error', 'Access restricted: User management requires authorized director or lead privileges.');
    redirect(BASE_URL . '/dashboard.php');
}

$page_title = 'User Management | BUP Protocol';
$currentUserId = (int)current_user()['id'];

// =====================================================
// HANDLE USER CREATION & STATUS UPDATE (MongoDB Atlas)
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // =================================================
    // CREATE NEW ROLE-BASED ACCOUNT
    // =================================================
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = trim($_POST['role'] ?? 'Staff');
        $level = trim($_POST['level'] ?? 'L5');
        $department = trim($_POST['department'] ?? 'Protocol');
        $designation = trim($_POST['designation'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $reports_to_id = !empty($_POST['reports_to_id']) ? (int)$_POST['reports_to_id'] : null;
        $backup_person_id = !empty($_POST['backup_person_id']) ? (int)$_POST['backup_person_id'] : null;
        $protocol_level = !empty($_POST['protocol_level']) ? trim($_POST['protocol_level']) : null;

        if (!$name || !$email || !$password) {
            flash('error', 'Name, email, and password are required.');
            redirect(BASE_URL . '/users.php');
        }

        // Enforce hierarchy permission boundaries
        if (is_team_lead()) {
            if (!in_array($level, ['L4', 'L5'], true)) {
                flash('error', 'Team Leads can only create L4 Supervisor or L5 Staff accounts.');
                redirect(BASE_URL . '/users.php');
            }
            $department = current_department(); // locked to team lead department
        } elseif (is_event_coordinator()) {
            if (!in_array($level, ['L3', 'L4', 'L5'], true)) {
                flash('error', 'Event Coordinators can only create L3 Team Lead, L4 Supervisor, or L5 Staff accounts.');
                redirect(BASE_URL . '/users.php');
            }
        } elseif (!is_event_director() && !has_role('Admin')) {
            flash('error', 'You are not authorized to create accounts.');
            redirect(BASE_URL . '/users.php');
        }

        // Email uniqueness check (MongoDB Atlas)
        $exists = mcoll('users')->findOne(['email' => $email]);
        if ($exists) {
            flash('error', 'An account with email ' . $email . ' already exists.');
            redirect(BASE_URL . '/users.php');
        }

        if ($role === 'Event Coordinator') {
            $level = 'L2';
            $department = 'Co-ordination';
        } elseif ($role === 'L3_VENUE_LEAD' || $role === 'Venue Lead') {
            $role = 'Department Lead';
            $level = 'L3';
            $department = 'Venue';
        } elseif ($role === 'L3_SECURITY_LEAD' || $role === 'Security Lead') {
            $role = 'Department Lead';
            $level = 'L3';
            $department = 'Security';
        } elseif ($role === 'L3_LOGISTICS_LEAD' || $role === 'Logistics Lead') {
            $role = 'Department Lead';
            $level = 'L3';
            $department = 'Logistics';
        } elseif ($role === 'L3_PROTOCOL_LEAD' || $role === 'Protocol Lead') {
            $role = 'Department Lead';
            $level = 'L3';
            $department = 'Protocol';
        } elseif ($role === 'L3_COMMUNICATION_LEAD' || $role === 'Communication Lead') {
            $role = 'Department Lead';
            $level = 'L3';
            $department = 'Communication';
        } elseif ($role === 'Department Lead') {
            $level = 'L3';
        } elseif ($role === 'Supervisor') {
            $level = 'L4';
        } elseif ($role === 'Staff' || $role === 'Volunteer' || $role === 'Staff / Volunteer') {
            $role = 'Staff / Volunteer';
            $level = 'L5';
        } elseif ($role === 'Military Protocol' || $role === 'Military Command') {
            $level = 'Military Protocol';
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $newUserId = mongo_next_id('users');
        $now = date('Y-m-d H:i:s');

        mcoll('users')->insertOne([
            'id' => $newUserId,
            'name' => $name,
            'email' => $email,
            'password' => $hash,
            'password_hash' => $hash,
            'role' => $role,
            'level' => $level,
            'department' => $department,
            'designation' => $designation,
            'phone' => $phone,
            'status' => 'Active',
            'reports_to_id' => $reports_to_id,
            'backup_person_id' => $backup_person_id,
            'protocol_level' => $protocol_level,
            'failed_attempts' => 0,
            'last_login' => null,
            'created_at' => $now
        ]);

        mongo_audit_log(
            current_user()['id'],
            'User Created',
            'Created account for ' . $name . ' (' . $email . ') as ' . $role . ' [' . $level . ']'
        );

        flash('success', 'User account created successfully for <strong>' . e($name) . '</strong> (' . e($role) . ' - ' . e($level) . ').');
        redirect(BASE_URL . '/users.php');
    }

    // =================================================
    // ACCOUNT STATUS UPDATE (Active / Inactive)
    // =================================================
    if ($action === 'status') {
        $id = (int)($_POST['id'] ?? 0);
        $requestedStatus = ($_POST['status'] ?? '') === 'Active' ? 'Active' : 'Inactive';

        if (!$id) {
            flash('error', 'Invalid user account.');
            redirect(BASE_URL . '/users.php');
        }

        if ($id === $currentUserId && $requestedStatus === 'Inactive') {
            flash('error', 'You cannot deactivate your own account.');
            redirect(BASE_URL . '/users.php');
        }

        $targetUserDoc = mcoll('users')->findOne(['id' => $id]);
        if (!$targetUserDoc) {
            flash('error', 'User account not found.');
            redirect(BASE_URL . '/users.php');
        }

        $targetUser = mongo_doc_to_array($targetUserDoc);

        if (($targetUser['status'] ?? '') === $requestedStatus) {
            flash('success', 'Account is already ' . strtolower($requestedStatus) . '.');
            redirect(BASE_URL . '/users.php');
        }

        try {
            mcoll('users')->updateOne(['id' => $id], ['$set' => ['status' => $requestedStatus]]);

            $actionText = $requestedStatus === 'Active' ? 'User Activated' : 'User Deactivated';
            $description = 'User "' . ($targetUser['name'] ?? '') . '" (' . ($targetUser['email'] ?? '') . ') was ' . strtolower($requestedStatus) . '.';

            mongo_audit_log($currentUserId, $actionText, $description);
            flash('success', 'Account status updated successfully.');
        } catch (Throwable $e) {
            flash('error', 'Unable to update account status.');
        }

        redirect(BASE_URL . '/users.php');
    }

    // =================================================
    // APPROVE / REJECT STUDENT REGISTRATIONS
    // =================================================
    if ($action === 'approve_registration' || $action === 'reject_registration') {
        if (!is_event_director() && !has_role('Admin')) {
            flash('error', 'Only System Administrator or Event Director can approve registrations.');
            redirect(BASE_URL . '/users.php');
        }

        $id = (int)($_POST['id'] ?? 0);
        $newStatus = ($action === 'approve_registration') ? 'Active' : 'Rejected';

        $targetDoc = mcoll('users')->findOne(['id' => $id]);
        if ($targetDoc) {
            $target = mongo_doc_to_array($targetDoc);
            mcoll('users')->updateOne(
                ['id' => $id],
                ['$set' => ['status' => $newStatus, 'failed_attempts' => 0]]
            );

            mongo_audit_log(
                $currentUserId,
                $action === 'approve_registration' ? 'Registration Approved' : 'Registration Rejected',
                'Administrator ' . ($action === 'approve_registration' ? 'approved' : 'rejected') . ' student registration for ' . ($target['name'] ?? '') . ' (' . ($target['email'] ?? '') . ')'
            );

            $notifTitle = $action === 'approve_registration' ? 'Registration Approved: ' . ($target['name'] ?? '') : 'Registration Rejected: ' . ($target['name'] ?? '');
            $notifMsg = $action === 'approve_registration'
                ? 'Student account for ' . ($target['name'] ?? '') . ' (' . ($target['email'] ?? '') . ') has been APPROVED. The student may now sign in.'
                : 'Student account registration for ' . ($target['name'] ?? '') . ' was REJECTED by Administrator.';

            $annId = mongo_next_id('announcements');
            mcoll('announcements')->insertOne([
                'id' => $annId,
                'title' => $notifTitle,
                'message' => $notifMsg,
                'created_by' => $currentUserId,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            flash('success', $action === 'approve_registration' ? 'Account for <strong>' . e($target['name'] ?? '') . '</strong> approved and activated successfully!' : 'Account registration rejected.');
        } else {
            flash('error', 'User account not found.');
        }

        redirect(BASE_URL . '/users.php');
    }
}

// =====================================================
// SEARCH / FILTER
// =====================================================
$search = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

// =====================================================
// STATISTICS (MongoDB Atlas)
// =====================================================
$totalUsers = mcoll('users')->countDocuments();
$activeUsers = mcoll('users')->countDocuments(['status' => 'Active']);
$inactiveUsers = mcoll('users')->countDocuments(['status' => 'Inactive']);
$pendingUsersCount = mcoll('users')->countDocuments(['status' => 'Pending']);

$pendingRegistrationsCursor = mcoll('users')->find(['status' => 'Pending'], ['sort' => ['id' => -1]]);
$pendingRegistrations = [];
foreach ($pendingRegistrationsCursor as $pDoc) {
    $pendingRegistrations[] = mongo_doc_to_array($pDoc);
}

$lockedUsers = mcoll('users')->countDocuments(['failed_attempts' => ['$gte' => 5]]);

$rawRoles = mcoll('users')->distinct('role', [
    'role' => ['$ne' => null, '$ne' => '', '$exists' => true]
]);
sort($rawRoles);
$roles = $rawRoles;
$roleCount = count($roles);

// Eligible users for Reports To and Backup Person dropdowns
$eligibleDocs = mcoll('users')->find(
    ['status' => 'Active'],
    ['sort' => ['level' => 1, 'name' => 1]]
);
$eligible_users = [];
foreach ($eligibleDocs as $eDoc) {
    $eligible_users[] = mongo_doc_to_array($eDoc);
}

// =====================================================
// USER QUERY (MongoDB Atlas Aggregation Pipeline)
// =====================================================
$matchStage = [];

if ($search !== '') {
    $searchRegex = new MongoDB\BSON\Regex(preg_quote($search, '/'), 'i');
    $matchStage['$or'] = [
        ['name' => $searchRegex],
        ['email' => $searchRegex],
        ['designation' => $searchRegex],
        ['phone' => $searchRegex]
    ];
}

if ($roleFilter !== '') {
    $matchStage['role'] = $roleFilter;
}

if ($statusFilter === 'Active' || $statusFilter === 'Inactive') {
    $matchStage['status'] = $statusFilter;
}

$pipeline = [
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'reports_to_id',
            'foreignField' => 'id',
            'as' => 'rt'
        ]
    ],
    ['$unwind' => ['path' => '$rt', 'preserveNullAndEmptyArrays' => true]],
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'backup_person_id',
            'foreignField' => 'id',
            'as' => 'bp'
        ]
    ],
    ['$unwind' => ['path' => '$bp', 'preserveNullAndEmptyArrays' => true]]
];

if (!empty($matchStage)) {
    $pipeline[] = ['$match' => $matchStage];
}

$cursor = mcoll('users')->aggregate($pipeline);
$users = [];
foreach ($cursor as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['reports_to_name'] = $row['rt']['name'] ?? null;
    $row['backup_person_name'] = $row['bp']['name'] ?? null;
    unset($row['rt'], $row['bp']);
    $users[] = $row;
}

// Sort matching SQL ORDER BY: Active first, then name ASC
usort($users, function($a, $b) {
    $stA = ($a['status'] ?? '') === 'Active' ? 1 : 2;
    $stB = ($b['status'] ?? '') === 'Active' ? 1 : 2;
    if ($stA !== $stB) return $stA <=> $stB;
    return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
});

require __DIR__ . '/includes/header.php';

?>


<div class="page-head">

    <div>

        <span class="eyebrow">
            ADMINISTRATION
        </span>

        <h1>
            User & Security
        </h1>

        <p>
            Manage authorized users, roles and account activation status.
        </p>

    </div>

</div>


<!-- =====================================================
     SECURITY NOTICE
====================================================== -->

<div class="security-notice">

    <div class="security-notice-icon">
        ✓
    </div>

    <div>

        <strong>
            Administrator Access
        </strong>

        <p>
            Only authorized administrators can activate or deactivate
            system accounts.
        </p>

    </div>

</div>


<!-- =====================================================
     STATISTICS
====================================================== -->

<div class="stat-grid user-stat-grid">

    <div class="stat-card">

        <span>
            Total Users
        </span>

        <strong>
            <?= $totalUsers ?>
        </strong>

        <em>
            Registered accounts
        </em>

    </div>


    <div class="stat-card">

        <span>
            Active
        </span>

        <strong class="stat-green">
            <?= $activeUsers ?>
        </strong>

        <em>
            Currently enabled
        </em>

    </div>


    <div class="stat-card">

        <span>
            Inactive
        </span>

        <strong class="stat-red">
            <?= $inactiveUsers ?>
        </strong>

        <em>
            Disabled accounts
        </em>

    </div>


    <div class="stat-card">

        <span>
            Security Alerts
        </span>

        <strong class="stat-warning">
            <?= $lockedUsers ?>
        </strong>

        <em>
            5+ failed attempts
        </em>

    </div>


    <div class="stat-card">

        <span>
            Roles
        </span>

        <strong>
            <?= $roleCount ?>
        </strong>

        <em>
            Configured user roles
        </em>

    </div>

</div>


<!-- =====================================================
     ROLE-BASED ACCOUNT CREATION (L2, L3, L4, L5, PROTOCOL)
====================================================== -->
<?php if (can_create_accounts() || is_event_director() || has_role('Admin')): ?>
<section class="panel form-panel" style="margin-bottom:28px;">
    <div class="panel-head">
        <div>
            <span class="eyebrow">HIERARCHY ACCESS CONTROL</span>
            <h2>Role-Based Account Creation</h2>
            <p>
                Authorized accounts can create operational personnel based on strict hierarchy boundaries.
                (Current authorization: <strong><?= e(current_user()['role']) ?> · <?= e(current_level()) ?></strong>)
            </p>
        </div>
    </div>

    <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:18px; padding:24px 28px;">
        <input type="hidden" name="action" value="create">

        <label style="display:flex; flex-direction:column; gap:6px;">
            Full Name *
            <input type="text" name="name" required placeholder="e.g. Major Tanvir Ahmed / Staff Member">
        </label>

        <label style="display:flex; flex-direction:column; gap:6px;">
            Official Email *
            <input type="email" name="email" required placeholder="e.g. user@bup.local">
        </label>

        <label style="display:flex; flex-direction:column; gap:6px;">
            Initial Password *
            <input type="password" name="password" required placeholder="Set temporary password">
        </label>

        <label style="display:flex; flex-direction:column; gap:6px;">
            Role &amp; Command Level *
            <select name="role" id="user-role-select" required onchange="handleRoleLevelChange(this.value)">
                <?php if (has_role('Admin') || is_event_director()): ?>
                    <option value="Event Coordinator" data-level="L2">L2 – Event Coordinator</option>
                    <optgroup label="L3 Department Leads">
                        <option value="L3_VENUE_LEAD" data-level="L3">L3 – Venue Lead</option>
                        <option value="L3_SECURITY_LEAD" data-level="L3">L3 – Security Lead</option>
                        <option value="L3_LOGISTICS_LEAD" data-level="L3">L3 – Logistics Lead</option>
                        <option value="L3_PROTOCOL_LEAD" data-level="L3">L3 – Protocol Lead</option>
                        <option value="L3_COMMUNICATION_LEAD" data-level="L3">L3 – Communication Lead</option>
                    </optgroup>
                    <option value="Supervisor" data-level="L4">L4 – Supervisor</option>
                    <option value="Staff" data-level="L5" selected>L5 – Staff / Volunteer</option>
                    <option value="Military Protocol" data-level="Military Protocol">Military Protocol Unit / Personnel</option>
                <?php elseif (is_event_coordinator()): ?>
                    <optgroup label="L3 Department Leads">
                        <option value="L3_VENUE_LEAD" data-level="L3">L3 – Venue Lead</option>
                        <option value="L3_SECURITY_LEAD" data-level="L3">L3 – Security Lead</option>
                        <option value="L3_LOGISTICS_LEAD" data-level="L3">L3 – Logistics Lead</option>
                        <option value="L3_PROTOCOL_LEAD" data-level="L3">L3 – Protocol Lead</option>
                        <option value="L3_COMMUNICATION_LEAD" data-level="L3">L3 – Communication Lead</option>
                    </optgroup>
                    <option value="Supervisor" data-level="L4">L4 – Supervisor</option>
                    <option value="Staff" data-level="L5" selected>L5 – Staff / Volunteer</option>
                <?php elseif (is_team_lead()): ?>
                    <option value="Supervisor" data-level="L4">L4 – Supervisor (<?= e(current_department()) ?>)</option>
                    <option value="Staff" data-level="L5" selected>L5 – Staff / Volunteer (<?= e(current_department()) ?>)</option>
                <?php endif; ?>
            </select>
            <input type="hidden" name="level" id="user-level-hidden" value="L5">
        </label>

        <label style="display:flex; flex-direction:column; gap:6px;">
            Department / Team
            <select name="department" <?= is_team_lead() ? 'readonly style="pointer-events:none; background:#f1f5f9;"' : '' ?>>
                <?php if (is_team_lead()): ?>
                    <option value="<?= e(current_department()) ?>" selected><?= e(current_department()) ?> Department (Your Unit)</option>
                <?php else: ?>
                    <option value="Venue">Venue Management</option>
                    <option value="Security">Safety &amp; Security</option>
                    <option value="Logistics">Transport &amp; Supply</option>
                    <option value="Protocol" selected>Guest &amp; VIP Handling</option>
                    <option value="Communication">Media &amp; Information</option>
                    <option value="Administration">Administration &amp; Operations</option>
                <?php endif; ?>
            </select>
        </label>

        <label style="display:flex; flex-direction:column; gap:6px;">
            Designation / Title
            <input type="text" name="designation" placeholder="e.g. Venue In-Charge / Protocol Officer">
        </label>

        <label style="display:flex; flex-direction:column; gap:6px;">
            Contact Phone
            <input type="text" name="phone" placeholder="e.g. +8801700000000">
        </label>

        <label style="display:flex; flex-direction:column; gap:6px;">
            Reports To (Dropdown)
            <select name="reports_to_id">
                <option value="">— Select Reporting Officer / Lead —</option>
                <?php foreach ($eligible_users as $eu): ?>
                    <option value="<?= $eu['id'] ?>" <?= (!empty($eu['role']) && in_array($eu['role'], ['Event Director', 'Event Coordinator'], true)) ? 'selected' : '' ?>>
                        <?= e($eu['name']) ?> (<?= e($eu['role']) ?> · <?= e($eu['level']) ?><?= !empty($eu['department']) ? ' · ' . e($eu['department']) : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label style="display:flex; flex-direction:column; gap:6px;">
            Backup Person (Fallback Responsibility)
            <select name="backup_person_id">
                <option value="">— Select Eligible Backup User —</option>
                <?php foreach ($eligible_users as $eu): ?>
                    <option value="<?= $eu['id'] ?>">
                        <?= e($eu['name']) ?> (<?= e($eu['role']) ?><?= !empty($eu['department']) ? ' · ' . e($eu['department']) : '' ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label id="protocol-level-group" style="display:none; flex-direction:column; gap:6px;">
            Military Protocol Hierarchy Level
            <select name="protocol_level">
                <option value="">— Select Protocol Command Node —</option>
                <option value="Higher Command">1. Higher Command</option>
                <option value="Division">2. Division</option>
                <option value="Brigade">3. Brigade</option>
                <option value="Battalion">4. Battalion</option>
                <option value="Company">5. Company</option>
                <option value="Platoon">6. Platoon</option>
                <option value="Section">7. Section</option>
                <option value="Individual Soldier">8. Individual Soldier</option>
            </select>
        </label>

        <div style="grid-column:1 / -1; display:flex; justify-content:flex-end; gap:12px; margin-top:8px;">
            <button type="submit" class="btn btn-primary" style="padding:10px 24px;">
                &plus; Create &amp; Connect Account
            </button>
        </div>
    </form>
</section>

<script>
function handleRoleLevelChange(role) {
    const sel = document.getElementById('user-role-select');
    const opt = sel.options[sel.selectedIndex];
    const lvl = opt.getAttribute('data-level') || 'L5';
    document.getElementById('user-level-hidden').value = lvl;

    const protoGrp = document.getElementById('protocol-level-group');
    if (role === 'Military Protocol') {
        protoGrp.style.display = 'flex';
    } else {
        protoGrp.style.display = 'none';
    }
}
</script>
<?php endif; ?>

<!-- =====================================================
     PENDING STUDENT APPROVALS
====================================================== -->
<?php if (!empty($pendingRegistrations) && (is_event_director() || has_role('Admin'))): ?>
<section class="panel" style="border: 2px solid #f59e0b; background: #fffbeb; margin-bottom: 24px;">
    <div class="panel-head" style="border-bottom: 1px solid #fde68a;">
        <div>
            <span class="eyebrow" style="color: #b45309; font-weight: 800;">ADMIN APPROVAL WORKFLOW</span>
            <h2 style="color: #92400e;">Pending Account Registrations (<?= count($pendingRegistrations) ?>)</h2>
            <p style="margin: 4px 0 0; font-size: 13px; color: #78350f;">Review and verify student registrations. Pending accounts cannot log in until approved.</p>
        </div>
        <span class="badge" style="background:#f59e0b; color:#fff; font-weight:800; padding:6px 12px; border-radius:6px;">
            <?= count($pendingRegistrations) ?> PENDING
        </span>
    </div>
    <div class="table-wrap">
        <table class="user-table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>Student ID / Dept</th>
                    <th>Email / Phone</th>
                    <th>Registration Date</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingRegistrations as $pu): ?>
                <tr>
                    <td>
                        <strong><?= e($pu['name']) ?></strong>
                        <span class="table-subtext" style="color:#b45309; font-weight:600;"><?= e($pu['role']) ?> • <?= e($pu['level']) ?></span>
                    </td>
                    <td>
                        <strong><?= e($pu['student_id'] ?: $pu['designation']) ?></strong>
                        <span class="table-subtext"><?= e($pu['department']) ?></span>
                    </td>
                    <td>
                        <?= e($pu['email']) ?>
                        <?php if ($pu['phone']): ?>
                            <span class="table-subtext"><?= e($pu['phone']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e(date('d M Y, h:i A', strtotime($pu['created_at']))) ?>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex; gap:8px;">
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="action" value="approve_registration">
                                <input type="hidden" name="id" value="<?= (int)$pu['id'] ?>">
                                <button type="submit" class="btn btn-primary" style="background:#059669; border-color:#059669; padding:6px 12px; font-size:12px; font-weight:700;">
                                    ✓ Approve
                                </button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to reject this registration?');">
                                <input type="hidden" name="action" value="reject_registration">
                                <input type="hidden" name="id" value="<?= (int)$pu['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="background:#dc2626; border-color:#dc2626; color:#fff; padding:6px 12px; font-size:12px; font-weight:700;">
                                    ✕ Reject
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- =====================================================
     USER MANAGEMENT
====================================================== -->

<section class="panel user-management-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                USER DIRECTORY
            </span>

            <h2>
                Authorized Users
            </h2>

        </div>

        <span class="record-count">

            <?= count($users) ?>

            user<?= count($users) !== 1 ? 's' : '' ?>

        </span>

    </div>


    <!-- =================================================
         FILTERS
    ================================================== -->

    <form
        method="get"
        class="user-filter"
    >

        <input
            type="search"
            name="q"
            value="<?= e($search) ?>"
            placeholder="Search name, email, designation..."
        >


        <select name="role">

            <option value="">
                All Roles
            </option>

            <?php foreach ($roles as $role): ?>

                <option
                    value="<?= e($role) ?>"
                    <?= $roleFilter === $role
                        ? 'selected'
                        : '' ?>
                >

                    <?= e($role) ?>

                </option>

            <?php endforeach; ?>

        </select>


        <select name="status">

            <option value="">
                All Status
            </option>

            <option
                value="Active"
                <?= $statusFilter === 'Active'
                    ? 'selected'
                    : '' ?>
            >
                Active
            </option>

            <option
                value="Pending"
                <?= $statusFilter === 'Pending'
                    ? 'selected'
                    : '' ?>
            >
                Pending
            </option>

            <option
                value="Inactive"
                <?= $statusFilter === 'Inactive'
                    ? 'selected'
                    : '' ?>
            >
                Inactive
            </option>

            <option
                value="Rejected"
                <?= $statusFilter === 'Rejected'
                    ? 'selected'
                    : '' ?>
            >
                Rejected
            </option>

        </select>


        <button
            type="submit"
            class="btn btn-dark"
        >
            Filter
        </button>


        <?php if (
            $search !== '' ||
            $roleFilter !== '' ||
            $statusFilter !== ''
        ): ?>

            <a
                href="<?= BASE_URL ?>/users.php"
                class="btn btn-light"
            >
                Reset
            </a>

        <?php endif; ?>

    </form>


    <!-- =================================================
         USER TABLE
    ================================================== -->

    <?php if ($users): ?>

        <div class="table-wrap">

            <table class="user-table">

                <thead>

                    <tr>
                        <th>User</th>
                        <th>Role &amp; Level</th>
                        <th>Dept / Designation</th>
                        <th>Command Chain</th>
                        <th>Status</th>
                        <th>Security</th>
                        <th>Last Login</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <!-- USER -->
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar">
                                        <?= e(strtoupper(mb_substr(trim($user['name'] ?? 'U'), 0, 1))) ?>
                                    </div>
                                    <div>
                                        <strong><?= e($user['name']) ?></strong>
                                        <small><?= e($user['email']) ?></small>
                                    </div>
                                </div>
                            </td>

                            <!-- ROLE & LEVEL -->
                            <td>
                                <?php if (!empty($user['level'])): ?>
                                    <span style="display:inline-block; font-size:10px; font-weight:800; padding:2px 6px; border-radius:4px; background:#082d21; color:#f5d98d; font-family:monospace; margin-bottom:3px;">
                                        <?= e($user['level']) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="role-badge" style="display:block;">
                                    <?= e($user['role']) ?>
                                </span>
                                <?php if (!empty($user['protocol_level'])): ?>
                                    <small style="color:#0284c7; font-size:10px; font-weight:700; display:block;">
                                        🎖 <?= e($user['protocol_level']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>

                            <!-- DEPT / DESIGNATION -->
                            <td>
                                <strong style="font-size:12px; color:#1e293b; display:block;">
                                    <?= e($user['department'] ?: 'General') ?>
                                </strong>
                                <small style="color:#64748b;">
                                    <?= e($user['designation'] ?: '—') ?>
                                    <?php if (!empty($user['phone'])): ?>
                                        <br><span style="font-size:10px;">📞 <?= e($user['phone']) ?></span>
                                    <?php endif; ?>
                                </small>
                            </td>

                            <!-- COMMAND CHAIN -->
                            <td>
                                <?php if (!empty($user['reports_to_name'])): ?>
                                    <div style="font-size:11px; color:#334155;">
                                        Reports: <strong><?= e($user['reports_to_name']) ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($user['backup_person_name'])): ?>
                                    <div style="font-size:10px; color:#a16207; margin-top:2px;">
                                        Backup: <strong><?= e($user['backup_person_name']) ?></strong>
                                    </div>
                                <?php endif; ?>
                                <?php if (empty($user['reports_to_name']) && empty($user['backup_person_name'])): ?>
                                    <span style="color:#94a3b8; font-size:11px;">Direct Command</span>
                                <?php endif; ?>
                            </td>


                            <!-- STATUS -->

                            <td>

                                <?php if (
                                    $user['status'] === 'Active'
                                ): ?>

                                    <span class="badge active">
                                        Active
                                    </span>

                                <?php else: ?>

                                    <span class="badge inactive">
                                        Inactive
                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- SECURITY -->

                            <td>

                                <?php
                                $failed =
                                    (int)($user['failed_attempts'] ?? 0);
                                ?>


                                <?php if ($failed >= 5): ?>

                                    <span class="security-warning">

                                        ⚠
                                        <?= $failed ?>
                                        failed

                                    </span>

                                <?php elseif ($failed > 0): ?>

                                    <span class="security-normal">

                                        <?= $failed ?>
                                        failed

                                    </span>

                                <?php else: ?>

                                    <span class="security-normal">

                                        No failed attempts

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- LAST LOGIN -->

                            <td>

                                <span class="last-login">

                                    <?= !empty(
                                        $user['last_login']
                                    )
                                        ? e(
                                            $user['last_login']
                                        )
                                        : 'Never'
                                    ?>

                                </span>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <?php if (
                                    (int)$user['id']
                                    === $currentUserId
                                ): ?>

                                    <span class="self-account">
                                        Current account
                                    </span>

                                <?php else: ?>

                                    <form
                                        method="post"
                                        class="user-action-form"
                                        onsubmit="return confirm('Change this user account status?')"
                                    >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="status"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$user['id'] ?>"
                                        >


                                        <?php if (
                                            $user['status'] === 'Active'
                                        ): ?>

                                            <button
                                                type="submit"
                                                name="status"
                                                value="Inactive"
                                                class="btn btn-sm btn-danger"
                                            >
                                                Deactivate
                                            </button>

                                        <?php else: ?>

                                            <button
                                                type="submit"
                                                name="status"
                                                value="Active"
                                                class="btn btn-sm btn-primary"
                                            >
                                                Activate
                                            </button>

                                        <?php endif; ?>

                                    </form>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="empty">

            <strong>
                No users found
            </strong>

            <p>
                Try changing the search or filter options.
            </p>

        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     SECURITY INFORMATION
====================================================== -->

<section class="content-grid user-security-grid">


    <div class="panel">

        <div class="panel-head">

            <div>

                <span class="eyebrow">
                    ACCESS CONTROL
                </span>

                <h2>
                    Role Structure
                </h2>

            </div>

        </div>


        <div class="role-list">

            <div class="role-item">

                <span class="role-dot admin"></span>

                <div>

                    <strong>
                        Admin
                    </strong>

                    <small>
                        Full system administration and user management.
                    </small>

                </div>

            </div>


            <div class="role-item">

                <span class="role-dot event"></span>

                <div>

                    <strong>
                        Event Management
                    </strong>

                    <small>
                        Event planning and event operations.
                    </small>

                </div>

            </div>


            <div class="role-item">

                <span class="role-dot protocol"></span>

                <div>

                    <strong>
                        Protocol Officer
                    </strong>

                    <small>
                        Protocol planning and guest coordination.
                    </small>

                </div>

            </div>


            <div class="role-item">

                <span class="role-dot duty"></span>

                <div>

                    <strong>
                        Duty Personnel
                    </strong>

                    <small>
                        Assigned duty and operational activities.
                    </small>

                </div>

            </div>


            <div class="role-item">

                <span class="role-dot official"></span>

                <div>

                    <strong>
                        BUP Official
                    </strong>

                    <small>
                        Official event access and information.
                    </small>

                </div>

            </div>

        </div>

    </div>


    <div class="panel security-info">

        <div class="security-big-icon">
            ✓
        </div>

        <h2>
            Account Security
        </h2>

        <p>
            Inactive accounts should not be used for system access.
            Administrators can review account activity and control
            activation status from this directory.
        </p>

        <div class="security-rule">

            <strong>
                Self-protection enabled
            </strong>

            <span>
                Administrators cannot deactivate their own account.
            </span>

        </div>

        <div class="security-rule">

            <strong>
                Audit tracking
            </strong>

            <span>
                Account status changes are recorded in the audit log.
            </span>

        </div>

    </div>

</section>


<style>

/* =====================================================
   SECURITY NOTICE
===================================================== */

.security-notice {
    margin-top: 18px;
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 15px 17px;
    border-radius: 14px;
    border: 1px solid rgba(31,78,121,.15);
    background: rgba(31,78,121,.05);
}

.security-notice-icon {
    width: 39px;
    height: 39px;
    flex: 0 0 39px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: rgba(31,78,121,.1);
    color: var(--blue);
    font-weight: 900;
}

.security-notice strong {
    display: block;
}

.security-notice p {
    margin: 4px 0 0;
    color: var(--muted);
    font-size: 11px;
}


/* =====================================================
   STATISTICS
===================================================== */

.user-stat-grid {
    grid-template-columns:
        repeat(5, minmax(0, 1fr));
    margin-top: 18px;
}

.stat-green {
    color: var(--green);
}

.stat-red {
    color: var(--red);
}

.stat-warning {
    color: #a16207;
}


/* =====================================================
   USER MANAGEMENT
===================================================== */

.user-management-panel {
    margin-top: 18px;
}

.record-count {
    color: var(--muted);
    font-size: 11px;
}


/* =====================================================
   FILTER
===================================================== */

.user-filter {
    display: grid;
    grid-template-columns:
        minmax(220px, 1.5fr)
        minmax(170px, 1fr)
        minmax(150px, .8fr)
        auto
        auto;

    gap: 8px;
    margin-bottom: 18px;
}

.user-filter input,
.user-filter select {
    min-width: 0;
}


/* =====================================================
   USER CELL
===================================================== */

.user-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-avatar {
    width: 37px;
    height: 37px;
    flex: 0 0 37px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: rgba(31,78,121,.1);
    color: var(--blue);
    font-size: 13px;
    font-weight: 900;
}

.user-cell strong {
    display: block;
}

.user-cell small {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 10px;
}

.table-subtext {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 10px;
}


/* =====================================================
   ROLE
===================================================== */

.role-badge {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 8px;
    background: rgba(148,163,184,.08);
    color: var(--text);
    font-size: 10px;
    font-weight: 700;
}


/* =====================================================
   SECURITY STATUS
===================================================== */

.security-warning {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 8px;
    border-radius: 8px;
    background: rgba(239,68,68,.09);
    color: var(--red);
    font-size: 10px;
    font-weight: 700;
}

.security-normal {
    color: var(--muted);
    font-size: 10px;
}

.last-login {
    color: var(--muted);
    font-size: 10px;
}

.self-account {
    display: inline-flex;
    padding: 6px 8px;
    border-radius: 8px;
    background: rgba(148,163,184,.07);
    color: var(--muted);
    font-size: 10px;
}

.user-action-form {
    margin: 0;
}


/* =====================================================
   ROLE STRUCTURE
===================================================== */

.user-security-grid {
    margin-top: 18px;
}

.role-list {
    display: flex;
    flex-direction: column;
}

.role-item {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 12px 0;
    border-bottom: 1px solid rgba(148,163,184,.1);
}

.role-item:last-child {
    border-bottom: 0;
}

.role-dot {
    width: 9px;
    height: 9px;
    flex: 0 0 9px;
    margin-top: 5px;
    border-radius: 50%;
}

.role-dot.admin {
    background: var(--red);
}

.role-dot.event {
    background: var(--blue);
}

.role-dot.protocol {
    background: #7c3aed;
}

.role-dot.duty {
    background: #a16207;
}

.role-dot.official {
    background: var(--green);
}

.role-item strong {
    display: block;
    font-size: 12px;
}

.role-item small {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 10px;
    line-height: 1.4;
}


/* =====================================================
   SECURITY INFO
===================================================== */

.security-info {
    align-self: start;
}

.security-big-icon {
    width: 45px;
    height: 45px;
    display: grid;
    place-items: center;
    margin-bottom: 13px;
    border-radius: 13px;
    background: rgba(22,163,74,.1);
    color: var(--green);
    font-size: 20px;
    font-weight: 900;
}

.security-info h2 {
    margin: 0;
}

.security-info > p {
    margin: 8px 0 15px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.6;
}

.security-rule {
    padding: 11px 0;
    border-top: 1px solid rgba(148,163,184,.1);
}

.security-rule strong {
    display: block;
    font-size: 11px;
}

.security-rule span {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 10px;
    line-height: 1.4;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 1200px) {

    .user-stat-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    .user-filter {
        grid-template-columns:
            1fr 1fr 1fr;
    }

}

@media (max-width: 1000px) {

    .user-stat-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .user-filter {
        grid-template-columns: 1fr 1fr;
    }

}

@media (max-width: 720px) {

    .security-notice {
        align-items: flex-start;
    }

    .user-stat-grid {
        grid-template-columns: 1fr;
    }

    .user-filter {
        grid-template-columns: 1fr;
    }

    .user-filter .btn,
    .user-filter a {
        width: 100%;
    }

    .user-table th:nth-child(3),
    .user-table td:nth-child(3),
    .user-table th:nth-child(5),
    .user-table td:nth-child(5) {
        display: none;
    }

    .user-cell {
        min-width: 180px;
    }

}



/* FIX: Role Structure and Account Security cards */
.user-security-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr);
    gap: 24px;
    align-items: stretch;
    width: 100%;
    margin-top: 28px;
    box-sizing: border-box;
}

.user-security-grid > .panel {
    min-width: 0;
    width: 100%;
    margin: 0;
    box-sizing: border-box;
    overflow: hidden;
    border-radius: 22px;
}

.user-security-grid .panel-head {
    padding: 24px 28px;
    box-sizing: border-box;
}

.user-security-grid .role-list {
    padding: 0 28px 12px;
    min-width: 0;
    box-sizing: border-box;
}

.user-security-grid .role-item {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    padding: 18px 0;
    min-width: 0;
    box-sizing: border-box;
}

.user-security-grid .role-item > div {
    flex: 1;
    min-width: 0;
}

.user-security-grid .role-item strong,
.user-security-grid .role-item small {
    overflow-wrap: anywhere;
    line-height: 1.6;
}

.user-security-grid .security-info {
    align-self: stretch;
    padding: 28px;
    min-width: 0;
    box-sizing: border-box;
}

.user-security-grid .security-big-icon {
    margin-bottom: 18px;
}

.user-security-grid .security-info h2 {
    margin: 0 0 12px;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.user-security-grid .security-info > p {
    margin: 0 0 20px;
    line-height: 1.75;
    overflow-wrap: anywhere;
}

.user-security-grid .security-rule {
    padding: 17px 0;
    overflow-wrap: anywhere;
}

.user-security-grid .security-rule strong,
.user-security-grid .security-rule span {
    line-height: 1.6;
}

/* Tablet */
@media (max-width: 1000px) {
    .user-security-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 22px;
    }
}

/* Mobile */
@media (max-width: 720px) {
    .user-security-grid {
        margin-top: 22px;
        gap: 18px;
    }

    .user-security-grid .panel-head {
        padding: 20px;
    }

    .user-security-grid .role-list {
        padding: 0 20px 10px;
    }

    .user-security-grid .role-item {
        padding: 16px 0;
    }

    .user-security-grid .security-info {
        padding: 22px;
    }
}





</style>


<?php require __DIR__ . '/includes/footer.php'; ?>