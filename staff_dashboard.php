<?php
require_once __DIR__ . '/config/auth.php';
require_login();

// Guard: Staff members (or leads/supervisors/admins inspecting the view)
$pdo = db();
$user = current_user();
$userId = (int)$user['id'];

$page_title = 'Staff Dashboard | BUP Protocol';

// Handle duty status updates by staff
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. UPDATE DUTY STATUS (Acknowledge, Start, Complete)
    if ($action === 'update_duty_status') {
        $duty_id = (int)($_POST['duty_id'] ?? 0);
        $new_status = $_POST['status'] ?? 'Completed';

        // Allowed transitions for staff
        $allowed = ['Accepted', 'In Progress', 'Completed'];
        if (!in_array($new_status, $allowed, true)) {
            flash('error', 'Invalid status selection.');
            redirect(BASE_URL . '/staff_dashboard.php');
        }

        // Verify duty belongs to this staff member
        $chk = $pdo->prepare("SELECT id, title, status FROM duties WHERE id = ? AND (assigned_to = ? OR department = ?)");
        $chk->execute([$duty_id, $userId, $user['department']]);
        $duty = $chk->fetch();

        if ($duty) {
            $pdo->prepare("UPDATE duties SET status = ? WHERE id = ?")->execute([$new_status, $duty_id]);
            if (function_exists('notify_duty_status_change')) {
                notify_duty_status_change($duty_id, $new_status, $userId);
            }
            
            // Audit log
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'Duty Status Updated', ?)")
                ->execute([$userId, 'Staff ' . $user['name'] . ' updated duty "' . $duty['title'] . '" status to ' . $new_status]);

            flash('success', 'Status for duty <strong>' . e($duty['title']) . '</strong> updated to <strong>' . e($new_status) . '</strong>.');
            mongo_sync_table('duties');
            mongo_sync_table('audit_logs');
        } else {
            flash('error', 'You are not assigned to this duty.');
        }
        redirect(BASE_URL . '/staff_dashboard.php');
    }
}

