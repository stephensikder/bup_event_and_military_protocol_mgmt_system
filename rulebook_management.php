<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$u = current_user();
$is_higher_cmd = ($u['protocol_level'] ?? '') === 'Higher Command' || has_role('Admin');
$is_military = is_military_protocol();

if (!$is_military && !has_role('Admin') && !is_event_director()) {
    flash('error', 'Classified Document: Military SOP Rulebooks are restricted to authorized defense personnel.');
    redirect(BASE_URL . '/index.php');
}

$pdo = db();
$page_title = 'Military SOP & Rulebook Management | BUP Protocol';

// Standardized Protocol Categories
$categories = [
    'CER' => 'Ceremonial & Parades',
    'VIP' => 'VIP & Dignitary Handling',
    'SEC' => 'Security & Access Control',
    'VEN' => 'Venue & Seating Protocol',
    'LOG' => 'Logistics & Convoy Operations',
    'MED' => 'Medical & Emergency Response',
    'COM' => 'Communications & Signaling',
    'ADM' => 'Administrative & Reporting',
    'REP' => 'Incident Reporting & Escalation'
];

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Acknowledgement action (any military user)
    if ($action === 'acknowledge') {
        $sop_id = (int)($_POST['sop_id'] ?? 0);
        if ($sop_id > 0) {
            try {
                $ackStmt = $pdo->prepare("INSERT INTO military_sop_acknowledgements (sop_id, user_id) VALUES (?, ?)");
                $ackStmt->execute([$sop_id, $u['id']]);
                flash('success', 'Standing Operating Procedure acknowledged and signed into official record.');
            } catch (Exception $e) {
                flash('info', 'You have already acknowledged this Standing Operating Procedure.');
            }
        }
        redirect(BASE_URL . '/rulebook_management.php');
    }

    if (!$is_higher_cmd) {
        flash('error', 'Security Policy Violation: Only Armed Forces Higher Command (Level 1) may author or update standing orders.');
        redirect(BASE_URL . '/rulebook_management.php');
    }

    if ($action === 'create' || $action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'CER');
        $cat_label = $categories[$category] ?? $category;
        $version = trim($_POST['version'] ?? '1.0');
        $applicable_level = trim($_POST['applicable_level'] ?? 'All Levels');
        $content = trim($_POST['content'] ?? '');
        $authorized_by = trim($_POST['authorized_by'] ?? 'Armed Forces Protocol Directorate');
        
        // Extended Fields (Sections A, B, C, D)
        $issuing_authority = trim($_POST['issuing_authority'] ?? 'Higher Command / Chief of Protocol');
        $approval_authority = trim($_POST['approval_authority'] ?? 'General Officer Commanding');
        $effective_date = !empty($_POST['effective_date']) ? $_POST['effective_date'] : date('Y-m-d');
        $promulgation_date = !empty($_POST['promulgation_date']) ? $_POST['promulgation_date'] : date('Y-m-d');
        $purpose_scope = trim($_POST['purpose_scope'] ?? '');
        $roles_responsibilities = trim($_POST['roles_responsibilities'] ?? '');
        $supersedes_id = trim($_POST['supersedes_id'] ?? '');
        $related_documents = trim($_POST['related_documents'] ?? '');
        $implementation_guidelines = trim($_POST['implementation_guidelines'] ?? '');
        $compliance_requirements = trim($_POST['compliance_requirements'] ?? '');
        $supporting_document = trim($_POST['supporting_document'] ?? '');
        $review_date = !empty($_POST['review_date']) ? $_POST['review_date'] : date('Y-m-d', strtotime('+1 year'));
        $acknowledgement_required = isset($_POST['acknowledgement_required']) ? 1 : 0;
        $classification = in_array($_POST['classification'] ?? '', ['Restricted', 'Confidential', 'Secret', 'Top Secret'], true) ? $_POST['classification'] : 'Restricted';
        $distribution_scope = trim($_POST['distribution_scope'] ?? 'All Units (L1-L8)');
        $status = in_array($_POST['status'] ?? '', ['Draft', 'Pending Approval', 'Approved', 'Active', 'Archived'], true) ? $_POST['status'] : 'Active';

        if ($title !== '' && $content !== '') {
            if ($action === 'create') {
                if ($code === '') {
                    $catCode = substr($category, 0, 3);
                    $cCnt = $pdo->query("SELECT COUNT(*) FROM military_sops WHERE code LIKE 'SOP-{$catCode}-%'")->fetchColumn();
                    $code = sprintf("SOP-%s-%03d", $catCode, (int)$cCnt + 1);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO military_sops (
                        code, title, category, version, applicable_level, content, authorized_by, status,
                        issuing_authority, approval_authority, effective_date, promulgation_date,
                        purpose_scope, roles_responsibilities, supersedes_id, related_documents,
                        implementation_guidelines, compliance_requirements, supporting_document,
                        review_date, acknowledgement_required, classification, distribution_scope
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $code, $title, $cat_label, $version, $applicable_level, $content, $authorized_by, $status,
                    $issuing_authority, $approval_authority, $effective_date, $promulgation_date,
                    $purpose_scope, $roles_responsibilities, $supersedes_id, $related_documents,
                    $implementation_guidelines, $compliance_requirements, $supporting_document,
                    $review_date, $acknowledgement_required, $classification, $distribution_scope
                ]);

                $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)")
                    ->execute([$u['id'], 'SOP PROMULGATED', "Higher Command issued new SOP: {$code} - {$title} ({$classification})"]);
                flash('success', "Military SOP {$code} ({$title}) successfully promulgated.");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE military_sops SET
                        title = ?, category = ?, version = ?, applicable_level = ?, content = ?, authorized_by = ?, status = ?,
                        issuing_authority = ?, approval_authority = ?, effective_date = ?, promulgation_date = ?,
                        purpose_scope = ?, roles_responsibilities = ?, supersedes_id = ?, related_documents = ?,
                        implementation_guidelines = ?, compliance_requirements = ?, supporting_document = ?,
                        review_date = ?, acknowledgement_required = ?, classification = ?, distribution_scope = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $title, $cat_label, $version, $applicable_level, $content, $authorized_by, $status,
                    $issuing_authority, $approval_authority, $effective_date, $promulgation_date,
                    $purpose_scope, $roles_responsibilities, $supersedes_id, $related_documents,
                    $implementation_guidelines, $compliance_requirements, $supporting_document,
                    $review_date, $acknowledgement_required, $classification, $distribution_scope,
                    $id
                ]);

                $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)")
                    ->execute([$u['id'], 'SOP UPDATED', "Updated SOP #{$id} ({$title}) to version {$version}"]);
                flash('success', "Rulebook #{$id} updated successfully.");
            }

            if (function_exists('mongo_sync_table')) {
                mongo_sync_table('military_sops');
            }
        } else {
            flash('error', 'Title and procedural standing orders content are mandatory.');
        }
        redirect(BASE_URL . '/rulebook_management.php');
    }
}

