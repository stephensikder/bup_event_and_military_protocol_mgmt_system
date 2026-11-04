<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$u = current_user();
$pdo = db();
$proto_lvl = $u['protocol_level'] ?? '';
$is_admin = has_role('Admin') || is_event_director();

// Echelon configuration mapping
$echelon_map = [
    'Higher Command' => [
        'level_num' => 1,
        'title' => 'Higher Command Strategic Authority',
        'sub_level' => 'Division',
        'sub_code' => 'L2',
        'sub_unit_code' => 'DIV-09-INF',
        'sub_user_id' => 12,
        'description' => 'Exercise supreme military protocol authority, strategic planning, directive issuance, and critical issue resolution.',
        'target_label' => 'Target Division Commander',
        'placeholder' => 'e.g. 9th Infantry Division State Ceremonial Mobilization Directive',
        'default_location' => 'Division Headquarters & Mirpur Area',
        'tabs' => [
            'overview' => 'Command & Duties',
            'strategic_planning' => 'Strategic Duty Planning',
            'critical_issues' => 'Critical Issue Review'
        ]
    ],
    'Division' => [
        'level_num' => 2,
        'title' => 'Division Tactical Command',
        'sub_level' => 'Brigade',
        'sub_code' => 'L3',
        'sub_unit_code' => 'BGD-71-INF',
        'sub_user_id' => 13,
        'description' => 'Oversee 71st Infantry Brigade command account, operational readiness, and inter-brigade coordination.',
        'target_label' => 'Target Brigade Commander',
        'placeholder' => 'e.g. 71st Brigade Cordon & Saluting Battery Setup',
        'default_location' => 'Parade Ground & Main Cordon',
        'tabs' => [
            'overview' => 'Brigade Accounts & Duties',
            'inter_brigade' => 'Inter-Brigade Coordination',
            'brigade_reports' => 'Pending Brigade Reports'
        ]
    ],
    'Brigade' => [
        'level_num' => 3,
        'title' => 'Brigade Command Headquarters',
        'sub_level' => 'Battalion',
        'sub_code' => 'L4',
        'sub_unit_code' => 'BTN-01-EBR',
        'sub_user_id' => 14,
        'description' => 'Mobilize 1st East Bengal Battalion for ceremonial drill, quarter guard turnout, and ceremonial preparation tracking.',
        'target_label' => 'Target Battalion CO',
        'placeholder' => 'e.g. 1st East Bengal 100-Man Presidential Contingent Muster',
        'default_location' => 'BUP Parade Ground Dais',
        'tabs' => [
            'overview' => 'Battalion Accounts & Duties',
            'ceremonial_prep' => 'Ceremonial Preparation Tracker',
            'battalion_coordination' => 'Battalion Coordination'
        ]
    ],
    'Battalion' => [
        'level_num' => 4,
        'title' => 'Battalion Tactical Command',
        'sub_level' => 'Company',
        'sub_code' => 'L5',
        'sub_unit_code' => 'COY-ALPHA-EBR',
        'sub_user_id' => 15,
        'description' => 'Deploy Alpha Company for ceremonial escort, perimeter cordons, and coordinate civil-military event integration.',
        'target_label' => 'Target Company Commander',
        'placeholder' => 'e.g. Alpha Company VIP Inner Perimeter & Dais Cordon',
        'default_location' => 'Auditorium Main Concourse',
        'tabs' => [
            'overview' => 'Company Accounts & Duties',
            'company_progress' => 'Company Progress Monitoring',
            'civil_integration' => 'Civil Integration Console'
        ]
    ],
    'Company' => [
        'level_num' => 5,
        'title' => 'Company Command Sector',
        'sub_level' => 'Platoon',
        'sub_code' => 'L6',
        'sub_unit_code' => 'PLT-01-ALPHA',
        'sub_user_id' => 16,
        'description' => 'Assign tactical gate posts, walkie-talkie checks, and allocate troop gear to Platoon Commanders.',
        'target_label' => 'Target Platoon Commander',
        'placeholder' => 'e.g. 1st Platoon Guard of Honour Gate Post & Cordon',
        'default_location' => 'BUP Gate 1 & Protocol Corridor',
        'tabs' => [
            'overview' => 'Platoon Accounts & Duties',
            'platoon_progress' => 'Platoon Progress Tracking',
            'resource_allocation' => 'Resource Allocation'
        ]
    ],
    'Platoon' => [
        'level_num' => 6,
        'title' => 'Platoon Command Post',
        'sub_level' => 'Section',
        'sub_code' => 'L7',
        'sub_unit_code' => 'SEC-01-PLT1',
        'sub_user_id' => 17,
        'description' => 'Brief Section Commanders on ceremonial turnout standards, sentry points, and tactical communication channels.',
        'target_label' => 'Target Section Commander',
        'placeholder' => 'e.g. Section 1 VIP Dais Guard & Quarter Sentry',
        'default_location' => 'Main Dais Stage Right',
        'tabs' => [
            'overview' => 'Section Accounts & Duties',
            'section_progress' => 'Section Progress Tracking'
        ]
    ],
    'Section' => [
        'level_num' => 7,
        'title' => 'Section Command & Sentry Authority',
        'sub_level' => 'Individual Soldier',
        'sub_code' => 'L8',
        'sub_unit_code' => 'SLD-ESCORT-88',
        'sub_user_id' => 18,
        'description' => 'Direct individual soldiers to specific ceremonial sentry posts, inspect completion evidence, and grant official verification.',
        'target_label' => 'Target Soldier',
        'placeholder' => 'e.g. Ceremonial Honor Sentry Post #1 - Dias Left',
        'default_location' => 'VIP Dais Guard Post 1',
        'tabs' => [
            'overview' => 'Assigned Soldier Accounts',
            'soldier_evidence' => 'Soldier Evidence & Sign-Off'
        ]
    ],
    'Individual Soldier' => [
        'level_num' => 8,
        'title' => 'Soldier Duty Pass & Personal Operational Desk',
        'sub_level' => 'Self',
        'sub_code' => 'L8',
        'sub_unit_code' => 'SLD-ESCORT-88',
        'sub_user_id' => 18,
        'description' => 'Personal ceremonial sentry credentials, standing orders, active post monitoring, and duty evidence submission.',
        'target_label' => 'Soldier Self Desk',
        'placeholder' => '',
        'default_location' => 'Designated Sentry Post',
        'tabs' => [
            'overview' => 'Ceremonial Duty Pass',
            'evidence_submit' => 'Submit Completion Evidence'
        ]
    ]
];

// Determine active echelon to display
$active_echelon = $proto_lvl;
if (isset($_GET['echelon']) && isset($echelon_map[$_GET['echelon']])) {
    if ($is_admin || $proto_lvl === 'Higher Command') {
        $active_echelon = $_GET['echelon'];
    }
}

if (!isset($echelon_map[$active_echelon])) {
    if ($is_admin || $proto_lvl === 'Higher Command') {
        $active_echelon = 'Higher Command';
    } else {
        flash('error', 'Unauthorized military echelon.');
        redirect(BASE_URL . '/protocol.php');
    }
}

$cfg = $echelon_map[$active_echelon];
$active_tab = $_GET['tab'] ?? 'overview';
if (!isset($cfg['tabs'][$active_tab])) {
    $active_tab = array_key_first($cfg['tabs']);
}

$page_title = $cfg['title'] . ' | ' . ($cfg['tabs'][$active_tab] ?? 'Management');

