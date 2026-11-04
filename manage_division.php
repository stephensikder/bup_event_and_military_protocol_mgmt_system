<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_login();

$u = current_user();
$is_higher_cmd = ($u['protocol_level'] ?? '') === 'Higher Command' || has_role('Admin');

if (!$is_higher_cmd) {
    flash('error', 'Restricted Command Access: Manage Division Accounts is accessible only to Higher Command (Level 1).');
    redirect(BASE_URL . '/protocol.php');
}

$page_title = 'Manage Division Accounts | Higher Command L1';

// Handle Activate / Deactivate Actions (MongoDB Atlas)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id > 0) {
        $targetUser = mcoll('users')->findOne([
            'id' => $user_id,
            'protocol_level' => 'Division'
        ]);

        if ($targetUser) {
            $targetName = $targetUser['name'] ?? 'Division Account';
            if ($action === 'activate') {
                mcoll('users')->updateOne(['id' => $user_id], ['$set' => ['status' => 'Active']]);
                mongo_audit_log($u['id'], 'DIVISION ACTIVATED', 'Higher Command activated Division account: ' . $targetName);
                flash('success', 'Division account for ' . $targetName . ' is now ACTIVE.');
            } elseif ($action === 'deactivate') {
                mcoll('users')->updateOne(['id' => $user_id], ['$set' => ['status' => 'Inactive']]);
                mongo_audit_log($u['id'], 'DIVISION DEACTIVATED', 'Higher Command deactivated Division account: ' . $targetName);
                flash('success', 'Division account for ' . $targetName . ' has been DEACTIVATED.');
            }
        } else {
            flash('error', 'Target division account not found or does not hold Division protocol level.');
        }
    }
    redirect(BASE_URL . '/manage_division.php');
}

// Fetch all Level 2 Division Accounts and their associated military units (MongoDB Atlas)
$divDocs = mcoll('users')->find([
    '$or' => [
        ['protocol_level' => 'Division'],
        ['department' => ['$regex' => 'Division', '$options' => 'i']]
    ]
], ['sort' => ['id' => 1]]);

