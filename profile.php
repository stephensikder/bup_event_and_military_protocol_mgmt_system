<?php

require_once __DIR__ . '/config/auth.php';
require_login();

$pdo = db();

$page_title = 'My Profile | BUP Protocol';

$userId = (int)current_user()['id'];


// =====================================================
// HANDLE PROFILE UPDATE
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? 'update_profile';


    if ($action === 'update_profile') {

        $name = trim($_POST['name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $phone = trim($_POST['phone'] ?? '');


        if ($name === '') {

            flash(
                'error',
                'Full name is required.'
            );

            redirect(
                BASE_URL . '/profile.php'
            );
        }


        // Get current profile

        $stmt = $pdo->prepare("
            SELECT *
            FROM users
            WHERE id = ?
        ");

        $stmt->execute([$userId]);

        $existingUser = $stmt->fetch();


        if (!$existingUser) {

            flash(
                'error',
                'User account could not be found.'
            );

            redirect(
                BASE_URL . '/profile.php'
            );
        }


        try {

            $pdo->beginTransaction();


            $reports_to_id = !empty($_POST['reports_to_id']) ? (int)$_POST['reports_to_id'] : null;
            $backup_person_id = !empty($_POST['backup_person_id']) ? (int)$_POST['backup_person_id'] : null;

            // Update profile
            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    name = ?,
                    designation = ?,
                    phone = ?,
                    reports_to_id = ?,
                    backup_person_id = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $name,
                $designation,
                $phone,
                $reports_to_id,
                $backup_person_id,
                $userId
            ]);

            // Handle Profile Picture Upload
            if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['avatar'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array($ext, $allowed, true)) {
                    $uploadDir = __DIR__ . '/assets/uploads/avatars/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    $fileName = 'avatar_' . $userId . '_' . time() . '.' . $ext;
                    $targetPath = $uploadDir . $fileName;
                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $avatarRelPath = 'assets/uploads/avatars/' . $fileName;
                        $stmtAv = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
                        $stmtAv->execute([$avatarRelPath, $userId]);
                        $_SESSION['user']['avatar'] = $avatarRelPath;
                    }
                }
            }


            // Audit log

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    description
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                'Profile Updated',
                'User profile information was updated.'
            ]);


            $pdo->commit();
            mongo_sync_table('users');


            // Update current session

            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['designation'] = $designation;
            $_SESSION['user']['phone'] = $phone;


            flash(
                'success',
                'Profile updated successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'error',
                'Unable to update profile.'
            );
        }


        redirect(
            BASE_URL . '/profile.php'
        );
    }
}


// =====================================================
// FETCH CURRENT USER
// =====================================================

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
");

$stmt->execute([$userId]);

$u = $stmt->fetch();


if (!$u) {

    flash(
        'error',
        'User account not found.'
    );

    redirect(
        BASE_URL . '/dashboard.php'
    );
}


// =====================================================
// ACCOUNT ACTIVITY
// =====================================================