// =========================================================================
// POST ACTIONS (DUTY PROGRESSION, EVIDENCE, DIRECTIVES, ISSUES, RESOURCES)
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Update My Own Assigned Duty Status Progress
    if ($action === 'update_my_duty_status') {
        $duty_id = (int)($_POST['duty_id'] ?? 0);
        $new_status = in_array($_POST['status'] ?? '', ['Assigned', 'Accepted', 'In Progress', 'Completed'], true) ? $_POST['status'] : 'In Progress';

        if ($duty_id > 0) {
            $pdo->prepare("UPDATE duties SET status = ? WHERE id = ?")->execute([$new_status, $duty_id]);
            if (function_exists('notify_duty_status_change')) {
                notify_duty_status_change($duty_id, $new_status, (int)$u['id']);
            }
            $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)")
                ->execute([$u['id'], 'DUTY STATUS UPDATED', "Set duty #{$duty_id} status to {$new_status}"]);

            if (function_exists('mongo_sync_table')) {
                mongo_sync_table('duties');
            }
            flash('success', "Your duty status updated to: {$new_status}");
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=' . urlencode($active_tab) . (isset($_GET['echelon']) ? '&echelon=' . urlencode($_GET['echelon']) : ''));
    }

    // Action 2: Toggle Subordinate Account Status (Active/Inactive)
    if ($action === 'toggle_account_status') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_status = ($_POST['status'] ?? '') === 'Active' ? 'Active' : 'Inactive';

        if ($target_id > 0) {
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $target_id]);

            $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)")
                ->execute([$u['id'], 'SUBORDINATE ACCOUNT STATUS CHANGE', "Changed status of user #{$target_id} to {$new_status}"]);

            if (function_exists('mongo_sync_table')) {
                mongo_sync_table('users');
            }
            flash('success', "Subordinate account status updated to {$new_status}.");
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=' . urlencode($active_tab) . (isset($_GET['echelon']) ? '&echelon=' . urlencode($_GET['echelon']) : ''));
    }

    // Action 3: Delegate Echelon Duty to Subordinate
    if ($action === 'delegate_duty') {
        $title = trim($_POST['title'] ?? '');
        $target_user_id = (int)($_POST['target_user_id'] ?? $cfg['sub_user_id']);
        $sub_level = trim($_POST['sub_level'] ?? $cfg['sub_code']);
        $location = trim($_POST['location'] ?? $cfg['default_location']);
        $time_slot = trim($_POST['time_slot'] ?? 'Ceremony Duration');
        $priority = trim($_POST['priority'] ?? 'High');
        $event_id = (int)($_POST['event_id'] ?? 0);

        if ($event_id === 0) {
            $active_ev = $pdo->query("SELECT id FROM events WHERE status IN ('Upcoming', 'Ongoing') ORDER BY event_date ASC LIMIT 1")->fetch();
            $event_id = $active_ev ? (int)$active_ev['id'] : 1;
        }

        if ($title !== '' && $target_user_id > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO duties (event_id, title, department, level, location, time_slot, priority, assigned_to, assigned_by, status, created_at)
                VALUES (?, ?, 'Protocol', ?, ?, ?, ?, ?, ?, 'Assigned', NOW())
            ");
            $stmt->execute([$event_id, $title, $sub_level, $location, $time_slot, $priority, $target_user_id, $u['id']]);
            $dutyId = (int)$pdo->lastInsertId();

            if (function_exists('notify_duty_assignment')) {
                notify_duty_assignment($dutyId, $title, 'Protocol', $sub_level, $target_user_id, (int)$u['id']);
            }

            $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)")
                ->execute([$u['id'], 'Military Directive Delegated', "Assigned directive '{$title}' to user #{$target_user_id}"]);

            if (function_exists('mongo_sync_table')) {
                mongo_sync_table('duties');
            }
            flash('success', "Operational directive '{$title}' successfully assigned to subordinate.");
        } else {
            flash('error', 'Directive title is required.');
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=' . urlencode($active_tab) . (isset($_GET['echelon']) ? '&echelon=' . urlencode($_GET['echelon']) : ''));
    }

    // Action 4: Soldier Uploads Duty Completion Evidence
    if ($action === 'submit_soldier_evidence') {
        $duty_id = (int)($_POST['duty_id'] ?? 0);
        $evidence_text = trim($_POST['evidence_text'] ?? '');
        $evidence_file = null;

        if (!empty($_FILES['evidence_file']['name']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['evidence_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
            if (in_array($ext, $allowed, true)) {
                $uploadDir = __DIR__ . '/assets/uploads/evidence/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fileName = 'evidence_' . $u['id'] . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                    $evidence_file = 'assets/uploads/evidence/' . $fileName;
                }
            }
        }

        if ($evidence_text !== '') {
            $pdo->prepare("
                INSERT INTO military_evidence_reports (duty_id, soldier_id, section_commander_id, evidence_text, evidence_file, status, created_at)
                VALUES (?, ?, 17, ?, ?, 'Submitted', NOW())
            ")->execute([$duty_id, $u['id'], $evidence_text, $evidence_file]);

            if ($duty_id > 0) {
                $pdo->prepare("UPDATE duties SET status = 'Completed', notes = CONCAT(COALESCE(notes,''), ' [Evidence Submitted]') WHERE id = ?")
                    ->execute([$duty_id]);
            }

            if (function_exists('add_user_notification')) {
                add_user_notification(17, 'Soldier Duty Completion Evidence: ' . $u['name'], 'Corporal N. Islam has submitted post completion report for verification.', 'duty_allocated', BASE_URL . '/manage_subordinates.php?tab=soldier_evidence');
            }

            $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)")
                ->execute([$u['id'], 'Soldier Evidence Submitted', "Soldier uploaded post execution report for duty #{$duty_id}"]);

            flash('success', 'Post completion evidence successfully uploaded and dispatched to Section Commander for sign-off.');
        } else {
            flash('error', 'Execution summary remarks are required.');
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=evidence_submit');
    }

    // Action 5: Section Commander Signs Off / Verifies Soldier Evidence
    if ($action === 'verify_soldier_evidence') {
        $rep_id = (int)($_POST['report_id'] ?? 0);
        $decision = $_POST['decision'] ?? 'Verified';
        $verifier_notes = trim($_POST['verifier_notes'] ?? '');

        if ($rep_id > 0) {
            $pdo->prepare("UPDATE military_evidence_reports SET status = ?, verifier_notes = ?, verified_at = NOW() WHERE id = ?")
                ->execute([$decision, $verifier_notes, $rep_id]);

            $rep = $pdo->query("SELECT * FROM military_evidence_reports WHERE id = {$rep_id}")->fetch();
            if ($rep && function_exists('add_user_notification')) {
                add_user_notification((int)$rep['soldier_id'], 'Duty Evidence ' . $decision, 'Section Commander has reviewed your post evidence: ' . $decision . '. Remarks: ' . $verifier_notes, 'system', BASE_URL . '/manage_subordinates.php');
            }

            flash('success', "Soldier duty completion report marked as: {$decision}");
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=soldier_evidence');
    }

    // Action 6: Submit / Resolve Critical Issue
    if ($action === 'submit_critical_issue') {
        $title = trim($_POST['title'] ?? '');
        $severity = trim($_POST['severity'] ?? 'Critical');
        $desc = trim($_POST['description'] ?? '');

        if ($title !== '' && $desc !== '') {
            $pdo->prepare("
                INSERT INTO military_critical_issues (title, reported_by, reported_level, severity, description, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'Open', NOW())
            ")->execute([$title, $u['id'], $proto_lvl, $severity, $desc]);

            // Notify Higher Command (User 11)
            if (function_exists('add_user_notification')) {
                add_user_notification(11, 'Critical Military Incident: ' . $title, "Escalated by {$proto_lvl}: {$desc}", 'announcement', BASE_URL . '/manage_subordinates.php?tab=critical_issues');
            }

            flash('success', 'Critical military incident successfully escalated to Higher Command.');
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=critical_issues');
    }

    if ($action === 'resolve_critical_issue') {
        $issue_id = (int)($_POST['issue_id'] ?? 0);
        $decision = trim($_POST['command_decision'] ?? '');
        $status = $_POST['status'] ?? 'Resolved';

        if ($issue_id > 0) {
            $pdo->prepare("UPDATE military_critical_issues SET command_decision = ?, status = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?")
                ->execute([$decision, $status, $u['id'], $issue_id]);
            flash('success', "Command decision promulgated for Incident #{$issue_id}.");
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=critical_issues');
    }

    // Action 7: Inter-Brigade Coordination Meeting / Entry
    if ($action === 'add_inter_coordination') {
        $title = trim($_POST['title'] ?? '');
        $unit_a = trim($_POST['unit_a'] ?? '71st Infantry Brigade');
        $unit_b = trim($_POST['unit_b'] ?? 'Division Artillery / MP');
        $type = trim($_POST['coordination_type'] ?? 'Joint Security & Route');
        $details = trim($_POST['details'] ?? '');
        $schedule = !empty($_POST['meeting_schedule']) ? $_POST['meeting_schedule'] : date('Y-m-d H:i:s', strtotime('+1 day'));

        if ($title !== '') {
            $pdo->prepare("
                INSERT INTO military_inter_coordinations (echelon_level, title, unit_a, unit_b, coordination_type, meeting_schedule, details, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Scheduled', NOW())
            ")->execute([$active_echelon, $title, $unit_a, $unit_b, $type, $schedule, $details]);
            flash('success', 'Coordination schedule successfully recorded.');
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=' . urlencode($active_tab));
    }

    // Action 8: Update Ceremonial Prep Item
    if ($action === 'update_ceremonial_prep') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $readiness = (int)($_POST['readiness_percentage'] ?? 0);
        $st = $_POST['status'] ?? 'Ready';
        $notes = trim($_POST['notes'] ?? '');

        if ($item_id > 0) {
            $pdo->prepare("UPDATE military_ceremonial_prep SET readiness_percentage = ?, status = ?, notes = ? WHERE id = ?")
                ->execute([$readiness, $st, $notes, $item_id]);
            flash('success', 'Ceremonial checkpoint readiness updated.');
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=ceremonial_prep');
    }

    // Action 9: Resource Allocation
    if ($action === 'add_resource_allocation') {
        $type = trim($_POST['resource_type'] ?? 'Equipment');
        $name = trim($_POST['item_name'] ?? '');
        $qty = (int)($_POST['quantity'] ?? 1);
        $target = trim($_POST['allocated_to'] ?? '1st Protocol Platoon');
        $notes = trim($_POST['notes'] ?? '');

        if ($name !== '') {
            $pdo->prepare("
                INSERT INTO military_resource_allocations (resource_type, item_name, quantity, allocated_to, request_status, notes, created_at)
                VALUES (?, ?, ?, ?, 'Approved', ?, NOW())
            ")->execute([$type, $name, $qty, $target, $notes]);
            flash('success', "Resource '{$name}' allocated to {$target}.");
        }
        redirect(BASE_URL . '/manage_subordinates.php?tab=resource_allocation');
    }
}

// =========================================================================
// DATA FETCHING: MY DUTIES, SUBORDINATE DUTIES, AND ECHELON MODULE DATA
// =========================================================================

// 1. My Assigned Duties (Duties for current user or current echelon)
$my_duties = $pdo->prepare("
    SELECT d.*, e.name AS event_name, u_by.name AS assigner_name
    FROM duties d
    LEFT JOIN events e ON e.id = d.event_id
    LEFT JOIN users u_by ON u_by.id = d.assigned_by
    WHERE d.assigned_to = ? OR (d.department = 'Protocol' AND d.level = ?)
    ORDER BY d.id DESC
    LIMIT 6
");
$my_level_code = 'L' . ($cfg['level_num'] ?? 1);
$my_duties->execute([$u['id'], $my_level_code]);
$my_duties = $my_duties->fetchAll();

// 2. Subordinate Accounts & Unit
$sub_users = [];
$sub_unit = null;
if ($active_echelon === 'Individual Soldier') {
    $sub_users = $pdo->query("SELECT * FROM users WHERE id = {$u['id']}")->fetchAll();
    $sub_unit = $pdo->query("SELECT * FROM military_protocol_units WHERE level = 'Individual Soldier' LIMIT 1")->fetch();
} else {
    $target_level = $cfg['sub_level'];
    $sub_users_stmt = $pdo->prepare("
        SELECT u.*, mpu.code as unit_code, mpu.name as unit_name, mpu.hq_location
        FROM users u
        LEFT JOIN military_protocol_units mpu ON mpu.commander_id = u.id
        WHERE u.protocol_level = ? OR u.department LIKE ?
        ORDER BY u.id ASC
    ");
    $sub_users_stmt->execute([$target_level, '%' . $target_level . '%']);
    $sub_users = $sub_users_stmt->fetchAll();

    $sub_unit = $pdo->prepare("SELECT * FROM military_protocol_units WHERE level = ? LIMIT 1");
    $sub_unit->execute([$target_level]);
    $sub_unit = $sub_unit->fetch();
}

// 3. Subordinate Echelon Allocated Duties (Duties assigned to subordinate level)
$user_ids = !empty($sub_users) ? array_column($sub_users, 'id') : [$cfg['sub_user_id']];
$in_clause = implode(',', array_map('intval', $user_ids));

$subordinate_duties = $pdo->query("
    SELECT d.*, e.name AS event_name, u.name AS assignee_name, u.designation AS assignee_designation
    FROM duties d
    LEFT JOIN events e ON e.id = d.event_id
    LEFT JOIN users u ON u.id = d.assigned_to
    WHERE (d.assigned_to IN ({$in_clause}) OR (d.department = 'Protocol' AND d.level = '{$cfg['sub_code']}'))
    ORDER BY d.id DESC
    LIMIT 12
")->fetchAll();

// 4. Module-specific data fetching
$critical_issues = $pdo->query("
    SELECT i.*, u.name AS reporter_name, u.designation AS reporter_designation
    FROM military_critical_issues i
    LEFT JOIN users u ON u.id = i.reported_by
    ORDER BY i.id DESC
")->fetchAll();

$inter_coordinations = $pdo->query("
    SELECT * FROM military_inter_coordinations ORDER BY id DESC
")->fetchAll();

$ceremonial_prep = $pdo->query("
    SELECT * FROM military_ceremonial_prep ORDER BY id ASC
")->fetchAll();

$resource_allocations = $pdo->query("
    SELECT * FROM military_resource_allocations ORDER BY id DESC
")->fetchAll();

$soldier_evidence_list = $pdo->query("
    SELECT r.*, d.title AS duty_title, d.location AS post_location, u.name AS soldier_name, u.designation AS soldier_designation
    FROM military_evidence_reports r
    LEFT JOIN duties d ON d.id = r.duty_id
    LEFT JOIN users u ON u.id = r.soldier_id
    ORDER BY r.id DESC
")->fetchAll();

$events = $pdo->query("SELECT id, name FROM events WHERE status IN ('Upcoming', 'Ongoing') ORDER BY event_date ASC")->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<!-- PAGE HEADER -->
<div class="page-head" style="margin-bottom:20px;">
    <div>
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
            <span class="badge" style="background:#082d21; color:#f5d98d; border:1px solid #d4af37; font-family:monospace; font-weight:800; font-size:11px;">
                ECHELON LEVEL <?= $cfg['level_num'] ?>
            </span>
            <span class="eyebrow" style="color:#d4af37; font-weight:800; letter-spacing:1.5px; margin:0;">
                TACTICAL COMMAND &amp; ECHELON OPERATIONS
            </span>
        </div>
        <h1 style="color:#082d21; margin:2px 0 6px; font-size:26px;">
            <?= e($cfg['title']) ?>
        </h1>
        <p style="color:#5e776a; font-size:13.5px; margin:0;">
            <?= e($cfg['description']) ?>
        </p>
    </div>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <a class="btn btn-dark" href="<?= BASE_URL ?>/protocol.php" style="background:#082d21; border:1px solid #d4af37; color:#fff; font-size:12px; font-weight:700;">
            &larr; Command Hierarchy
        </a>
        <a class="btn btn-light" href="<?= BASE_URL ?>/calendar.php" style="font-size:12px; font-weight:700; background:#f4fbf7; border:1px solid #94d3a2; color:#082d21;">
            📅 Event Calendar
        </a>
        <a class="btn btn-light" href="<?= BASE_URL ?>/reports.php?tab=protocol" style="font-size:12px; font-weight:700; background:#f4fbf7; border:1px solid #94d3a2; color:#082d21;">
            📊 Protocol Report
        </a>
    </div>
</div>

<?php if ($is_admin || $proto_lvl === 'Higher Command'): ?>
    <!-- ADMIN / HIGHER COMMAND ECHELON SWITCHER -->
    <div style="background:#ffffff; border:1px solid #dce6e1; border-radius:12px; padding:10px 16px; margin-bottom:18px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <span style="font-size:11px; font-weight:800; color:#5e776a; text-transform:uppercase; letter-spacing:1px;">COMMAND SWITCHER:</span>
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <?php foreach ($echelon_map as $echKey => $echVal): ?>
                <a href="<?= BASE_URL ?>/manage_subordinates.php?echelon=<?= urlencode($echKey) ?>"
                   style="padding:5px 11px; border-radius:6px; font-size:11.5px; font-weight:700; text-decoration:none; <?= $active_echelon === $echKey ? 'background:#082d21; color:#f5d98d; border:1px solid #d4af37;' : 'background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;' ?>">
                    Level <?= $echVal['level_num'] ?>: <?= e($echKey) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- ECHELON SUB-MODULE NAVIGATION TABS -->
<div style="display:flex; gap:8px; border-bottom:2px solid #e2e8f0; margin-bottom:22px; overflow-x:auto; padding-bottom:2px;">
    <?php foreach ($cfg['tabs'] as $tKey => $tLabel): ?>
        <?php $isActive = ($active_tab === $tKey); ?>
        <a href="<?= BASE_URL ?>/manage_subordinates.php?tab=<?= urlencode($tKey) ?><?= isset($_GET['echelon']) ? '&echelon=' . urlencode($_GET['echelon']) : '' ?>"
           style="padding:10px 16px; font-size:13px; font-weight:800; text-decoration:none; white-space:nowrap; border-bottom:3px solid <?= $isActive ? '#082d21' : 'transparent' ?>; color:<?= $isActive ? '#082d21' : '#64748b' ?>; background:<?= $isActive ? '#eaf3ee' : 'transparent' ?>; border-radius:8px 8px 0 0; transition:all 0.15s;">
            <?= e($tLabel) ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- =========================================================================
     SECTION 1: MY OWN OPERATIONAL DUTIES & LIVE STATUS PROGRESSION
========================================================================== -->
<section class="panel" style="margin-bottom:24px; border:2px solid #082d21; background:#ffffff; border-radius:14px; padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px; border-bottom:1px solid #e2e8f0; padding-bottom:10px;">
        <div>
            <span style="background:#082d21; color:#f5d98d; font-size:10px; font-weight:900; padding:2px 8px; border-radius:4px; font-family:monospace;">
                MY OPERATIONAL ROSTER
            </span>
            <h3 style="margin:4px 0 0; color:#082d21; font-size:18px;">
                Directives &amp; Tasks Assigned to My Echelon (Level <?= $cfg['level_num'] ?>)
            </h3>
            <small style="color:#5e776a;">Update your task progress in real time for chain of command visibility.</small>
        </div>
        <span class="badge completed" style="background:#dcfce7; color:#15803d; font-weight:800;">
            <?= count($my_duties) ?> Active Directives
        </span>
    </div>

    <?php if (empty($my_duties)): ?>
        <div style="padding:22px; text-align:center; color:#64748b; background:#f8fafc; border-radius:10px; font-size:12.5px;">
            No direct operational tasks currently pending for this account. High-level command oversight active.
        </div>
    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(310px, 1fr)); gap:14px;">
            <?php foreach ($my_duties as $md): ?>
                <?php
                $st = $md['status'];
                $stColor = ($st === 'Completed') ? '#15803d' : (($st === 'In Progress') ? '#b45309' : '#0369a1');
                $stBg = ($st === 'Completed') ? '#dcfce7' : (($st === 'In Progress') ? '#fef3c7' : '#e0f2fe');
                ?>
                <div style="border:1px solid #cbd5e1; border-radius:10px; padding:14px; background:#fcfdfd;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                        <strong style="color:#082d21; font-size:14px;"><?= e($md['title']) ?></strong>
                        <span class="badge" style="background:<?= $stBg ?>; color:<?= $stColor ?>; font-weight:800; font-size:10px; padding:3px 8px;">
                            <?= e($st) ?>
                        </span>
                    </div>
                    <div style="font-size:11.5px; color:#64748b; margin-bottom:12px; line-height:1.4;">
                        <div>📍 Post: <strong style="color:#334155;"><?= e($md['location'] ?: 'Command Post') ?></strong></div>
                        <div>📅 Date: <strong style="color:#334155;"><?= !empty($md['duty_date']) ? date('d M Y', strtotime($md['duty_date'])) : 'Official Event' ?></strong></div>
                        <div>🎖️ Issued By: <strong style="color:#334155;"><?= e($md['assigner_name'] ?: 'Commanding Authority') ?></strong></div>
                    </div>

                    <!-- 1-Click Status Progression Control -->
                    <div style="background:#f1f5f9; padding:8px 10px; border-radius:6px; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:10.5px; font-weight:800; color:#475569; text-transform:uppercase;">Set Progress:</span>
                        <div style="display:flex; gap:6px;">
                            <?php if ($st === 'Assigned'): ?>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="update_my_duty_status">
                                    <input type="hidden" name="duty_id" value="<?= $md['id'] ?>">
                                    <input type="hidden" name="status" value="Accepted">
                                    <button type="submit" class="btn btn-sm" style="background:#0284c7; color:#fff; font-size:11px; font-weight:700; padding:3px 8px; border-radius:4px;">
                                        ✓ Accept
                                    </button>
                                </form>
                            <?php elseif ($st === 'Accepted'): ?>
                                <form method="post" style="margin:0;">
                                    <input type="hidden" name="action" value="update_my_duty_status">
                                    <input type="hidden" name="duty_id" value="<?= $md['id'] ?>">
                                    <input type="hidden" name="status" value="In Progress">
                                    <button type="submit" class="btn btn-sm" style="background:#d97706; color:#fff; font-size:11px; font-weight:700; padding:3px 8px; border-radius:4px;">
                                        ⚡ In Progress
                                    </button>
                                </form>
                            <?php elseif ($st === 'In Progress'): ?>
                                <?php if ($active_echelon === 'Individual Soldier'): ?>
                                    <a href="<?= BASE_URL ?>/manage_subordinates.php?tab=evidence_submit" class="btn btn-sm" style="background:#16a34a; color:#fff; font-size:11px; font-weight:700; padding:3px 8px; border-radius:4px; text-decoration:none;">
                                        ⭐ Upload Evidence &rarr;
                                    </a>
                                <?php else: ?>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="action" value="update_my_duty_status">
                                        <input type="hidden" name="duty_id" value="<?= $md['id'] ?>">
                                        <input type="hidden" name="status" value="Completed">
                                        <button type="submit" class="btn btn-sm" style="background:#16a34a; color:#fff; font-size:11px; font-weight:700; padding:3px 8px; border-radius:4px;">
                                            ⭐ Mark Done
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="font-size:11px; color:#15803d; font-weight:800;">✓ Verified Completed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- =========================================================================
     TAB CONTENT ROUTING ACCORDING TO CURRENT TAB & LEVEL
========================================================================== -->

<?php if ($active_tab === 'strategic_planning'): ?>
    <!-- 1. STRATEGIC DUTY PLANNING (L1 HIGHER COMMAND) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head">
            <div>
                <span class="eyebrow" style="color:#d4af37; font-weight:800;">HIGHER COMMAND L1</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Strategic Duty Planning &amp; Operational Readiness</h3>
                <p>Formulate broad operational directives, set command priorities, and direct Division (L2) deployment.</p>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; margin-bottom:20px;">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                <span style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase;">1. Readiness Overview</span>
                <strong style="display:block; font-size:22px; color:#082d21; margin-top:4px;">98.5%</strong>
                <small style="color:#16a34a; font-weight:700;">8 Echelons Operational</small>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                <span style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase;">2. Active Formations</span>
                <strong style="display:block; font-size:22px; color:#082d21; margin-top:4px;">9th Inf Division</strong>
                <small style="color:#0284c7; font-weight:700;">Strategic Lead Assigned</small>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                <span style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase;">3. Operational Priority</span>
                <strong style="display:block; font-size:22px; color:#b45309; margin-top:4px;">High / Urgent</strong>
                <small style="color:#b45309; font-weight:700;">Ceremonial Turnout &amp; Escort</small>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                <span style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase;">4. Total Guard Strength</span>
                <strong style="display:block; font-size:22px; color:#082d21; margin-top:4px;">96 Soldiers</strong>
                <small style="color:#16a34a; font-weight:700;">Full Contingent Ready</small>
            </div>
        </div>

        <!-- Issue Strategic Directive Form -->
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px; padding:18px;">
            <h4 style="margin:0 0 8px; color:#166534; font-size:15px;">🎖️ Issue Strategic Directive to Division (L2)</h4>
            <p style="margin:0 0 14px; font-size:12px; color:#475569;">Higher Command promulgates strategic orders to Division Commander. Division delegates tactical execution to Brigades.</p>
            <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; align-items:end;">
                <input type="hidden" name="action" value="delegate_duty">
                <input type="hidden" name="sub_level" value="L2">
                <input type="hidden" name="target_user_id" value="12">
                <div style="grid-column: span 2;">
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Strategic Directive Title *</label>
                    <input type="text" name="title" required placeholder="e.g. State Guard of Honour &amp; Convoy Route Protocol Plan" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px 10px; font-size:12px;">
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Target Commander</label>
                    <input type="text" disabled value="Major General M. Rahman (Division CO)" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px; background:#f1f5f9;">
                </div>
                <div>
                    <button type="submit" class="btn btn-dark" style="background:#082d21; border:1px solid #d4af37; color:#f5d98d; font-weight:700; font-size:12px; padding:9px; width:100%;">
                        Promulgate Directive &rarr;
                    </button>
                </div>
            </form>
        </div>
    </section>

<?php elseif ($active_tab === 'critical_issues'): ?>
    <!-- 2. CRITICAL ISSUE REVIEW (L1 HIGHER COMMAND) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <span class="eyebrow" style="color:#dc2626; font-weight:800;">COMMAND OVERSIGHT</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Critical Issue &amp; Incident Review Desk</h3>
                <p>Review escalations from subordinate echelons L2 through L8, assess severity, and provide binding command decisions.</p>
            </div>
            <button type="button" onclick="document.getElementById('issueEscalateForm').style.display = document.getElementById('issueEscalateForm').style.display === 'none' ? 'block' : 'none'" class="btn btn-sm" style="background:#dc2626; color:#fff; font-weight:700;">
                + Log / Escalate Incident
            </button>
        </div>

        <div id="issueEscalateForm" style="display:none; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:16px; margin-bottom:20px;">
            <h4 style="margin:0 0 10px; color:#991b1b; font-size:14px;">🚨 Log Command Incident / Escalation</h4>
            <form method="post" style="display:flex; flex-direction:column; gap:10px;">
                <input type="hidden" name="action" value="submit_critical_issue">
                <div style="display:grid; grid-template-columns: 2fr 1fr; gap:10px;">
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Incident Title *</label>
                        <input type="text" name="title" required placeholder="e.g. Dais Security Clearance Delay at Gate 1" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px;">
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Severity</label>
                        <select name="severity" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px; background:#fff;">
                            <option value="Critical">Critical (Immediate Commander Action)</option>
                            <option value="High" selected>High</option>
                            <option value="Moderate">Moderate</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Incident Description &amp; Escalation Notes *</label>
                    <textarea name="description" rows="3" required placeholder="Describe the tactical or ceremonial incident in detail..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px;"></textarea>
                </div>
                <button type="submit" class="btn btn-dark" style="background:#991b1b; color:#fff; font-weight:700; font-size:12px; padding:8px; width:200px;">
                    Dispatch Incident &rarr;
                </button>
            </form>
        </div>

        <div class="table-wrap">
            <table style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Incident Title</th>
                        <th>Reported By</th>
                        <th>Severity</th>
                        <th>Details</th>
                        <th>Command Decision</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($critical_issues)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:20px;">No open critical incidents reported.</td></tr>
                    <?php else: ?>
                        <?php foreach ($critical_issues as $ci): ?>
                            <tr>
                                <td><strong style="color:#082d21;"><?= e($ci['title']) ?></strong></td>
                                <td><?= e($ci['reporter_name'] ?: 'Officer') ?> (<?= e($ci['reported_level']) ?>)</td>
                                <td>
                                    <span class="badge" style="font-size:10px; font-weight:800; <?= $ci['severity'] === 'Critical' ? 'background:#fee2e2; color:#991b1b;' : 'background:#fef3c7; color:#92400e;' ?>">
                                        <?= e($ci['severity']) ?>
                                    </span>
                                </td>
                                <td><small style="color:#475569;"><?= e($ci['description']) ?></small></td>
                                <td><em style="color:#166534; font-size:11px;"><?= e($ci['command_decision'] ?: 'Pending Review') ?></em></td>
                                <td>
                                    <span class="badge <?= $ci['status'] === 'Resolved' ? 'completed' : 'pending' ?>">
                                        <?= e($ci['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($ci['status'] !== 'Resolved'): ?>
                                        <form method="post" style="display:flex; gap:4px; align-items:center;">
                                            <input type="hidden" name="action" value="resolve_critical_issue">
                                            <input type="hidden" name="issue_id" value="<?= $ci['id'] ?>">
                                            <input type="text" name="command_decision" required placeholder="Directive decision..." style="font-size:11px; padding:3px 6px; border:1px solid #cbd5e1; border-radius:4px; width:130px;">
                                            <button type="submit" class="btn btn-sm" style="background:#16a34a; color:#fff; font-size:10px; font-weight:700; padding:4px 8px;">
                                                Resolve
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#16a34a; font-weight:700; font-size:11px;">✓ Closed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($active_tab === 'inter_brigade'): ?>
    <!-- 3. INTER-BRIGADE COORDINATION (L2 DIVISION) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <span class="eyebrow" style="color:#0284c7; font-weight:800;">DIVISION LEVEL 2</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Inter-Brigade Coordination Dashboard</h3>
                <p>Synchronize joint operations, meeting schedules, and resource distribution between subordinate brigades.</p>
            </div>
            <button type="button" onclick="document.getElementById('coordForm').style.display = document.getElementById('coordForm').style.display === 'none' ? 'block' : 'none'" class="btn btn-sm btn-dark" style="background:#082d21; color:#f5d98d; border:1px solid #d4af37;">
                + Schedule Coordination
            </button>
        </div>

        <div id="coordForm" style="display:none; background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; padding:16px; margin-bottom:20px;">
            <h4 style="margin:0 0 10px; color:#0369a1; font-size:14px;">📅 New Inter-Brigade Coordination Session</h4>
            <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px;">
                <input type="hidden" name="action" value="add_inter_coordination">
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Coordination Title *</label>
                    <input type="text" name="title" required placeholder="e.g. Ceremonial Route Clearance Synchronization" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px;">
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Participating Unit A</label>
                    <input type="text" name="unit_a" value="71st Infantry Brigade" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px;">
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Participating Unit B</label>
                    <input type="text" name="unit_b" value="9th Artillery / MP Unit" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px;">
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Meeting Schedule</label>
                    <input type="datetime-local" name="meeting_schedule" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px; background:#fff;">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Agenda &amp; Coordination Directives</label>
                    <textarea name="details" rows="2" placeholder="Coordination points and requirements..." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px;"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" style="background:#0284c7; border:none; font-weight:700; font-size:12px; padding:8px; width:180px;">
                    Save Schedule &rarr;
                </button>
            </form>
        </div>

        <div class="table-wrap">
            <table style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Coordination Topic</th>
                        <th>Lead Units</th>
                        <th>Type</th>
                        <th>Scheduled Meeting</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inter_coordinations as $ic): ?>
                        <tr>
                            <td>
                                <strong style="color:#082d21;"><?= e($ic['title']) ?></strong>
                                <small style="display:block; color:#64748b;"><?= e($ic['details']) ?></small>
                            </td>
                            <td><?= e($ic['unit_a']) ?> &harr; <?= e($ic['unit_b']) ?></td>
                            <td><span style="font-family:monospace;"><?= e($ic['coordination_type']) ?></span></td>
                            <td><?= !empty($ic['meeting_schedule']) ? date('d M Y, H:i', strtotime($ic['meeting_schedule'])) : 'Scheduled' ?></td>
                            <td><span class="badge completed" style="background:#dcfce7; color:#15803d; font-weight:800;"><?= e($ic['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($active_tab === 'brigade_reports'): ?>
    <!-- 4. PENDING BRIGADE REPORTS REVIEW (L2 DIVISION) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head">
            <div>
                <span class="eyebrow" style="color:#d4af37; font-weight:800;">DIVISION COMMAND OVERSIGHT</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Pending Brigade Reports &amp; Verification Desk</h3>
                <p>Verify incoming operational reports from L3 Brigades, request revisions, and forward approved dossiers to Higher Command (L1).</p>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
            <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <strong style="color:#082d21; font-size:14px;">71st Brigade Ceremonial Roster Report</strong>
                    <span class="badge pending" style="font-weight:800;">Submitted</span>
                </div>
                <p style="font-size:12px; color:#475569; margin:0 0 10px;">
                    Comprehensive parade drill report including 100-man presidential guard turnout and saluting battery status.
                </p>
                <div style="display:flex; gap:8px;">
                    <button type="button" class="btn btn-sm" style="background:#16a34a; color:#fff; font-size:11px; font-weight:700; padding:4px 10px;" onclick="alert('Report approved and forwarded to Higher Command (L1).')">
                        ✓ Approve &amp; Forward L1
                    </button>
                    <button type="button" class="btn btn-sm" style="background:#ea580c; color:#fff; font-size:11px; font-weight:700; padding:4px 10px;" onclick="alert('Revision request sent to Brigade Commander.')">
                        Request Revision
                    </button>
                </div>
            </div>

            <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <strong style="color:#082d21; font-size:14px;">Brigade Radio &amp; Signals Verification</strong>
                    <span class="badge completed" style="font-weight:800;">Approved</span>
                </div>
                <p style="font-size:12px; color:#475569; margin:0 0 10px;">
                    Frequency sync SEC-CH-2 verified for all VIP inner perimeter cordons. Handed over to Higher Command.
                </p>
                <span style="font-size:11px; color:#15803d; font-weight:800;">✓ Forwarded to Supreme Command</span>
            </div>
        </div>
    </section>

<?php elseif ($active_tab === 'ceremonial_prep'): ?>
    <!-- 5. CEREMONIAL PREPARATION TRACKER (L3 BRIGADE) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head">
            <div>
                <span class="eyebrow" style="color:#d4af37; font-weight:800;">BRIGADE LEVEL 3</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Ceremonial Preparation Tracker &bull; Battalion Readiness</h3>
                <p>Track flags, dais setup, guard turnout, rehearsal schedule, and inspection status across 1st East Bengal Battalion.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Checkpoint / Element</th>
                        <th>Category</th>
                        <th>Readiness %</th>
                        <th>Rehearsal Date</th>
                        <th>Status</th>
                        <th>Inspection Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ceremonial_prep as $cp): ?>
                        <tr>
                            <td><strong style="color:#082d21;"><?= e($cp['item_name']) ?></strong></td>
                            <td><span style="font-family:monospace;"><?= e($cp['category']) ?></span></td>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div style="width:70px; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden;">
                                        <div style="width:<?= (int)$cp['readiness_percentage'] ?>%; height:100%; background:#16a34a;"></div>
                                    </div>
                                    <strong style="font-size:11px;"><?= (int)$cp['readiness_percentage'] ?>%</strong>
                                </div>
                            </td>
                            <td><?= !empty($cp['rehearsal_date']) ? date('d M Y, H:i', strtotime($cp['rehearsal_date'])) : 'Scheduled' ?></td>
                            <td>
                                <span class="badge <?= $cp['status'] === 'Ready' || $cp['status'] === 'Inspected' ? 'completed' : 'pending' ?>">
                                    <?= e($cp['status']) ?>
                                </span>
                            </td>
                            <td><small style="color:#64748b;"><?= e($cp['notes'] ?: 'Pending inspection') ?></small></td>
                            <td>
                                <form method="post" style="display:flex; gap:4px; align-items:center;">
                                    <input type="hidden" name="action" value="update_ceremonial_prep">
                                    <input type="hidden" name="item_id" value="<?= $cp['id'] ?>">
                                    <input type="hidden" name="readiness_percentage" value="100">
                                    <input type="hidden" name="status" value="Ready">
                                    <input type="hidden" name="notes" value="Verified ready by Brigade CO.">
                                    <button type="submit" class="btn btn-sm" style="background:#16a34a; color:#fff; font-size:10px; font-weight:700; padding:3px 6px;">
                                        Mark Ready
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($active_tab === 'battalion_coordination'): ?>
    <!-- 6. BATTALION COORDINATION (L3 BRIGADE) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head">
            <div>
                <span class="eyebrow" style="color:#d4af37; font-weight:800;">BRIGADE LEVEL 3</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Battalion Coordination &amp; Synchronization</h3>
                <p>Task synchronization, joint briefings, and resource coordination between battalions.</p>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 6px; color:#082d21; font-size:14px;">⚔️ 1st East Bengal Battalion CO Sync</h4>
                <p style="font-size:12px; color:#475569; margin:0 0 10px;">
                    Daily tactical conference at 0730 hrs. All honor sentry personnel and parade contingents muster at BUP Parade Ground dais.
                </p>
                <div style="font-size:11px; color:#166534; font-weight:700;">✓ Sync Complete &bull; All Contingents Accounted</div>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 6px; color:#082d21; font-size:14px;">📡 Radio Frequency Check</h4>
                <p style="font-size:12px; color:#475569; margin:0 0 10px;">
                    Walkie-talkies tested across Gate 1, Dais, and Auditorium perimeter. Radio operator standing by.
                </p>
                <div style="font-size:11px; color:#0284c7; font-weight:700;">✓ Signal Strength 100%</div>
            </div>
        </div>
    </section>

<?php elseif ($active_tab === 'company_progress'): ?>
    <!-- 7. COMPANY PROGRESS MONITORING (L4 BATTALION) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head">
            <div>
                <span class="eyebrow" style="color:#d4af37; font-weight:800;">BATTALION LEVEL 4</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Company Progress Monitoring Desk</h3>
                <p>Observe Alpha Company operational duties, deadline adherence, and event preparation status.</p>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;">
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:14px;">
                <span style="font-size:10px; font-weight:800; color:#166534; text-transform:uppercase;">Alpha Company Completion</span>
                <strong style="display:block; font-size:24px; color:#15803d; margin-top:4px;">92%</strong>
                <small style="color:#166534; font-weight:700;">On Schedule</small>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                <span style="font-size:10px; font-weight:800; color:#64748b; text-transform:uppercase;">Active Directives</span>
                <strong style="display:block; font-size:24px; color:#082d21; margin-top:4px;"><?= count($subordinate_duties) ?></strong>
                <small style="color:#0284c7; font-weight:700;">Delegated to Company</small>
            </div>
            <div style="background:#fefbf2; border:1px solid #fde68a; border-radius:10px; padding:14px;">
                <span style="font-size:10px; font-weight:800; color:#92400e; text-transform:uppercase;">Overdue Directives</span>
                <strong style="display:block; font-size:24px; color:#b45309; margin-top:4px;">0</strong>
                <small style="color:#16a34a; font-weight:700;">Zero Delayed Tasks</small>
            </div>
        </div>
    </section>

<?php elseif ($active_tab === 'civil_integration'): ?>
    <!-- 8. CIVIL INTEGRATION CONSOLE (L4 BATTALION) -->
    <section class="panel" style="margin-bottom:24px; border:2px solid #38bdf8; background:#f0f9ff; border-radius:14px; padding:20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px; border-bottom:1px solid #bae6fd; padding-bottom:10px;">
            <div>
                <span style="background:#0284c7; color:#fff; font-size:10px; font-weight:800; padding:2px 8px; border-radius:4px; font-family:monospace;">
                    CIVIL INTEGRATION CONSOLE
                </span>
                <h3 style="margin:4px 0 0; color:#0369a1; font-size:17px;">
                    Civil Event Directorate &amp; Military Battalion Joint Alignment
                </h3>
            </div>
            <span style="background:#e0f2fe; color:#0284c7; font-weight:700; font-size:11px; padding:4px 10px; border-radius:6px; border:1px solid #bae6fd;">
                L4 Battalion &harr; Civil L1 Director / L2 Coordinator Active Link
            </span>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
            <div style="background:#ffffff; border:1px solid #bae6fd; border-radius:10px; padding:14px;">
                <h4 style="margin:0 0 6px; font-size:13px; color:#0c4a6e;">🏛️ Civil Event Command Channels</h4>
                <div style="font-size:11px; color:#334155; display:flex; flex-direction:column; gap:4px;">
                    <div>&bull; <strong>Event Director (L1):</strong> Dr. A. Rahman (Ext: 1001)</div>
                    <div>&bull; <strong>Event Coordinator (L2):</strong> Engr. S. Ahmed (Ext: 1002)</div>
                    <div>&bull; <strong>Civil Emergency Desk:</strong> +880 1711-000000</div>
                </div>
            </div>

            <div style="background:#ffffff; border:1px solid #bae6fd; border-radius:10px; padding:14px;">
                <h4 style="margin:0 0 6px; font-size:13px; color:#0c4a6e;">🤝 Joint Handover Checkpoints</h4>
                <div style="display:flex; flex-direction:column; gap:6px; font-size:11px; color:#334155;">
                    <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:4px 8px; border-radius:4px;">
                        <span>VVIP Motorcade Gate Handover</span>
                        <span style="background:#dcfce7; color:#15803d; font-weight:700; padding:1px 6px; border-radius:3px;">Synchronized</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:4px 8px; border-radius:4px;">
                        <span>Auditorium Dais Security Clear</span>
                        <span style="background:#dcfce7; color:#15803d; font-weight:700; padding:1px 6px; border-radius:3px;">Cleared</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

<?php elseif ($active_tab === 'platoon_progress'): ?>
    <!-- 9. PLATOON PROGRESS TRACKING (L5 COMPANY) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head">
            <div>
                <span class="eyebrow" style="color:#d4af37; font-weight:800;">COMPANY LEVEL 5</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Platoon Progress Tracking</h3>
                <p>Observe 1st Protocol Platoon performance, gate post readiness, and completion milestones.</p>
            </div>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:16px;">
            <h4 style="margin:0 0 8px; color:#082d21; font-size:14px;">1st Protocol Platoon Operational Status</h4>
            <div style="font-size:12px; color:#475569; display:flex; flex-direction:column; gap:6px;">
                <div>Commander: <strong>Captain T. Mahmood</strong> (BA-8114)</div>
                <div>Station: <strong>BUP Gate 1 &amp; Protocol Corridor</strong></div>
                <div>Assigned Strength: <strong>24 Personnel</strong></div>
                <div>Readiness Status: <span style="color:#16a34a; font-weight:700;">Turnout Checked &amp; Walkie-Talkies Operational</span></div>
            </div>
        </div>
    </section>

<?php elseif ($active_tab === 'resource_allocation'): ?>
    <!-- 10. RESOURCE ALLOCATION (L5 COMPANY) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <span class="eyebrow" style="color:#d4af37; font-weight:800;">COMPANY LEVEL 5</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Resource &amp; Equipment Allocation Desk</h3>
                <p>Distribute personnel, communication walkie-talkies, and ceremonial gear to platoons.</p>
            </div>
            <button type="button" onclick="document.getElementById('allocForm').style.display = document.getElementById('allocForm').style.display === 'none' ? 'block' : 'none'" class="btn btn-sm btn-dark" style="background:#082d21; color:#f5d98d; border:1px solid #d4af37;">
                + Allocate Resource
            </button>
        </div>

        <div id="allocForm" style="display:none; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:16px; margin-bottom:20px;">
            <h4 style="margin:0 0 10px; color:#166534; font-size:14px;">📦 Allocate Gear / Personnel to Platoon</h4>
            <form method="post" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px;">
                <input type="hidden" name="action" value="add_resource_allocation">
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Resource Type</label>
                    <select name="resource_type" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px; background:#fff;">
                        <option value="Equipment">Equipment / Gear</option>
                        <option value="Personnel">Personnel Contingent</option>
                        <option value="Comms">Communications / Walkie-Talkie</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Item / Resource Name *</label>
                    <input type="text" name="item_name" required placeholder="e.g. VHF Walkie-Talkie Set" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px;">
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Quantity</label>
                    <input type="number" name="quantity" value="6" min="1" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px;">
                </div>
                <div>
                    <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Allocated To</label>
                    <input type="text" name="allocated_to" value="1st Protocol Platoon" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px;">
                </div>
                <div style="grid-column:1 / -1;">
                    <button type="submit" class="btn btn-dark" style="background:#082d21; color:#f5d98d; font-weight:700; font-size:12px; padding:8px 16px;">
                        Confirm Allocation &rarr;
                    </button>
                </div>
            </form>
        </div>

        <div class="table-wrap">
            <table style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Resource Item</th>
                        <th>Type</th>
                        <th>Quantity</th>
                        <th>Allocated Echelon</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resource_allocations as $ra): ?>
                        <tr>
                            <td><strong style="color:#082d21;"><?= e($ra['item_name']) ?></strong></td>
                            <td><span style="font-family:monospace;"><?= e($ra['resource_type']) ?></span></td>
                            <td><strong><?= (int)$ra['quantity'] ?> units</strong></td>
                            <td><?= e($ra['allocated_to']) ?></td>
                            <td><span class="badge completed" style="background:#dcfce7; color:#15803d; font-weight:800;"><?= e($ra['request_status']) ?></span></td>
                            <td><small style="color:#64748b;"><?= e($ra['notes'] ?: 'Operational') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($active_tab === 'section_progress'): ?>
    <!-- 11. SECTION PROGRESS TRACKING (L6 PLATOON) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head">
            <div>
                <span class="eyebrow" style="color:#d4af37; font-weight:800;">PLATOON LEVEL 6</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Section Progress Tracking Desk</h3>
                <p>Observe Section 1 sentry deployments, ceremonial turnout readiness, and post rotation schedules.</p>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px;">
            <div style="background:#ffffff; border:1px solid #cbd5e1; border-radius:10px; padding:16px;">
                <h4 style="margin:0 0 6px; color:#082d21; font-size:14px;">Section 1 &bull; VIP Dais Guard</h4>
                <div style="font-size:12px; color:#475569; display:flex; flex-direction:column; gap:4px;">
                    <div>Commander: <strong>Sergeant R. Karim</strong></div>
                    <div>Assigned Post: <strong>Main Dais Stage Right</strong></div>
                    <div>Personnel Turnout: <strong style="color:#16a34a;">100% Present &amp; Briefed</strong></div>
                    <div>Walkie-Talkie Channel: <strong>SEC-CH-2 (Verified)</strong></div>
                </div>
            </div>
        </div>
    </section>

<?php elseif ($active_tab === 'soldier_evidence'): ?>
    <!-- 12. SOLDIER EVIDENCE & SIGN-OFF DESK (L7 SECTION COMMANDER) -->
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-head">
            <div>
                <span class="eyebrow" style="color:#16a34a; font-weight:800;">SECTION COMMAND (L7)</span>
                <h3 style="margin:2px 0 0; color:#082d21; font-size:18px;">Soldier Duty Evidence &amp; Verification Desk</h3>
                <p>Review post execution reports and evidence uploaded by individual sentries, and issue official sign-off.</p>
            </div>
        </div>

        <div class="table-wrap">
            <table style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Soldier &amp; Post</th>
                        <th>Duty Title</th>
                        <th>Execution Remarks</th>
                        <th>Attached Evidence</th>
                        <th>Submitted At</th>
                        <th>Status</th>
                        <th>Sign-Off Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($soldier_evidence_list)): ?>
                        <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:20px;">No soldier duty reports submitted yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($soldier_evidence_list as $se): ?>
                            <tr>
                                <td>
                                    <strong style="color:#082d21;"><?= e($se['soldier_name']) ?></strong>
                                    <small style="display:block; color:#64748b;"><?= e($se['post_location'] ?: 'VIP Dais Post') ?></small>
                                </td>
                                <td><?= e($se['duty_title'] ?: 'Ceremonial Post Duty') ?></td>
                                <td><p style="margin:0; font-size:11.5px; color:#334155;"><?= e($se['evidence_text']) ?></p></td>
                                <td>
                                    <?php if (!empty($se['evidence_file'])): ?>
                                        <a href="<?= BASE_URL ?>/<?= e($se['evidence_file']) ?>" target="_blank" style="background:#e0f2fe; color:#0284c7; padding:2px 6px; border-radius:4px; font-weight:700; text-decoration:none;">
                                            📎 View Attachment
                                        </a>
                                    <?php else: ?>
                                        <span style="color:#94a3b8; font-size:11px;">Text Record</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d M Y, H:i', strtotime($se['created_at'])) ?></td>
                                <td>
                                    <span class="badge <?= $se['status'] === 'Verified' ? 'completed' : 'pending' ?>">
                                        <?= e($se['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($se['status'] !== 'Verified'): ?>
                                        <form method="post" style="display:flex; gap:4px;">
                                            <input type="hidden" name="action" value="verify_soldier_evidence">
                                            <input type="hidden" name="report_id" value="<?= $se['id'] ?>">
                                            <input type="hidden" name="decision" value="Verified">
                                            <input type="hidden" name="verifier_notes" value="Turnout and execution inspected &amp; signed off by Sergeant R. Karim.">
                                            <button type="submit" class="btn btn-sm" style="background:#16a34a; color:#fff; font-size:10px; font-weight:700; padding:4px 8px;">
                                                ✓ Verify &amp; Sign Off
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color:#16a34a; font-weight:800; font-size:11px;">✓ Signed Off</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($active_tab === 'evidence_submit'): ?>
    <!-- 13. SOLDIER SUBMIT COMPLETION EVIDENCE & REPORT (L8 SOLDIER) -->
    <section class="panel" style="margin-bottom:24px; border:2px solid #16a34a; background:#f0fdf4; border-radius:14px; padding:24px;">
        <div style="border-bottom:1px solid #bbf7d0; padding-bottom:12px; margin-bottom:18px;">
            <span style="background:#16a34a; color:#fff; font-size:10px; font-weight:900; padding:2px 8px; border-radius:4px; font-family:monospace;">
                SOLDIER OPERATIONAL REPORT
            </span>
            <h3 style="margin:6px 0 2px; color:#166534; font-size:20px;">
                Submit Post Execution Evidence &amp; Report to Section Commander
            </h3>
            <p style="margin:0; font-size:12px; color:#475569;">
                Upon completion of your ceremonial sentry post, upload execution summary notes and evidence for Section Commander sign-off.
            </p>
        </div>

        <form method="post" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:14px;">
            <input type="hidden" name="action" value="submit_soldier_evidence">

            <div>
                <label style="font-size:11.5px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Select Duty / Sentry Post *</label>
                <select name="duty_id" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px; background:#fff;">
                    <?php foreach ($my_duties as $md): ?>
                        <option value="<?= $md['id'] ?>"><?= e($md['title']) ?> (<?= e($md['location']) ?>)</option>
                    <?php endforeach; ?>
                    <?php if (empty($my_duties)): ?>
                        <option value="1">VIP Dais Sentry Post #1 (Parade Dais)</option>
                    <?php endif; ?>
                </select>
            </div>

            <div>
                <label style="font-size:11.5px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Execution Summary &amp; Sentry Report Remarks *</label>
                <textarea name="evidence_text" rows="4" required placeholder="e.g. Sentry post manned from 0900 to 1230 hrs. VVIP dais security perimeter cordoned. Turnout immaculate. Zero anomalies encountered." style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:10px; font-size:12px;"></textarea>
            </div>

            <div>
                <label style="font-size:11.5px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Attach Photo / Verification Document (Optional)</label>
                <input type="file" name="evidence_file" accept="image/*,.pdf" style="font-size:12px;">
                <small style="display:block; color:#64748b; margin-top:3px;">Allowed: JPG, PNG, WEBP, PDF.</small>
            </div>

            <button type="submit" class="btn btn-dark" style="background:#082d21; border:1px solid #d4af37; color:#f5d98d; font-weight:800; font-size:13px; padding:11px 20px; width:260px;">
                Submit to Section Commander &rarr;
            </button>
        </form>
    </section>

<?php else: ?>
    <!-- =========================================================================
         DEFAULT OVERVIEW TAB: SUBORDINATE ACCOUNTS ROSTER & DIRECTIVE ALLOCATION
    ========================================================================== -->
    <?php if ($active_echelon === 'Individual Soldier'): ?>
        <!-- SOLDIER CEREMONIAL PASS HERO -->
        <section class="panel" style="margin-bottom:24px; border:2px solid #d4af37; background:#fffcf2; border-radius:16px; padding:24px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px; border-bottom:2px dashed rgba(212,175,55,0.4); padding-bottom:14px;">
                <div>
                    <span style="background:#082d21; color:#f5d98d; font-size:10.5px; font-weight:900; padding:3px 8px; border-radius:4px; font-family:monospace;">
                        ARMED FORCES CEREMONIAL PROTOCOL PASS
                    </span>
                    <h2 style="margin:6px 0 2px; color:#082d21; font-size:22px;">
                        Soldier Operational Credentials &bull; Standing Post Pass
                    </h2>
                    <div style="color:#78651a; font-size:12.5px;">
                        Issued under authority of BUP Military Protocol Directorate &bull; Official Digital Verification
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:14px;">
                    <div style="background:#082d21; color:#fff; border:2px solid #d4af37; border-radius:10px; padding:8px 16px; text-align:center;">
                        <span style="display:block; font-size:9.5px; color:#f5d98d; font-family:monospace; text-transform:uppercase;">PASS IDENTIFIER</span>
                        <strong style="display:block; font-size:16px; color:#ffffff; font-family:monospace;">#MIL-PASS-018</strong>
                    </div>
                    <button type="button" onclick="window.print()" class="btn btn-dark" style="background:#082d21; border:1px solid #d4af37; color:#f5d98d; font-size:12px; font-weight:700; padding:8px 14px;">
                        🖨️ Print Duty Pass
                    </button>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:18px;">
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                    <span style="font-size:10px; font-weight:800; color:#d4af37; text-transform:uppercase;">SOLDIER PROFILE</span>
                    <h3 style="margin:4px 0 2px; font-size:16px; color:#082d21;"><?= e($u['name']) ?></h3>
                    <div style="font-size:12px; color:#0284c7; font-weight:700;"><?= e($u['designation'] ?: 'Individual Soldier / Escort Personnel') ?></div>
                    <div style="font-size:11.5px; color:#64748b; margin-top:8px;">
                        <div>Service Unit: <strong style="color:#0f172a;">Section 1 &bull; 1st Protocol Platoon</strong></div>
                        <div>Direct Commander: <strong style="color:#0f172a;">Sergeant R. Karim (Section 1)</strong></div>
                    </div>
                </div>
                <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                    <span style="font-size:10px; font-weight:800; color:#d4af37; text-transform:uppercase;">STANDING SENTRY ORDERS</span>
                    <div style="margin-top:6px; font-size:11.5px; color:#334155; line-height:1.5;">
                        <div>1. <strong>Honor Turnout:</strong> Maintain immaculate ceremonial uniform.</div>
                        <div>2. <strong>VVIP Security:</strong> Permit access only to verified credentials.</div>
                        <div>3. <strong>Reporting:</strong> Escalate anomalies via Frequency SEC-CH-2.</div>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Subordinate Accounts Register Table & Directive Delegation -->
    <div class="content-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:24px;">
        <!-- Subordinate Command Accounts -->
        <section class="panel" style="margin:0;">
            <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span class="eyebrow" style="color:#d4af37; font-weight:800;">AUTHORIZATION &amp; ROSTER</span>
                    <h3 style="margin:2px 0 0; font-size:16px; color:#082d21;">
                        <?= e($cfg['sub_level']) ?> Commander Accounts
                    </h3>
                </div>
                <span class="badge completed" style="background:#eaf7ee; color:#082d21; font-weight:800;">
                    <?= count($sub_users) ?> Personnel
                </span>
            </div>

            <div class="table-wrap">
                <table style="font-size:12px;">
                    <thead>
                        <tr>
                            <th>Officer / Personnel</th>
                            <th>Designation &amp; Unit</th>
                            <th>Status</th>
                            <th style="text-align:right;">Access Control</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sub_users)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:20px;">No accounts found for this subordinate tier.</td></tr>
                        <?php else: ?>
                            <?php foreach ($sub_users as $su): ?>
                                <tr>
                                    <td>
                                        <strong style="color:#082d21; display:block;"><?= e($su['name']) ?></strong>
                                        <small style="color:#64748b;"><?= e($su['email']) ?></small>
                                    </td>
                                    <td>
                                        <div style="font-weight:700; color:#0284c7;"><?= e($su['designation'] ?: $cfg['sub_level']) ?></div>
                                        <small style="color:#64748b;"><?= e($su['unit_code'] ?? $cfg['sub_unit_code']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge <?= strtolower($su['status']) === 'active' ? 'completed' : 'pending' ?>" style="font-weight:800;">
                                            <?= e($su['status']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if ($active_echelon !== 'Individual Soldier'): ?>
                                            <form method="post" style="display:inline-block;" onsubmit="return confirm('Confirm toggle status for <?= e($su['name']) ?>?');">
                                                <input type="hidden" name="action" value="toggle_account_status">
                                                <input type="hidden" name="user_id" value="<?= $su['id'] ?>">
                                                <?php if (strtolower($su['status']) === 'active'): ?>
                                                    <input type="hidden" name="status" value="Inactive">
                                                    <button type="submit" class="btn btn-sm" style="background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; font-weight:700; font-size:11px; padding:3px 8px; border-radius:4px;">
                                                        Deactivate
                                                    </button>
                                                <?php else: ?>
                                                    <input type="hidden" name="status" value="Active">
                                                    <button type="submit" class="btn btn-sm" style="background:#dcfce7; border:1px solid #86efac; color:#166534; font-weight:700; font-size:11px; padding:3px 8px; border-radius:4px;">
                                                        Activate
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        <?php else: ?>
                                            <span style="font-size:11px; color:#16a34a; font-weight:700;">Verified Officer</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Delegate Tactical Directive Form -->
        <section class="panel" style="margin:0;">
            <div class="panel-head">
                <div>
                    <span class="eyebrow" style="color:#d4af37; font-weight:800;">DELEGATE DIRECTIVE</span>
                    <h3 style="margin:2px 0 0; font-size:16px; color:#082d21;">
                        Allocate Tactical Duty to <?= e($cfg['sub_level']) ?>
                    </h3>
                </div>
            </div>

            <?php if ($active_echelon === 'Individual Soldier'): ?>
                <div style="padding:16px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; font-size:12px; color:#475569;">
                    <p style="margin:0 0 10px;">Individual soldiers execute standing orders dispatched from Section Commander.</p>
                    <a href="<?= BASE_URL ?>/manage_subordinates.php?tab=evidence_submit" class="btn btn-block" style="background:#16a34a; color:#fff; font-size:12px; font-weight:700; padding:10px; text-align:center; text-decoration:none;">
                        ⭐ Submit Post Completion Evidence &rarr;
                    </a>
                </div>
            <?php else: ?>
                <form method="post" style="display:flex; flex-direction:column; gap:12px;">
                    <input type="hidden" name="action" value="delegate_duty">
                    <input type="hidden" name="sub_level" value="<?= $cfg['sub_code'] ?>">

                    <div>
                        <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Directive / Mission Title *</label>
                        <input type="text" name="title" required placeholder="<?= e($cfg['placeholder']) ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px 10px; font-size:12px;">
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div>
                            <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;"><?= e($cfg['target_label']) ?> *</label>
                            <select name="target_user_id" required style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px; background:#fff;">
                                <?php foreach ($sub_users as $su): ?>
                                    <option value="<?= $su['id'] ?>"><?= e($su['name']) ?> (<?= e($su['designation'] ?: $cfg['sub_level']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Associated Event</label>
                            <select name="event_id" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px; background:#fff;">
                                <?php foreach ($events as $ev): ?>
                                    <option value="<?= $ev['id'] ?>"><?= e($ev['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                        <div>
                            <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Location / Sentry Post</label>
                            <input type="text" name="location" value="<?= e($cfg['default_location']) ?>" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px 10px; font-size:12px;">
                        </div>
                        <div>
                            <label style="font-size:11px; font-weight:700; color:#334155; display:block; margin-bottom:4px;">Priority</label>
                            <select name="priority" style="width:100%; border-radius:6px; border:1px solid #cbd5e1; padding:8px; font-size:12px; background:#fff;">
                                <option value="Urgent">Urgent (Immediate Turnout)</option>
                                <option value="High" selected>High (Standard Protocol)</option>
                                <option value="Medium">Medium</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-dark" style="background:#082d21; border:1px solid #d4af37; color:#f5d98d; font-weight:700; font-size:12px; padding:10px; margin-top:4px;">
                        Delegate Directive to <?= e($cfg['sub_level']) ?> &rarr;
                    </button>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <!-- =========================================================================
         SECTION 2: SUBORDINATE ECHELON ALLOCATED DIRECTIVES & LIVE STATUS
    ========================================================================== -->
    <section class="panel">
        <div class="panel-head" style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <span class="eyebrow" style="color:#d4af37; font-weight:800;">DUTIES &amp; DIRECTIVES ROSTER</span>
                <h3 style="margin:2px 0 0; font-size:16px; color:#082d21;">
                    Active Operational Directives &bull; <?= e($cfg['sub_level']) ?>
                </h3>
            </div>
        </div>

        <div class="table-wrap">
            <table style="font-size:12px;">
                <thead>
                    <tr>
                        <th>Directive / Task</th>
                        <th>Target Officer / Assignee</th>
                        <th>Event</th>
                        <th>Post / Location</th>
                        <th>Priority</th>
                        <th>Live Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subordinate_duties)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; color:#94a3b8; padding:24px;">
                                No active duties currently delegated to this echelon.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subordinate_duties as $d): ?>
                            <tr>
                                <td>
                                    <strong style="color:#082d21;"><?= e($d['title']) ?></strong>
                                    <small style="display:block; color:#64748b;"><?= !empty($d['duty_date']) ? date('d M Y', strtotime($d['duty_date'])) : '' ?></small>
                                </td>
                                <td>
                                    <strong style="color:#0284c7;"><?= e($d['assignee_name'] ?: 'Subordinate Personnel') ?></strong>
                                    <small style="display:block; color:#64748b;"><?= e($d['assignee_designation'] ?: $cfg['sub_level']) ?></small>
                                </td>
                                <td><?= e($d['event_name'] ?: 'Institutional Event') ?></td>
                                <td><?= e($d['location'] ?: $cfg['default_location']) ?></td>
                                <td>
                                    <span class="badge" style="font-size:10px; font-weight:800; <?= $d['priority'] === 'Urgent' ? 'background:#fee2e2; color:#991b1b;' : 'background:#fef3c7; color:#92400e;' ?>">
                                        <?= e($d['priority']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= strtolower($d['status']) === 'completed' ? 'completed' : (strtolower($d['status']) === 'in progress' ? 'inprogress' : 'pending') ?>" style="font-weight:800;">
                                        <?= e($d['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