// Fetch all military SOPs with acknowledgement counts
$sops = $pdo->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM military_sop_acknowledgements a WHERE a.sop_id = s.id) AS ack_count,
           (SELECT COUNT(*) FROM military_sop_acknowledgements a WHERE a.sop_id = s.id AND a.user_id = {$u['id']}) AS user_has_acked
    FROM military_sops s
    ORDER BY s.id ASC
")->fetchAll();

// Edit target if requested
$edit_sop = null;
if (isset($_GET['edit']) && $is_higher_cmd) {
    $edit_id = (int)$_GET['edit'];
    $stmtEdit = $pdo->prepare("SELECT * FROM military_sops WHERE id = ?");
    $stmtEdit->execute([$edit_id]);
    $edit_sop = $stmtEdit->fetch();
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-head" style="margin-bottom:24px;">
    <div>
        <span class="eyebrow" style="color:#d4af37; font-weight:800; letter-spacing:2px;">MILITARY STANDING ORDERS &amp; PROTOCOL</span>
        <h1 style="color:#082d21; margin:4px 0 6px;">SOP / Rulebook Management</h1>
        <p style="color:#5e776a; font-size:14px; margin:0;">
            Official military guidelines, ceremonial guard regulations, escort procedures, and chain-of-command standards.
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <a class="btn btn-dark" href="<?= BASE_URL ?>/protocol.php" style="background:#082d21; border:1px solid #d4af37; color:#fff;">
            &larr; Protocol Hierarchy
        </a>
        <?php if ($is_higher_cmd): ?>
            <a class="btn btn-primary" href="#add-sop-section" style="background:linear-gradient(135deg, #082d21 0%, #13533e 100%); border:1px solid #d4af37; color:#fff; font-weight:800;">
                + Author New SOP Rulebook
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- MILITARY RULEBOOK OVERVIEW BANNER -->
<section class="panel" style="margin-bottom:24px; padding:22px 28px; background:linear-gradient(135deg, #051d14 0%, #082d21 50%, #0d3b2b 100%); color:#ffffff; border-radius:18px; border:1px solid rgba(212,175,55,0.3); box-shadow:0 10px 30px rgba(6,40,30,0.15);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <span style="background:#f5d98d; color:#082d21; font-weight:900; font-size:11px; padding:3px 8px; border-radius:4px; font-family:monospace;">ARMED FORCES DOCTRINE</span>
                <span style="color:#f5d98d; font-size:12px; font-weight:800; letter-spacing:1px; text-transform:uppercase;">Protocol &amp; Ceremony Manual</span>
            </div>
            <h2 style="margin:0; font-size:22px; color:#ffffff;">Armed Forces Standing Operating Procedures</h2>
            <p style="margin:6px 0 0; color:#cbd5e1; font-size:13px; max-width:720px; line-height:1.5;">
                These authoritative SOPs govern all ceremonial guards, VIP cordons, gun salutes, and motorcade clearance formations executed by Armed Forces and BUP military protocol echelons.
            </p>
        </div>
        <div style="display:flex; gap:12px;">
            <div style="background:rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.18); border-radius:12px; padding:12px 18px; text-align:center;">
                <span style="display:block; font-size:11px; color:#cbd5e1; text-transform:uppercase; font-weight:700;">Promulgated SOPs</span>
                <strong style="display:block; font-size:24px; color:#f5d98d;"><?= count($sops) ?></strong>
                <small style="color:#86efac; font-size:11px;">Active Doctrines</small>
            </div>
        </div>
    </div>
</section>

<!-- SOP LISTING GRID -->
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:20px; margin-bottom:28px;">
    <?php foreach ($sops as $sop): ?>
        <?php
        $classColor = '#10b981';
        if ($sop['classification'] === 'Top Secret') $classColor = '#dc2626';
        elseif ($sop['classification'] === 'Secret') $classColor = '#ea580c';
        elseif ($sop['classification'] === 'Confidential') $classColor = '#d97706';
        ?>
        <div class="panel" style="border-radius:16px; border:1px solid #e2e8f0; padding:22px; display:flex; flex-direction:column; justify-content:space-between; background:#ffffff; box-shadow:0 4px 16px rgba(0,0,0,0.03);">
            <div>
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                    <div style="display:flex; gap:6px; align-items:center;">
                        <span style="font-family:monospace; font-weight:900; font-size:12px; background:#082d21; color:#f5d98d; padding:4px 8px; border-radius:6px;">
                            <?= e($sop['code']) ?>
                        </span>
                        <span style="font-size:10px; font-weight:800; background:<?= $classColor ?>; color:#ffffff; padding:3px 7px; border-radius:4px; text-transform:uppercase;">
                            <?= e($sop['classification'] ?? 'Restricted') ?>
                        </span>
                    </div>
                    <span style="font-size:11px; font-weight:700; background:#f1f5f9; color:#475569; padding:4px 8px; border-radius:6px;">
                        v<?= e($sop['version']) ?> &bull; <?= e($sop['status']) ?>
                    </span>
                </div>

                <h3 style="margin:0 0 6px; font-size:17px; color:#0f172a; line-height:1.3;">
                    <?= e($sop['title']) ?>
                </h3>
                <span style="display:inline-block; font-size:11px; color:#0369a1; font-weight:700; margin-bottom:10px;">
                    📂 <?= e($sop['category']) ?> &bull; Scope: <?= e($sop['applicable_level']) ?>
                </span>

                <?php if (!empty($sop['purpose_scope'])): ?>
                    <p style="font-size:12px; color:#475569; margin:0 0 10px; line-height:1.4;">
                        <strong>Purpose:</strong> <?= e($sop['purpose_scope']) ?>
                    </p>
                <?php endif; ?>

                <div style="background:#f8fafc; border-left:3px solid #082d21; padding:12px 14px; border-radius:0 8px 8px 0; font-size:12px; color:#334155; line-height:1.6; max-height:140px; overflow-y:auto; margin-bottom:14px; white-space:pre-wrap;">
                    <?= e($sop['content']) ?>
                </div>

                <div style="font-size:11px; color:#64748b; margin-bottom:12px; display:flex; flex-direction:column; gap:4px; background:#fdfdfd; padding:8px 10px; border-radius:6px; border:1px solid #f1f5f9;">
                    <div><strong>Issuing Authority:</strong> <?= e($sop['issuing_authority'] ?? $sop['authorized_by']) ?></div>
                    <div><strong>Effective Date:</strong> <?= !empty($sop['effective_date']) ? date('d M Y', strtotime($sop['effective_date'])) : 'Promulgated' ?> &bull; <strong>Next Review:</strong> <?= !empty($sop['review_date']) ? date('d M Y', strtotime($sop['review_date'])) : 'Annual' ?></div>
                    <?php if ((int)($sop['acknowledgement_required'] ?? 0) === 1): ?>
                        <div style="color:#0369a1; font-weight:700;">
                            ✍️ Mandatory Acknowledgement Required (<?= (int)$sop['ack_count'] ?> Signed)
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #f1f5f9; padding-top:12px; font-size:11px; color:#64748b;">
                <div>
                    <?php if ((int)($sop['acknowledgement_required'] ?? 0) === 1): ?>
                        <?php if ((int)$sop['user_has_acked'] > 0): ?>
                            <span style="color:#16a34a; font-weight:800;">&check; Acknowledged</span>
                        <?php else: ?>
                            <form method="post" style="display:inline; margin:0;">
                                <input type="hidden" name="action" value="acknowledge">
                                <input type="hidden" name="sop_id" value="<?= $sop['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="font-size:10px; padding:3px 8px; background:#082d21; color:#f5d98d; border:none; border-radius:4px; font-weight:700;">
                                    Sign &amp; Acknowledge &rarr;
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span style="color:#16a34a; font-weight:700;">&check; Official Doctrine</span>
                    <?php endif; ?>
                </div>

                <div style="display:flex; gap:10px; align-items:center;">
                    <button type="button" class="btn btn-light btn-sm" onclick="viewFullSop(<?= htmlspecialchars(json_encode($sop), ENT_QUOTES, 'UTF-8') ?>)" style="font-size:11px; padding:4px 8px;">
                        Full Document &rarr;
                    </button>
                    <?php if ($is_higher_cmd): ?>
                        <a href="<?= BASE_URL ?>/rulebook_management.php?edit=<?= $sop['id'] ?>#add-sop-section" class="table-link" style="font-weight:700; color:#1e40af; font-size:11px;">
                            Edit
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- FULL SOP MODAL VIEWER -->
<div id="fullSopModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.65); z-index:99999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#ffffff; color:#0f172a; border-radius:14px; max-width:760px; width:100%; padding:28px; box-shadow:0 24px 48px rgba(0,0,0,0.3); max-height:88vh; overflow-y:auto; border:2px solid #082d21;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #082d21; padding-bottom:14px; margin-bottom:16px;">
            <div>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:4px;">
                    <span id="mSopCode" style="background:#082d21; color:#f5d98d; font-size:12px; font-weight:900; padding:3px 8px; border-radius:4px; font-family:monospace;"></span>
                    <span id="mSopClass" style="font-size:10px; font-weight:800; padding:3px 7px; border-radius:4px; color:#fff; text-transform:uppercase;"></span>
                    <span id="mSopVer" style="font-size:11px; color:#475569; font-weight:700;"></span>
                </div>
                <h2 id="mSopTitle" style="margin:4px 0 0; font-size:19px; color:#082d21;"></h2>
            </div>
            <button type="button" onclick="document.getElementById('fullSopModal').style.display='none'" style="border:none; background:transparent; font-size:26px; cursor:pointer; color:#64748b;">&times;</button>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px; background:#f8fafc; padding:12px 14px; border-radius:8px; font-size:12px; border:1px solid #e2e8f0;">
            <div><strong>Issuing Authority:</strong> <span id="mSopIssue"></span></div>
            <div><strong>Approval Authority:</strong> <span id="mSopApprove"></span></div>
            <div><strong>Effective Date:</strong> <span id="mSopEff"></span></div>
            <div><strong>Next Review Date:</strong> <span id="mSopRev"></span></div>
            <div><strong>Distribution Scope:</strong> <span id="mSopDist"></span></div>
            <div><strong>Category:</strong> <span id="mSopCat"></span></div>
        </div>

        <div style="margin-bottom:16px;">
            <h4 style="margin:0 0 6px; font-size:13px; color:#082d21; text-transform:uppercase; letter-spacing:1px;">1. Purpose &amp; Scope</h4>
            <div id="mSopScope" style="font-size:13px; line-height:1.6; color:#334155; background:#fff; border:1px solid #e2e8f0; padding:10px 12px; border-radius:6px;"></div>
        </div>

        <div style="margin-bottom:16px;">
            <h4 style="margin:0 0 6px; font-size:13px; color:#082d21; text-transform:uppercase; letter-spacing:1px;">2. Roles &amp; Responsibilities</h4>
            <div id="mSopRoles" style="font-size:13px; line-height:1.6; color:#334155; background:#fff; border:1px solid #e2e8f0; padding:10px 12px; border-radius:6px;"></div>
        </div>

        <div style="margin-bottom:16px;">
            <h4 style="margin:0 0 6px; font-size:13px; color:#082d21; text-transform:uppercase; letter-spacing:1px;">3. Procedural Standing Orders</h4>
            <div id="mSopContent" style="font-size:13px; line-height:1.6; color:#334155; background:#f8fafc; border-left:3px solid #082d21; padding:12px 14px; border-radius:0 6px 6px 0; white-space:pre-wrap;"></div>
        </div>

        <div style="margin-bottom:16px;">
            <h4 style="margin:0 0 6px; font-size:13px; color:#082d21; text-transform:uppercase; letter-spacing:1px;">4. Compliance Requirements</h4>
            <div id="mSopCompliance" style="font-size:13px; line-height:1.6; color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; padding:10px 12px; border-radius:6px;"></div>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px solid #e2e8f0; padding-top:14px; margin-top:16px;">
            <button type="button" onclick="window.print()" class="btn btn-light" style="font-size:12px;">🖨️ Print Doctrine</button>
            <button type="button" class="btn btn-dark" onclick="document.getElementById('fullSopModal').style.display='none'" style="background:#082d21;">Close Window</button>
        </div>
    </div>
</div>

<script>
function viewFullSop(sop) {
    document.getElementById('mSopCode').textContent = sop.code || 'SOP';
    document.getElementById('mSopTitle').textContent = sop.title || '';
    document.getElementById('mSopVer').textContent = 'Version ' + (sop.version || '1.0') + ' (' + (sop.status || 'Active') + ')';
    
    const cls = sop.classification || 'Restricted';
    const cSpan = document.getElementById('mSopClass');
    cSpan.textContent = cls;
    cSpan.style.background = (cls === 'Top Secret') ? '#dc2626' : ((cls === 'Secret') ? '#ea580c' : ((cls === 'Confidential') ? '#d97706' : '#10b981'));

    document.getElementById('mSopIssue').textContent = sop.issuing_authority || sop.authorized_by || 'Higher Command';
    document.getElementById('mSopApprove').textContent = sop.approval_authority || 'General Officer Commanding';
    document.getElementById('mSopEff').textContent = sop.effective_date || 'N/A';
    document.getElementById('mSopRev').textContent = sop.review_date || 'Annual Review';
    document.getElementById('mSopDist').textContent = sop.distribution_scope || 'All Units (L1-L8)';
    document.getElementById('mSopCat').textContent = sop.category || 'General';

    document.getElementById('mSopScope').textContent = sop.purpose_scope || 'Standing operating procedure governing tactical and ceremonial execution across authorized protocol units.';
    document.getElementById('mSopRoles').textContent = sop.roles_responsibilities || 'Echelon commanders ensure subordinates comply strictly with these authorized instructions.';
    document.getElementById('mSopContent').textContent = sop.content || '';
    document.getElementById('mSopCompliance').textContent = sop.compliance_requirements || 'Mandatory adherence to military protocol code. Violations subject to formal command inquiry.';

    document.getElementById('fullSopModal').style.display = 'flex';
}
</script>

<!-- AUTHOR / EDIT SOP FORM (HIGHER COMMAND L1 ONLY) -->
<?php if ($is_higher_cmd): ?>
<section id="add-sop-section" class="panel form-panel" style="border-radius:18px; border:2px solid #d4af37; overflow:hidden; margin-top:20px;">
    <div class="panel-head" style="padding:22px 28px; background:linear-gradient(135deg, #082d21 0%, #13533e 100%); color:#fff;">
        <div>
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                <span style="background:#d4af37; color:#082d21; font-size:10px; font-weight:900; padding:2px 6px; border-radius:4px; font-family:monospace;">HIGHER COMMAND AUTH</span>
                <span style="color:#fef3c7; font-size:12px; font-weight:700; text-transform:uppercase;">Military Doctrine Promulgation Desk</span>
            </div>
            <h2 style="margin:0; font-size:20px; color:#ffffff;">
                <?= $edit_sop ? 'Edit Standing Operating Procedure (' . e($edit_sop['code']) . ')' : 'Promulgate New Armed Forces Protocol SOP' ?>
            </h2>
            <p style="margin:4px 0 0; color:#d1fae5; font-size:12px;">
                Authored rulebooks propagate across all 8 military echelons with strict immutability for subordinate commanders.
            </p>
        </div>
        <?php if ($edit_sop): ?>
            <a class="btn btn-light" href="<?= BASE_URL ?>/rulebook_management.php">Cancel Edit</a>
        <?php endif; ?>
    </div>

    <form method="post" class="form-grid" style="padding:24px 28px;">
        <input type="hidden" name="action" value="<?= $edit_sop ? 'edit' : 'create' ?>">
        <?php if ($edit_sop): ?>
            <input type="hidden" name="id" value="<?= $edit_sop['id'] ?>">
        <?php endif; ?>

        <!-- SECTION A: CLASSIFICATION & IDENTITY -->
        <div class="span-2" style="background:#f8fafc; border-left:4px solid #d4af37; padding:10px 14px; margin-bottom:8px; border-radius:0 6px 6px 0;">
            <strong style="color:#082d21; font-size:13px; text-transform:uppercase; letter-spacing:1px;">Section A &bull; Category &amp; Document Classification</strong>
        </div>

        <label>
            Protocol Category *
            <select name="category" id="sopCatSelect" required class="form-control" onchange="autoSuggestCode(this.value)">
                <?php foreach ($categories as $k => $label): ?>
                    <option value="<?= $k ?>" <?= (($edit_sop['category'] ?? '') === $label || ($edit_sop['category'] ?? '') === $k) ? 'selected' : '' ?>>
                        <?= e($k) ?> &mdash; <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            SOP Document Code *
            <input type="text" name="code" id="sopCodeInput" value="<?= e($edit_sop['code'] ?? 'SOP-CER-005') ?>" required placeholder="e.g. SOP-CER-005" class="form-control">
            <small style="color:#64748b; font-size:10px;">Format: SOP-[CAT]-[SEQ]</small>
        </label>

        <label>
            Doctrine / SOP Title *
            <input type="text" name="title" value="<?= e($edit_sop['title'] ?? '') ?>" required placeholder="e.g. 21-Gun &amp; Ceremonial Artillery Salute Guidelines" class="form-control">
        </label>

        <label>
            Document Classification
            <select name="classification" class="form-control">
                <option value="Restricted" <?= (($edit_sop['classification'] ?? '') === 'Restricted') ? 'selected' : '' ?>>Restricted (Standard Unit Access)</option>
                <option value="Confidential" <?= (($edit_sop['classification'] ?? '') === 'Confidential') ? 'selected' : '' ?>>Confidential (Command Access)</option>
                <option value="Secret" <?= (($edit_sop['classification'] ?? '') === 'Secret') ? 'selected' : '' ?>>Secret (Higher Command &amp; Division)</option>
                <option value="Top Secret" <?= (($edit_sop['classification'] ?? '') === 'Top Secret') ? 'selected' : '' ?>>Top Secret (Higher Command Only)</option>
            </select>
        </label>

        <!-- SECTION B: AUTHORITY & APPROVAL -->
        <div class="span-2" style="background:#f8fafc; border-left:4px solid #082d21; padding:10px 14px; margin:8px 0; border-radius:0 6px 6px 0;">
            <strong style="color:#082d21; font-size:13px; text-transform:uppercase; letter-spacing:1px;">Section B &bull; Authority &amp; Promulgation Control</strong>
        </div>

        <label>
            Issuing Authority
            <input type="text" name="issuing_authority" value="<?= e($edit_sop['issuing_authority'] ?? 'Armed Forces Headquarters / Protocol Directorate') ?>" class="form-control">
        </label>

        <label>
            Approval Authority
            <input type="text" name="approval_authority" value="<?= e($edit_sop['approval_authority'] ?? 'General Officer Commanding (GOC)') ?>" class="form-control">
        </label>

        <label>
            Effective Date
            <input type="date" name="effective_date" value="<?= e($edit_sop['effective_date'] ?? date('Y-m-d')) ?>" class="form-control">
        </label>

        <label>
            Next Mandatory Review Date
            <input type="date" name="review_date" value="<?= e($edit_sop['review_date'] ?? date('Y-m-d', strtotime('+1 year'))) ?>" class="form-control">
        </label>

        <!-- SECTION C: SCOPE, ROLES & APPLICABILITY -->
        <div class="span-2" style="background:#f8fafc; border-left:4px solid #082d21; padding:10px 14px; margin:8px 0; border-radius:0 6px 6px 0;">
            <strong style="color:#082d21; font-size:13px; text-transform:uppercase; letter-spacing:1px;">Section C &bull; Scope &amp; Tactical Responsibilities</strong>
        </div>

        <label>
            Distribution Scope
            <select name="distribution_scope" class="form-control">
                <option value="All Units (L1-L8)" <?= (($edit_sop['distribution_scope'] ?? '') === 'All Units (L1-L8)') ? 'selected' : '' ?>>All Units (L1-L8)</option>
                <option value="Division & Above (L1-L2)" <?= (($edit_sop['distribution_scope'] ?? '') === 'Division & Above (L1-L2)') ? 'selected' : '' ?>>Division &amp; Above (L1-L2)</option>
                <option value="Brigade & Above (L1-L3)" <?= (($edit_sop['distribution_scope'] ?? '') === 'Brigade & Above (L1-L3)') ? 'selected' : '' ?>>Brigade &amp; Above (L1-L3)</option>
                <option value="Battalion & Below (L4-L8)" <?= (($edit_sop['distribution_scope'] ?? '') === 'Battalion & Below (L4-L8)') ? 'selected' : '' ?>>Battalion &amp; Below (L4-L8)</option>
            </select>
        </label>

        <label>
            Supersedes / Replaces
            <input type="text" name="supersedes_id" value="<?= e($edit_sop['supersedes_id'] ?? 'None') ?>" placeholder="e.g. SOP-CER-001 or None" class="form-control">
        </label>

        <label class="span-2">
            Purpose &amp; Operational Scope
            <textarea name="purpose_scope" rows="2" class="form-control" placeholder="Define the tactical objective, scope of authority and operational context..."><?= e($edit_sop['purpose_scope'] ?? '') ?></textarea>
        </label>

        <label class="span-2">
            Roles &amp; Echelon Responsibilities
            <textarea name="roles_responsibilities" rows="2" class="form-control" placeholder="Specify responsibilities for Higher Command, Division, Brigade, Battalion, Company, Platoon, Section and Soldier..."><?= e($edit_sop['roles_responsibilities'] ?? '') ?></textarea>
        </label>

        <!-- SECTION D: PROCEDURES & COMPLIANCE -->
        <div class="span-2" style="background:#f8fafc; border-left:4px solid #082d21; padding:10px 14px; margin:8px 0; border-radius:0 6px 6px 0;">
            <strong style="color:#082d21; font-size:13px; text-transform:uppercase; letter-spacing:1px;">Section D &bull; Standing Orders, Procedures &amp; Compliance</strong>
        </div>

        <label class="span-2">
            Step-by-Step Tactical Instructions &amp; Standing Orders *
            <textarea name="content" rows="5" required placeholder="Detailed step-by-step procedural instructions, sequence of events, tactical checks, and drill requirements..." class="form-control"><?= e($edit_sop['content'] ?? '') ?></textarea>
        </label>

        <label class="span-2">
            Mandatory Compliance &amp; Failure Escalation
            <textarea name="compliance_requirements" rows="2" class="form-control" placeholder="Mandatory zero-defect rules, safety protocols, inspection checkpoints..."><?= e($edit_sop['compliance_requirements'] ?? '') ?></textarea>
        </label>

        <label>
            Document Status
            <select name="status" class="form-control">
                <option value="Active" <?= (($edit_sop['status'] ?? 'Active') === 'Active') ? 'selected' : '' ?>>Active (Promulgated)</option>
                <option value="Approved" <?= (($edit_sop['status'] ?? '') === 'Approved') ? 'selected' : '' ?>>Approved (Awaiting Rollout)</option>
                <option value="Pending Approval" <?= (($edit_sop['status'] ?? '') === 'Pending Approval') ? 'selected' : '' ?>>Pending Approval</option>
                <option value="Draft" <?= (($edit_sop['status'] ?? '') === 'Draft') ? 'selected' : '' ?>>Draft</option>
                <option value="Archived" <?= (($edit_sop['status'] ?? '') === 'Archived') ? 'selected' : '' ?>>Archived</option>
            </select>
        </label>

        <div style="display:flex; align-items:center; gap:8px; padding-top:22px;">
            <input type="checkbox" name="acknowledgement_required" id="ackReq" value="1" <?= ((int)($edit_sop['acknowledgement_required'] ?? 1) === 1) ? 'checked' : '' ?> style="width:18px; height:18px;">
            <label for="ackReq" style="margin:0; font-weight:700; color:#082d21; font-size:13px; cursor:pointer;">
                Require Subordinate Echelon Receipt Acknowledgement
            </label>
        </div>

        <div class="span-2" style="margin-top:14px;">
            <button class="btn btn-primary" type="submit" style="background:#082d21; border:1px solid #d4af37; padding:12px 28px; font-weight:800; color:#fff;">
                <?= $edit_sop ? 'Update SOP Rulebook' : 'Promulgate Rulebook &rarr;' ?>
            </button>
        </div>
    </form>
</section>

<script>
function autoSuggestCode(cat) {
    const codeInp = document.getElementById('sopCodeInput');
    if (!codeInp) return;
    const catCode = cat.substring(0, 3).toUpperCase();
    const randSeq = Math.floor(Math.random() * 90) + 10;
    codeInp.value = 'SOP-' + catCode + '-0' + randSeq;
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