$divisions = [];
foreach ($divDocs as $dDoc) {
    $div = mongo_doc_to_array($dDoc);
    $divId = (int)$div['id'];
    $unitDoc = mcoll('military_protocol_units')->findOne([
        'commander_id' => $divId,
        'level' => 'Division'
    ]);
    $div['unit_code'] = $unitDoc['code'] ?? null;
    $div['unit_name'] = $unitDoc['name'] ?? null;
    $div['hq_location'] = $unitDoc['hq_location'] ?? null;
    $divisions[] = $div;
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-head" style="margin-bottom:24px;">
    <div>
        <span class="eyebrow" style="color:#d4af37; font-weight:800; letter-spacing:2px;">HIGHER COMMAND LEVEL 1 CONTROL</span>
        <h1 style="color:#082d21; margin:4px 0 6px;">Manage Division Accounts</h1>
        <p style="color:#5e776a; font-size:14px; margin:0;">
            Exercise supreme command authority over subordinate Division-level command accounts, operational readiness, and authorization status.
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <a class="btn btn-dark" href="<?= BASE_URL ?>/protocol.php" style="background:#082d21; border:1px solid #d4af37; color:#fff;">
            &larr; Protocol Hierarchy
        </a>
        <a class="btn btn-light" href="<?= BASE_URL ?>/rulebook_management.php">
            SOP Rulebook Management &rarr;
        </a>
    </div>
</div>

<!-- HIGHER COMMAND BANNER -->
<section class="panel" style="margin-bottom:24px; padding:22px 28px; background:linear-gradient(135deg, #051d14 0%, #082d21 50%, #0d3b2b 100%); color:#ffffff; border-radius:18px; border:1px solid rgba(212,175,55,0.3); box-shadow:0 10px 30px rgba(6,40,30,0.15);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <span style="background:#f5d98d; color:#082d21; font-weight:900; font-size:11px; padding:3px 8px; border-radius:4px; font-family:monospace;">ARMED FORCES L1</span>
                <span style="color:#f5d98d; font-size:12px; font-weight:800; letter-spacing:1px; text-transform:uppercase;">Supreme Military Protocol Authority</span>
            </div>
            <h2 style="margin:0; font-size:22px; color:#ffffff;">Division Command Oversight (Level 2)</h2>
            <p style="margin:6px 0 0; color:#cbd5e1; font-size:13px; max-width:700px; line-height:1.5;">
                In accordance with Armed Forces protocol hierarchy, Higher Command controls Division commander account permissions while preserving decentralized tactical command for subordinate formations.
            </p>
        </div>
        <div style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:12px; padding:12px 18px; text-align:center;">
            <span style="display:block; font-size:11px; color:#cbd5e1; text-transform:uppercase; font-weight:700;">Division Accounts</span>
            <strong style="display:block; font-size:26px; color:#f5d98d;"><?= count($divisions) ?></strong>
            <small style="color:#86efac; font-size:11px;">Active Formations</small>
        </div>
    </div>
</section>

<!-- DIVISION ACCOUNTS REGISTER TABLE -->
<section class="panel" style="border-radius:18px; border:1px solid #e2e8f0; overflow:hidden;">
    <div class="panel-head" style="padding:20px 24px; background:#f8fafc; border-bottom:1px solid #e2e8f0;">
        <div>
            <h2 style="margin:0; font-size:18px; color:#0f172a;">Level 2 Division Formations &amp; Commanders</h2>
            <p style="margin:4px 0 0; color:#64748b; font-size:12px;">Review credential status, designated formations, and activate or deactivate command access.</p>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Division Formation</th>
                    <th>Commander &amp; Designation</th>
                    <th>Official Email</th>
                    <th>Command Code</th>
                    <th>Current Status</th>
                    <th>Higher Command Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($divisions as $div): ?>
                    <tr>
                        <td>
                            <strong style="color:#082d21; font-size:14px; display:block;">
                                <?= e($div['unit_name'] ?: '9th Infantry Division') ?>
                            </strong>
                            <small style="color:#64748b; font-family:monospace; font-weight:700;">
                                Unit: <?= e($div['unit_code'] ?: 'DIV-09-INF') ?> &bull; <?= e($div['hq_location'] ?: 'Savar/Mirpur') ?>
                            </small>
                        </td>
                        <td>
                            <div style="font-weight:700; color:#0f172a;">
                                <?= e($div['name']) ?>
                            </div>
                            <small style="color:#475569;">
                                <?= e($div['designation'] ?: 'Division Commander (Major General)') ?>
                            </small>
                        </td>
                        <td>
                            <span style="font-family:monospace; font-size:12px; color:#1e40af; background:#eff6ff; padding:3px 8px; border-radius:4px;">
                                <?= e($div['email']) ?>
                            </span>
                        </td>
                        <td>
                            <span style="font-family:monospace; font-weight:800; font-size:11px; padding:3px 7px; border-radius:4px; background:#f1f5f9; color:#334155;">
                                LEVEL 2 &bull; DIV
                            </span>
                        </td>
                        <td>
                            <?php if (($div['status'] ?? 'Active') === 'Active'): ?>
                                <span class="badge completed" style="background:#dcfce7; color:#166534; font-weight:800; padding:4px 10px;">
                                    &check; Active (Authorized)
                                </span>
                            <?php else: ?>
                                <span class="badge rejected" style="background:#fee2e2; color:#991b1b; font-weight:800; padding:4px 10px;">
                                    &bull; Deactivated
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex; gap:8px; align-items:center;">
                                <?php if (($div['status'] ?? 'Active') === 'Active'): ?>
                                    <form method="post" style="display:inline; margin:0;" onsubmit="return confirm('Deactivate Division account for <?= e($div['name']) ?>? The Division commander will be prevented from logging in.');">
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="user_id" value="<?= $div['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-light" style="font-size:11px; padding:4px 10px; color:#dc2626; border-color:#fca5a5; font-weight:700;">
                                            Deactivate Account
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="display:inline; margin:0;">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="user_id" value="<?= $div['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-primary" style="font-size:11px; padding:4px 10px; background:#16a34a; border-color:#16a34a; color:#fff; font-weight:700;">
                                            &check; Activate Account
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <a href="<?= BASE_URL ?>/reports.php?tab=protocol" class="table-link" style="font-size:11px;">
                                    View Reports
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($divisions)): ?>
                    <tr>
                        <td colspan="6" class="empty">No Division-level accounts currently configured.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