// Fetch staff assigned duties
$dutiesStmt = $pdo->prepare("
    SELECT d.*, e.name as event_title, e.venue as event_venue,
           u_sup.name as supervisor_name
    FROM duties d
    LEFT JOIN events e ON e.id = d.event_id
    LEFT JOIN users u_sup ON u_sup.id = d.supervisor_id
    WHERE (d.assigned_to = ? OR (d.level = 'L5' AND (d.department = ? OR d.assigned_to = 0)))
    ORDER BY 
        CASE 
            WHEN d.status IN ('Assigned', 'Pending') THEN 1
            WHEN d.status = 'In Progress' THEN 2
            WHEN d.status = 'Accepted' THEN 3
            ELSE 4
        END,
        d.id DESC
");
$dutiesStmt->execute([$userId, $user['department'] ?? 'Protocol']);
$assignedDuties = $dutiesStmt->fetchAll();

// Statistics
$totalAssigned = count($assignedDuties);
$pendingDuties = 0;
$inProgressDuties = 0;
$completedDuties = 0;
foreach ($assignedDuties as $d) {
    if (in_array($d['status'], ['Pending', 'Assigned'], true)) $pendingDuties++;
    elseif (in_array($d['status'], ['In Progress', 'Ongoing', 'Accepted'], true)) $inProgressDuties++;
    elseif ($d['status'] === 'Completed') $completedDuties++;
}

// Upcoming events
$upcomingEvents = $pdo->query("
    SELECT id, name as title, event_type, venue, event_date, event_time, status
    FROM events
    WHERE status IN ('Upcoming', 'Ongoing')
    ORDER BY event_date ASC, event_time ASC
    LIMIT 5
")->fetchAll();

// Announcements
$announcements = $pdo->query("
    SELECT a.*, u.name as publisher_name, u.role as publisher_role
    FROM announcements a
    LEFT JOIN users u ON u.id = a.created_by
    ORDER BY a.created_at DESC
    LIMIT 5
")->fetchAll();

require __DIR__ . '/includes/header.php';
?>

        <!-- STAFF BANNER -->
        <div style="background:linear-gradient(135deg, #051d14 0%, #082d21 50%, #0e3f2f 100%); color:#fff; border-radius:14px; padding:24px 28px; margin-bottom:24px; border:1px solid rgba(212,175,55,0.25); box-shadow:0 8px 24px rgba(8,45,33,0.15);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
                <div>
                    <span style="font-size:11px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; color:#d4af37; display:block; margin-bottom:6px;">
                        DUTY EXECUTION DECK &bull; TIER L5
                    </span>
                    <h1 style="margin:0; font-size:24px; font-weight:800; color:#ffffff;">
                        Welcome, <?= e($user['name']) ?>
                    </h1>
                    <p style="margin:6px 0 0; color:#c7ded2; font-size:13px;">
                        Role: <strong><?= e($user['role']) ?></strong> &bull; Department: <strong><?= e($user['department']) ?></strong> &bull; Designation: <?= e($user['designation'] ?: 'Duty Staff') ?>
                    </p>
                </div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <span style="background:rgba(212,175,55,0.18); border:1px solid rgba(212,175,55,0.3); color:#fef3c7; padding:6px 14px; border-radius:20px; font-size:12px; font-weight:700;">
                        ✓ Operational Staff Active
                    </span>
                    <a href="<?= BASE_URL ?>/calendar.php" class="btn btn-sm btn-light" style="font-size:12px; font-weight:700; background:#eaf3ee; color:#082d21; border:1px solid #c2ded0;">
                        Event Calendar
                    </a>
                </div>
            </div>
        </div>

        <!-- STAT METRICS -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #6366f1;">
                <span class="eyebrow" style="color:#6366f1;">MY TOTAL ASSIGNED DUTIES</span>
                <div style="font-size:26px; font-weight:800; color:#082d21; margin-top:4px;"><?= $totalAssigned ?></div>
                <small style="color:#64748b;">Scheduled responsibilities</small>
            </div>
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #f59e0b;">
                <span class="eyebrow" style="color:#f59e0b;">ACTION REQUIRED / PENDING</span>
                <div style="font-size:26px; font-weight:800; color:#b45309; margin-top:4px;"><?= $pendingDuties ?></div>
                <small style="color:#64748b;">Awaiting acceptance or start</small>
            </div>
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #2563eb;">
                <span class="eyebrow" style="color:#2563eb;">IN PROGRESS / ACTIVE</span>
                <div style="font-size:26px; font-weight:800; color:#1e40af; margin-top:4px;"><?= $inProgressDuties ?></div>
                <small style="color:#64748b;">Currently being executed</small>
            </div>
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #10b981;">
                <span class="eyebrow" style="color:#10b981;">COMPLETED TASKS</span>
                <div style="font-size:26px; font-weight:800; color:#065f46; margin-top:4px;"><?= $completedDuties ?></div>
                <small style="color:#64748b;">Successfully fulfilled</small>
            </div>
        </div>

        <!-- MY ASSIGNED DUTIES -->
        <section class="panel" style="margin-bottom:24px;">
            <div class="panel-head">
                <div>
                    <span class="eyebrow" style="color:#4f46e5; font-weight:800;">DUTY ROSTER &bull; PERSONAL ASSIGNMENTS</span>
                    <h2>My Assigned Duties &amp; Schedules</h2>
                    <p style="margin:4px 0 0; font-size:13px; color:#64748b;">Acknowledge received orders, monitor time and location checkpoints, and report progress directly to your supervisor.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="user-table" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Duty / Task Name</th>
                            <th>Event &amp; Location</th>
                            <th>Schedule Date &amp; Time</th>
                            <th>Supervisor</th>
                            <th>Priority</th>
                            <th>Duty Status</th>
                            <th style="text-align:right;">Update Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assignedDuties)): ?>
                            <tr><td colspan="7" style="text-align:center; padding:30px; color:#64748b;">You have no active duties assigned at this time.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assignedDuties as $duty): ?>
                            <tr>
                                <td>
                                    <strong style="color:#082d21; font-size:14px;"><?= e($duty['title']) ?></strong>
                                    <?php if (!empty($duty['description'])): ?>
                                        <span class="table-subtext" style="color:#64748b; font-size:12px;"><?= e(mb_strimwidth($duty['description'], 0, 80, '...')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>📍 <?= e($duty['location'] ?: ($duty['event_venue'] ?? 'Campus Complex')) ?></strong>
                                    <?php if (!empty($duty['event_title'])): ?>
                                        <span class="table-subtext" style="color:#4f46e5; font-weight:600;">Event: <?= e($duty['event_title']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><strong><?= date('d M Y', strtotime($duty['duty_date'] ?: ($duty['created_at'] ?? 'now'))) ?></strong></div>
                                    <small style="color:#64748b;">
                                        <?= !empty($duty['start_time']) ? date('h:i A', strtotime($duty['start_time'])) : 'TBD' ?>
                                        <?= !empty($duty['end_time']) ? ' - ' . date('h:i A', strtotime($duty['end_time'])) : '' ?>
                                    </small>
                                </td>
                                <td>
                                    <?= e($duty['supervisor_name'] ?: ($duty['reports_to'] ?: 'Ground Supervisor')) ?>
                                </td>
                                <td>
                                    <?php if ($duty['priority'] === 'High'): ?>
                                        <span class="badge" style="background:#fee2e2; color:#991b1b; font-weight:700;">High</span>
                                    <?php elseif ($duty['priority'] === 'Medium'): ?>
                                        <span class="badge" style="background:#fef3c7; color:#92400e; font-weight:700;">Medium</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f1f5f9; color:#475569;">Normal</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($duty['status'] === 'Completed'): ?>
                                        <span class="badge" style="background:#d1fae5; color:#065f46; font-weight:700;">✓ Completed</span>
                                    <?php elseif ($duty['status'] === 'In Progress' || $duty['status'] === 'Ongoing'): ?>
                                        <span class="badge" style="background:#dbeafe; color:#1e40af; font-weight:700;">⚡ In Progress</span>
                                    <?php elseif ($duty['status'] === 'Accepted'): ?>
                                        <span class="badge" style="background:#e0e7ff; color:#3730a3; font-weight:700;">✓ Acknowledged</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fef3c7; color:#92400e; font-weight:700;">⏳ Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:right;">
                                    <form method="post" style="display:inline-flex; gap:6px;">
                                        <input type="hidden" name="action" value="update_duty_status">
                                        <input type="hidden" name="duty_id" value="<?= (int)$duty['id'] ?>">

                                        <?php if ($duty['status'] === 'Pending' || $duty['status'] === 'Assigned'): ?>
                                            <button type="submit" name="status" value="Accepted" class="btn btn-sm btn-primary" style="background:#4f46e5; border-color:#4f46e5; font-size:11px; padding:4px 8px;">
                                                Acknowledge
                                            </button>
                                        <?php elseif ($duty['status'] === 'Accepted'): ?>
                                            <button type="submit" name="status" value="In Progress" class="btn btn-sm btn-primary" style="background:#2563eb; border-color:#2563eb; font-size:11px; padding:4px 8px;">
                                                Start Duty
                                            </button>
                                        <?php elseif ($duty['status'] === 'In Progress' || $duty['status'] === 'Ongoing'): ?>
                                            <button type="submit" name="status" value="Completed" class="btn btn-sm btn-primary" style="background:#059669; border-color:#059669; font-size:11px; padding:4px 8px;">
                                                Mark Done
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#059669; font-weight:700; font-size:12px;">Finished</span>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- TWO-COLUMN GRID: UPCOMING EVENTS & ANNOUNCEMENTS -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
            <!-- UPCOMING EVENTS -->
            <section class="panel" style="margin-bottom:0;">
                <div class="panel-head">
                    <div>
                        <span class="eyebrow">CAMPUS SCHEDULE</span>
                        <h2>Upcoming Events</h2>
                    </div>
                    <a href="<?= BASE_URL ?>/calendar.php" class="btn btn-light btn-sm" style="font-size:11px;">Calendar &rarr;</a>
                </div>
                <div style="display:flex; flex-direction:column; gap:10px; padding:8px 0;">
                    <?php if (empty($upcomingEvents)): ?>
                        <p style="color:#64748b; font-size:13px;">No events scheduled.</p>
                    <?php else: ?>
                        <?php foreach ($upcomingEvents as $ev): ?>
                            <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                                <strong style="color:#082d21; font-size:13px; display:block;"><?= e($ev['title']) ?></strong>
                                <small style="color:#64748b; display:block; margin-top:2px;">
                                    📍 <?= e($ev['venue']) ?> &bull; 📅 <?= date('d M Y', strtotime($ev['event_date'])) ?> <?= date('h:i A', strtotime($ev['event_time'])) ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ANNOUNCEMENTS & INSTRUCTIONS -->
            <section class="panel" style="margin-bottom:0;">
                <div class="panel-head">
                    <div>
                        <span class="eyebrow">DIRECTIVES</span>
                        <h2>Announcements &amp; Instructions</h2>
                    </div>
                    <a href="<?= BASE_URL ?>/notifications.php" class="btn btn-light btn-sm" style="font-size:11px;">All Notices &rarr;</a>
                </div>
                <div style="display:flex; flex-direction:column; gap:10px; padding:8px 0;">
                    <?php if (empty($announcements)): ?>
                        <p style="color:#64748b; font-size:13px;">No active announcements.</p>
                    <?php else: ?>
                        <?php foreach ($announcements as $an): ?>
                            <div style="padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <strong style="color:#082d21; font-size:13px;"><?= e($an['title']) ?></strong>
                                    <small style="color:#64748b;"><?= date('d M', strtotime($an['created_at'])) ?></small>
                                </div>
                                <p style="margin:4px 0 0; font-size:12px; color:#475569; line-height:1.4;"><?= e(mb_strimwidth($an['message'], 0, 90, '...')) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>

<?php require __DIR__ . '/includes/footer.php'; ?>