$stmt = $pdo->prepare("
    SELECT
        action,
        description,
        created_at
    FROM audit_logs
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 8
");

$stmt->execute([$userId]);

$activity = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| ACCOUNT STATUS
|--------------------------------------------------------------------------
*/

$accountStatus =
    ($u['status'] ?? 'Active') === 'Active'
        ? 'Active'
        : 'Inactive';


/*
|--------------------------------------------------------------------------
| ROLE CLASS & ELIGIBLE USERS
|--------------------------------------------------------------------------
*/

$role = $u['role'] ?? 'User';

// Eligible users for dropdowns
$cand_stmt = $pdo->prepare("
    SELECT id, name, role, level, department
    FROM users
    WHERE status = 'Active' AND id != ?
    ORDER BY level ASC, name ASC
");
$cand_stmt->execute([$userId]);
$backup_candidates = $cand_stmt->fetchAll();

// Assigned duties for current user
$duties_stmt = $pdo->prepare("
    SELECT d.*, e.name AS event_name
    FROM duties d
    LEFT JOIN events e ON e.id = d.event_id
    WHERE d.assigned_to = ?
    ORDER BY d.duty_date DESC, d.start_time DESC
    LIMIT 10
");
$duties_stmt->execute([$userId]);
$my_duties = $duties_stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| HEADER
|--------------------------------------------------------------------------
*/

require __DIR__ . '/includes/header.php';

?>


<div class="page-head">

    <div>

        <span class="eyebrow">
            ACCOUNT & SECURITY
        </span>

        <h1>
            My Profile
        </h1>

        <p>
            Manage your personal information and review your account activity.
        </p>

    </div>

</div>


<!-- =====================================================
     PROFILE HERO
====================================================== -->

<section class="profile-hero panel">

    <div class="profile-avatar" style="overflow:hidden; padding:0; display:grid; place-items:center;">
        <?php if (!empty($u['avatar']) && file_exists(__DIR__ . '/' . $u['avatar'])): ?>
            <img src="<?= BASE_URL ?>/<?= e($u['avatar']) ?>?v=<?= time() ?>" alt="<?= e($u['name']) ?>" style="width:100%; height:100%; object-fit:cover; border-radius:inherit;">
        <?php else: ?>
            <?= e(
                strtoupper(
                    mb_substr(
                        trim($u['name'] ?? 'U'),
                        0,
                        1
                    )
                )
            ) ?>
        <?php endif; ?>
    </div>


    <div class="profile-identity">

        <span class="eyebrow">
            BUP PROTOCOL ACCOUNT
        </span>

        <h2>
            <?= e($u['name']) ?>
        </h2>

        <p>
            <?= e(
                $u['designation']
                ?: 'BUP Protocol System User'
            ) ?>
        </p>


        <div class="profile-tags">

            <span class="profile-role">
                <?= e($role) ?>
            </span>

            <span class="profile-status">

                <i></i>

                <?= e($accountStatus) ?>

            </span>

        </div>

    </div>


    <div class="profile-meta">

        <span>
            Account ID
        </span>

        <strong>
            #<?= (int)$u['id'] ?>
        </strong>

    </div>

</section>


<!-- =====================================================
     PROFILE CONTENT
====================================================== -->

<div class="profile-layout">


    <!-- =================================================
         PROFILE INFORMATION
    ================================================== -->

    <section class="panel form-panel">

        <div class="panel-head">

            <div>

                <span class="eyebrow">
                    PERSONAL INFORMATION
                </span>

                <h2>
                    Profile Details
                </h2>

                <p>
                    Update your contact and designation information.
                </p>

            </div>

        </div>


        <form method="post" enctype="multipart/form-data">

            <input
                type="hidden"
                name="action"
                value="update_profile"
            >

            <div class="form-grid">

                <div class="span-2" style="background:#f4f8f6; border:1.5px dashed #0d6e42; border-radius:14px; padding:18px 20px; display:flex; align-items:center; gap:20px; flex-wrap:wrap; margin-bottom:12px;">
                    <div style="width:64px; height:64px; border-radius:50%; overflow:hidden; border:2px solid #d4af37; background:#eaf3ee; display:grid; place-items:center; flex-shrink:0;">
                        <?php if (!empty($u['avatar']) && file_exists(__DIR__ . '/' . $u['avatar'])): ?>
                            <img src="<?= BASE_URL ?>/<?= e($u['avatar']) ?>?v=<?= time() ?>" alt="Avatar" style="width:100%; height:100%; object-fit:cover;">
                        <?php else: ?>
                            <span style="font-weight:900; font-size:24px; color:#082d21;"><?= e(strtoupper(substr($u['name'] ?? 'U', 0, 1))) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1; min-width:220px;">
                        <strong style="color:#082d21; font-size:14px; display:block; margin-bottom:3px;">Profile Picture</strong>
                        <p style="margin:0 0 8px; font-size:12px; color:#5e776a;">Upload your official photo (JPG, PNG, or WEBP). It will be stored locally and displayed in the top navbar and identity badges.</p>
                        <input type="file" name="avatar" accept="image/png, image/jpeg, image/jpg, image/webp" class="form-control" style="background:#fff; height:42px; padding:6px 12px; margin:0;">
                    </div>
                </div>

                <label>

                    Full Name

                    <input
                        type="text"
                        name="name"
                        value="<?= e($u['name']) ?>"
                        maxlength="160"
                        required
                    >

                </label>


                <label>

                    Email Address

                    <input
                        type="email"
                        value="<?= e($u['email']) ?>"
                        disabled
                    >

                    <small class="field-note">
                        Email address is managed by the system administrator.
                    </small>

                </label>


                <label>

                    Role

                    <input
                        type="text"
                        value="<?= e($u['role']) ?>"
                        disabled
                    >

                    <small class="field-note">
                        Your system role cannot be changed from this page.
                    </small>

                </label>


                <label>

                    Account Status

                    <input
                        type="text"
                        value="<?= e($accountStatus) ?>"
                        disabled
                    >

                </label>


                <label>

                    Designation

                    <input
                        type="text"
                        name="designation"
                        value="<?= e($u['designation']) ?>"
                        maxlength="160"
                        placeholder="Your designation"
                    >

                </label>


                <label>
                    Phone Number
                    <input
                        type="tel"
                        name="phone"
                        value="<?= e($u['phone']) ?>"
                        maxlength="40"
                        placeholder="Your phone number"
                    >
                </label>

                <label>
                    Reports To (Chain of Command)
                    <select name="reports_to_id">
                        <option value="">— Select Reporting Officer / Governing Authority —</option>
                        <?php foreach ($backup_candidates as $cand): ?>
                            <option value="<?= $cand['id'] ?>" <?= ((int)($u['reports_to_id'] ?? 0) === (int)$cand['id']) ? 'selected' : '' ?>>
                                <?= e($cand['name']) ?> (<?= e($cand['role']) ?> · <?= e($cand['level']) ?><?= !empty($cand['department']) ? ' · ' . e($cand['department']) : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Backup Person (Fallback Responsibility)
                    <select name="backup_person_id">
                        <option value="">— Select Eligible Backup Person —</option>
                        <?php foreach ($backup_candidates as $cand): ?>
                            <option value="<?= $cand['id'] ?>" <?= ((int)($u['backup_person_id'] ?? 0) === (int)$cand['id']) ? 'selected' : '' ?>>
                                <?= e($cand['name']) ?> (<?= e($cand['role']) ?><?= !empty($cand['department']) ? ' · ' . e($cand['department']) : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <?php if (is_event_director()): ?>
                <label>
                    Command Level (Director Authority)
                    <!-- Event Director's command-level selection ONLY relates to Event Coordinator -->
                    <select name="command_level_director" readonly style="background:#f8fafc; font-weight:700; color:#082d21;">
                        <option value="Event Coordinator" selected>Event Coordinator (L2 Direct Subordinate Command)</option>
                    </select>
                    <small class="field-note" style="color:#0284c7; font-weight:600;">
                        Operational authority delegates directly to Event Coordinator. Unnecessary options omitted.
                    </small>
                </label>
                <?php endif; ?>

            </div>


            <div class="profile-form-actions">

                <button
                    type="submit"
                    class="btn btn-primary btn-lg"
                >
                    Save Changes
                </button>

            </div>

        </form>

    </section>

    <!-- DUTIES SECTION -->
    <section class="panel" style="margin-top:24px;">
        <div class="panel-head">
            <div>
                <span class="eyebrow">DUTIES &amp; RESPONSIBILITIES</span>
                <h2>Operational Assignments</h2>
                <p>Track duties assigned to this account across event operations.</p>
            </div>
            <a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/duties.php">View All Duties &rarr;</a>
        </div>
        <div style="padding:16px 24px;">
            <?php if (!empty($my_duties)): ?>
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="border-bottom:1px solid #e2e8f0; text-align:left; color:#64748b;">
                            <th style="padding:8px 6px;">Duty Name</th>
                            <th style="padding:8px 6px;">Event</th>
                            <th style="padding:8px 6px;">Date &amp; Time</th>
                            <th style="padding:8px 6px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_duties as $md): ?>
                            <tr style="border-bottom:1px solid #f1f5f9;">
                                <td style="padding:10px 6px; font-weight:600; color:#1e293b;"><?= e($md['title']) ?></td>
                                <td style="padding:10px 6px; color:#475569;"><?= e($md['event_name'] ?? 'General') ?></td>
                                <td style="padding:10px 6px; color:#64748b; font-size:12px;">
                                    <?= !empty($md['duty_date']) ? e(date('d M Y', strtotime($md['duty_date']))) : '—' ?>
                                    <?= !empty($md['start_time']) ? ' · ' . e(substr($md['start_time'], 0, 5)) : '' ?>
                                </td>
                                <td style="padding:10px 6px;">
                                    <span class="badge <?= strtolower($md['status']) ?>"><?= e($md['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="color:#64748b; margin:0; font-size:13px;">No direct operational duties assigned. High-level event oversight applies.</p>
            <?php endif; ?>
        </div>
    </section>


    <!-- =================================================
         ACCOUNT OVERVIEW
    ================================================== -->

    <aside class="profile-side">


        <!-- ACCOUNT CARD -->

        <section class="panel account-card">

            <div class="panel-head">

                <div>

                    <span class="eyebrow">
                        ACCOUNT
                    </span>

                    <h2>
                        Account Overview
                    </h2>

                </div>

            </div>


            <div class="account-row">

                <span>
                    User ID
                </span>

                <strong>
                    #<?= (int)$u['id'] ?>
                </strong>

            </div>


            <div class="account-row">

                <span>
                    Role
                </span>

                <strong>
                    <?= e($u['role']) ?>
                </strong>

            </div>


            <div class="account-row">

                <span>
                    Status
                </span>

                <strong class="account-active">

                    <?= e($accountStatus) ?>

                </strong>

            </div>


            <div class="account-row">

                <span>
                    Last Login
                </span>

                <strong>

                    <?= !empty($u['last_login'])
                        ? e($u['last_login'])
                        : 'Not available' ?>

                </strong>

            </div>


            <div class="account-row">

                <span>
                    Member Since
                </span>

                <strong>
                    <?= !empty($u['created_at'])
                        ? e($u['created_at'])
                        : '—' ?>
                </strong>

            </div>

        </section>


        <!-- SECURITY CARD -->

        <section class="panel security-card">

            <div class="security-icon">
                ✓
            </div>

            <div>

                <h3>
                    Account Security
                </h3>

                <p>
                    Your role and account status are controlled through the
                    BUP Protocol administration system.
                </p>

            </div>

        </section>

    </aside>

</div>


<!-- =====================================================
     RECENT ACTIVITY
====================================================== -->

<section class="panel profile-activity">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                ACCOUNT ACTIVITY
            </span>

            <h2>
                Recent Activity
            </h2>

            <p>
                Recent actions performed using your account.
            </p>

        </div>

        <span class="activity-count">
            <?= count($activity) ?>
            record<?= count($activity) !== 1 ? 's' : '' ?>
        </span>

    </div>


    <?php if ($activity): ?>

        <div class="profile-timeline">

            <?php foreach ($activity as $item): ?>

                <div class="profile-timeline-item">

                    <div class="timeline-dot"></div>


                    <div class="timeline-content">

                        <strong>
                            <?= e($item['action']) ?>
                        </strong>

                        <p>
                            <?= e($item['description']) ?>
                        </p>

                        <small>
                            <?= e($item['created_at']) ?>
                        </small>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">

            No account activity is available yet.

        </div>

    <?php endif; ?>

</section>


<style>

/* =====================================================
   PROFILE HERO
===================================================== */

.profile-hero {
    margin-top: 18px;
    padding: 22px;
    display: flex;
    align-items: center;
    gap: 17px;
}

.profile-avatar {
    width: 72px;
    height: 72px;
    flex: 0 0 72px;
    display: grid;
    place-items: center;
    border-radius: 20px;
    background: linear-gradient(
        135deg,
        #06281e,
        #115840
    );
    border: 2px solid #d4af37;
    box-shadow: 0 6px 18px rgba(8, 45, 33, 0.18);
    color: #fcf4db;
    font-size: 27px;
    font-weight: 900;
}

.profile-identity {
    min-width: 0;
    flex: 1;
}

.profile-identity h2 {
    margin: 3px 0 4px;
    font-size: 23px;
}

.profile-identity p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
}

.profile-tags {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
    margin-top: 11px;
}

.profile-role,
.profile-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
}

.profile-role {
    background: rgba(31,78,121,.09);
    color: var(--blue);
}

.profile-status {
    background: rgba(22,163,74,.09);
    color: var(--green);
}

.profile-status i {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.profile-meta {
    text-align: right;
}

.profile-meta span {
    display: block;
    color: var(--muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.profile-meta strong {
    display: block;
    margin-top: 3px;
}


/* =====================================================
   PROFILE LAYOUT
===================================================== */

.profile-layout {
    display: grid;
    grid-template-columns:
        minmax(0, 1.5fr)
        minmax(290px, .65fr);
    gap: 18px;
    margin-top: 18px;
}

.profile-side {
    display: flex;
    flex-direction: column;
    gap: 18px;
    align-self: start;
}


/* =====================================================
   FORM
===================================================== */

.field-note {
    display: block;
    margin-top: 5px;
    color: var(--muted);
    font-size: 10px;
    line-height: 1.4;
}

.profile-form-actions {
    margin-top: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
}


/* =====================================================
   ACCOUNT CARD
===================================================== */

.account-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    padding: 12px 0;
    border-bottom: 1px solid rgba(148,163,184,.12);
}

.account-row:last-child {
    border-bottom: 0;
}

.account-row span {
    color: var(--muted);
    font-size: 11px;
}

.account-row strong {
    text-align: right;
    font-size: 12px;
}

.account-active {
    color: var(--green);
}


/* =====================================================
   SECURITY
===================================================== */

.security-card {
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.security-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: rgba(22,163,74,.1);
    color: var(--green);
    font-weight: 900;
}

.security-card h3 {
    margin: 0;
    font-size: 14px;
}

.security-card p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.55;
}


/* =====================================================
   ACTIVITY
===================================================== */

.profile-activity {
    margin-top: 18px;
}

.activity-count {
    color: var(--muted);
    font-size: 11px;
}

.profile-timeline {
    display: flex;
    flex-direction: column;
}

.profile-timeline-item {
    position: relative;
    display: flex;
    gap: 12px;
    padding: 13px 5px;
    border-bottom: 1px solid rgba(148,163,184,.1);
}

.profile-timeline-item:last-child {
    border-bottom: 0;
}

.timeline-dot {
    width: 9px;
    height: 9px;
    flex: 0 0 9px;
    margin-top: 5px;
    border-radius: 50%;
    background: var(--blue);
    box-shadow:
        0 0 0 4px rgba(31,78,121,.09);
}

.timeline-content {
    min-width: 0;
}

.timeline-content strong {
    display: block;
    font-size: 12px;
}

.timeline-content p {
    margin: 3px 0;
    color: var(--muted);
    font-size: 11px;
}

.timeline-content small {
    color: var(--muted);
    font-size: 10px;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 1000px) {

    .profile-layout {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 720px) {

    .profile-hero {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .profile-avatar {
        width: 58px;
        height: 58px;
        flex-basis: 58px;
        border-radius: 16px;
        font-size: 22px;
    }

    .profile-identity {
        width: calc(100% - 80px);
    }

    .profile-meta {
        width: 100%;
        padding-top: 10px;
        text-align: left;
        border-top: 1px solid rgba(148,163,184,.12);
    }

    .profile-meta strong {
        text-align: left;
    }

    .profile-form-actions .btn {
        width: 100%;
    }

}




/* ==========================================
   BUP PROFILE — LAYOUT & CARD SPACING
========================================== */

/* Page heading */
.page-head {
    margin-bottom: 26px;
}

.page-head h1 {
    margin-bottom: 10px;
}

/* Profile hero */
.profile-hero {
    display: flex;
    align-items: center;
    gap: 22px;
    margin: 0 0 28px;
    padding: 28px;
    border-radius: 22px;
    box-sizing: border-box;
}

.profile-avatar {
    width: 76px;
    height: 76px;
    flex: 0 0 76px;
    border-radius: 20px;
}

.profile-identity {
    flex: 1;
    min-width: 0;
}

.profile-identity h2 {
    margin: 6px 0 8px;
    overflow-wrap: anywhere;
}

.profile-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 14px;
}

.profile-meta {
    flex: 0 0 auto;
    padding-left: 24px;
    border-left: 1px solid #e5eaf1;
}

/* Main two-column layout */
.profile-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(290px, 0.85fr);
    align-items: start;
    gap: 28px;
    margin: 0 0 28px;
}

.profile-layout > .panel,
.profile-side > .panel {
    min-width: 0;
    width: 100%;
    margin: 0;
    border-radius: 20px;
    overflow: hidden;
    box-sizing: border-box;
}

/* Profile information panel */
.profile-layout > .form-panel {
    padding: 0;
}

.profile-layout .panel-head,
.profile-side .panel-head,
.profile-activity > .panel-head {
    padding: 24px 28px;
    margin: 0;
    border-bottom: 1px solid #e5eaf1;
}

.profile-layout .panel-head h2,
.profile-side .panel-head h2,
.profile-activity .panel-head h2 {
    margin: 5px 0 8px;
}

.profile-layout .panel-head p,
.profile-activity .panel-head p {
    margin: 0;
    line-height: 1.6;
}

/* Profile form spacing */
.profile-layout .form-panel form {
    padding: 28px;
}

.profile-layout .form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px;
}

.profile-layout .form-grid label {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0;
}

.profile-layout .form-grid input {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

.profile-layout .field-note {
    line-height: 1.5;
}

.profile-form-actions {
    margin-top: 26px;
}

/* Right sidebar cards */
.profile-side {
    display: flex;
    flex-direction: column;
    gap: 24px;
    align-self: start;
}

.profile-side .account-card {
    padding-bottom: 12px;
}

.account-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
    margin: 0 24px;
    padding: 17px 0;
    border-bottom: 1px solid #e8edf2;
}

.account-row:last-child {
    border-bottom: 0;
}

.account-row span {
    flex: 0 0 auto;
}

.account-row strong {
    min-width: 0;
    max-width: 60%;
    overflow-wrap: anywhere;
    text-align: right;
    line-height: 1.6;
}

/* Security card */
.profile-side .security-card {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 24px;
}

.security-icon {
    flex: 0 0 42px;
    width: 42px;
    height: 42px;
    border-radius: 13px;
}

.security-card > div:last-child {
    min-width: 0;
}

.security-card h3 {
    margin: 0 0 10px;
}

.security-card p {
    margin: 0;
    line-height: 1.7;
}

/* Recent activity full width */
.profile-activity {
    width: 100%;
    min-width: 0;
    margin: 0 0 28px;
    border-radius: 20px;
    overflow: hidden;
    box-sizing: border-box;
}

.profile-activity > .panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
}

.profile-timeline {
    padding: 12px 28px;
}

.profile-timeline-item {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px 6px;
    border-bottom: 1px solid #e8edf2;
}

.profile-timeline-item:last-child {
    border-bottom: 0;
}

.timeline-content {
    flex: 1;
    min-width: 0;
}

.timeline-content strong {
    display: block;
    margin-bottom: 6px;
    overflow-wrap: anywhere;
}

.timeline-content p {
    margin: 0 0 8px;
    line-height: 1.65;
    overflow-wrap: anywhere;
}

.timeline-content small {
    line-height: 1.5;
}

/* Tablet */
@media (max-width: 1000px) {
    .profile-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 24px;
    }

    .profile-side {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px;
    }

    .profile-side .account-card {
        grid-row: span 2;
    }
}

/* Mobile */
@media (max-width: 720px) {
    .page-head {
        margin-bottom: 20px;
    }

    .profile-hero {
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 22px;
        padding: 20px;
        border-radius: 18px;
    }

    .profile-avatar {
        width: 58px;
        height: 58px;
        flex-basis: 58px;
        border-radius: 16px;
        font-size: 22px;
    }

    .profile-identity {
        flex: 1 1 calc(100% - 80px);
    }

    .profile-meta {
        flex-basis: 100%;
        padding: 14px 0 0;
        text-align: left;
        border-left: 0;
        border-top: 1px solid #e5eaf1;
    }

    .profile-layout {
        gap: 20px;
        margin-bottom: 22px;
    }

    .profile-layout > .panel,
    .profile-side > .panel,
    .profile-activity {
        border-radius: 18px;
    }

    .profile-layout .panel-head,
    .profile-side .panel-head,
    .profile-activity > .panel-head {
        padding: 20px;
    }

    .profile-layout .form-panel form {
        padding: 20px;
    }

    .profile-layout .form-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 20px;
    }

    .profile-form-actions .btn {
        width: 100%;
    }

    .profile-side {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .account-row {
        margin: 0 20px;
        padding: 15px 0;
    }

    .profile-side .security-card {
        padding: 20px;
    }

    .profile-timeline {
        padding: 10px 20px;
    }

    .profile-timeline-item {
        gap: 13px;
        padding: 17px 0;
    }
}

@media (max-width: 420px) {
    .profile-hero {
        padding: 16px;
    }

    .profile-layout .form-panel form {
        padding: 16px;
    }

    .profile-layout .panel-head,
    .profile-side .panel-head,
    .profile-activity > .panel-head {
        padding: 17px;
    }

    .account-row {
        margin: 0 16px;
        gap: 10px;
    }

    .account-row strong {
        max-width: 58%;
    }

    .profile-side .security-card {
        padding: 17px;
    }

    .profile-timeline {
        padding: 8px 16px;
    }
}






</style>


<?php require __DIR__ . '/includes/footer.php'; ?>