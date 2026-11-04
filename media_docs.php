<?php
require_once __DIR__ . '/config/auth.php';
require_login();

// Access control: Communication Lead, Coordinator, Director, Admin
if (!is_communication_lead() && !is_event_coordinator() && !is_event_director() && !has_role('Admin')) {
    flash('error', 'Access restricted: Media & Documentation is managed by Communication Lead.');
    redirect(BASE_URL . '/dashboard.php');
}

$pdo = db();
$page_title = 'Media & Documentation | BUP Protocol';

// Ensure uploads/media directory exists
$uploadDir = __DIR__ . '/uploads/media';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD / UPLOAD DOCUMENT OR MEDIA RECORD
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? 'Press Release');
        $event_id = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;
        $description = trim($_POST['description'] ?? '');
        $media_task = trim($_POST['media_task'] ?? '');
        $responsible_staff_id = !empty($_POST['responsible_staff_id']) ? (int)$_POST['responsible_staff_id'] : null;
        $approval_status = $_POST['approval_status'] ?? 'Pending Review';
        $documentation_status = $_POST['documentation_status'] ?? 'In Progress';

        if ($title === '') {
            flash('error', 'Document title is required.');
            redirect(BASE_URL . '/media_docs.php');
        }

        $filePath = null;
        $fileType = null;
        $fileSize = 0;

        // Check if file uploaded
        if (!empty($_FILES['doc_file']['name']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
            $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'mp4', 'zip'];
            $fileInfo = pathinfo($_FILES['doc_file']['name']);
            $ext = strtolower($fileInfo['extension'] ?? '');

            if (!in_array($ext, $allowedExtensions, true)) {
                flash('error', 'Invalid file type. Allowed formats: PDF, DOC, DOCX, JPG, PNG, MP4, ZIP.');
                redirect(BASE_URL . '/media_docs.php');
            }

            if ($_FILES['doc_file']['size'] > 25 * 1024 * 1024) { // 25MB limit
                flash('error', 'File size exceeds maximum limit of 25MB.');
                redirect(BASE_URL . '/media_docs.php');
            }

            $fileName = 'media_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destination = $uploadDir . '/' . $fileName;

            if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $destination)) {
                $filePath = 'uploads/media/' . $fileName;
                $fileType = $_FILES['doc_file']['type'];
                $fileSize = (int)$_FILES['doc_file']['size'];
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO media_documents 
            (event_id, title, category, description, file_path, file_type, file_size, responsible_staff_id, approval_status, media_task, documentation_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $event_id, $title, $category, $description, $filePath, $fileType, $fileSize,
            $responsible_staff_id, $approval_status, $media_task, $documentation_status
        ]);

        $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'Media Document Created', ?)")
            ->execute([current_user()['id'], 'Created media/doc record: "' . $title . '" (' . $category . ')']);

        flash('success', 'Media & Documentation record <strong>' . e($title) . '</strong> created successfully.');
        redirect(BASE_URL . '/media_docs.php');
    }

    // 2. UPDATE APPROVAL STATUS
    if ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $approval_status = $_POST['approval_status'] ?? 'Approved';
        $documentation_status = $_POST['documentation_status'] ?? 'Completed';

        $pdo->prepare("
            UPDATE media_documents 
            SET approval_status = ?, documentation_status = ? 
            WHERE id = ?
        ")->execute([$approval_status, $documentation_status, $id]);

        flash('success', 'Document status updated successfully.');
        redirect(BASE_URL . '/media_docs.php');
    }
}

// Search and filter
$q = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "
    SELECT m.*, e.name as event_title, u.name as staff_name
    FROM media_documents m
    LEFT JOIN events e ON e.id = m.event_id
    LEFT JOIN users u ON u.id = m.responsible_staff_id
    WHERE 1=1
";
$params = [];

if ($q !== '') {
    $sql .= " AND (m.title LIKE ? OR m.description LIKE ? OR m.media_task LIKE ?)";
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like]);
}
if ($categoryFilter !== '') {
    $sql .= " AND m.category = ?";
    $params[] = $categoryFilter;
}
if ($statusFilter !== '') {
    $sql .= " AND (m.approval_status = ? OR m.documentation_status = ?)";
    $params[] = $statusFilter;
    $params[] = $statusFilter;
}

$sql .= " ORDER BY m.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Events & Staff for dropdown
$events = $pdo->query("SELECT id, name as title FROM events ORDER BY event_date DESC")->fetchAll();
$staffMembers = $pdo->query("SELECT id, name FROM users WHERE department LIKE '%Comm%' OR role = 'Staff / Volunteer' ORDER BY name")->fetchAll();

// Statistics
$totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM media_documents")->fetchColumn();
$approvedDocs = (int)$pdo->query("SELECT COUNT(*) FROM media_documents WHERE approval_status = 'Approved'")->fetchColumn();
$pendingReview = (int)$pdo->query("SELECT COUNT(*) FROM media_documents WHERE approval_status = 'Pending Review'")->fetchColumn();
$activeTasks = (int)$pdo->query("SELECT COUNT(*) FROM media_documents WHERE documentation_status = 'In Progress'")->fetchColumn();

require __DIR__ . '/includes/header.php';
?>

        <!-- HEADER -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:24px;">
            <div>
                <span class="eyebrow" style="color:#0284c7; font-weight:800;">COMMUNICATION &amp; MEDIA CELL</span>
                <h1 style="margin:2px 0 0; font-size:24px; color:#082d21;">Media &amp; Documentation Center</h1>
                <p style="margin:4px 0 0; color:#64748b; font-size:13px;">Manage press releases, photo/video archives, broadcast tasks, and official documentation approvals.</p>
            </div>
            <div>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('addDocModal').style.display='flex'">
                    + New Media / Document
                </button>
            </div>
        </div>

        <!-- STAT CARDS -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #0284c7;">
                <span class="eyebrow" style="color:#0284c7;">MEDIA REPOSITORIES</span>
                <div style="font-size:26px; font-weight:800; color:#082d21; margin-top:4px;"><?= $totalDocs ?></div>
                <small style="color:#64748b;">Registered media &amp; documentation</small>
            </div>
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #10b981;">
                <span class="eyebrow" style="color:#10b981;">OFFICIALLY APPROVED</span>
                <div style="font-size:26px; font-weight:800; color:#082d21; margin-top:4px;"><?= $approvedDocs ?></div>
                <small style="color:#64748b;">Cleared for publication</small>
            </div>
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #f59e0b;">
                <span class="eyebrow" style="color:#f59e0b;">PENDING REVIEW</span>
                <div style="font-size:26px; font-weight:800; color:#b45309; margin-top:4px;"><?= $pendingReview ?></div>
                <small style="color:#64748b;">Awaiting lead / command sign-off</small>
            </div>
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #6366f1;">
                <span class="eyebrow" style="color:#6366f1;">ACTIVE MEDIA TASKS</span>
                <div style="font-size:26px; font-weight:800; color:#082d21; margin-top:4px;"><?= $activeTasks ?></div>
                <small style="color:#64748b;">Live video / photography teams</small>
            </div>
        </div>

        <!-- SEARCH AND FILTER -->
        <section class="panel" style="padding:16px; margin-bottom:20px;">
            <form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search document title, task, remarks..." style="flex:1; min-width:240px; padding:9px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
                <select name="category" style="padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
                    <option value="">All Categories</option>
                    <option value="Press Release" <?= $categoryFilter === 'Press Release' ? 'selected' : '' ?>>Press Release</option>
                    <option value="Photography" <?= $categoryFilter === 'Photography' ? 'selected' : '' ?>>Photography</option>
                    <option value="Live Stream / Video" <?= $categoryFilter === 'Live Stream / Video' ? 'selected' : '' ?>>Live Stream / Video</option>
                    <option value="Social Media" <?= $categoryFilter === 'Social Media' ? 'selected' : '' ?>>Social Media</option>
                    <option value="Official Briefing" <?= $categoryFilter === 'Official Briefing' ? 'selected' : '' ?>>Official Briefing</option>
                    <option value="Documentation" <?= $categoryFilter === 'Documentation' ? 'selected' : '' ?>>Documentation</option>
                </select>
                <select name="status" style="padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
                    <option value="">All Statuses</option>
                    <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="Pending Review" <?= $statusFilter === 'Pending Review' ? 'selected' : '' ?>>Pending Review</option>
                    <option value="In Progress" <?= $statusFilter === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                </select>
                <button type="submit" class="btn btn-dark" style="padding:9px 16px;">Filter</button>
                <?php if ($q !== '' || $categoryFilter !== '' || $statusFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/media_docs.php" class="btn btn-light" style="padding:9px 14px;">Reset</a>
                <?php endif; ?>
            </form>
        </section>

        <!-- DOCUMENTS TABLE -->
        <section class="panel">
            <div class="panel-head">
                <div>
                    <span class="eyebrow">DOCUMENT ARCHIVE</span>
                    <h2>Media &amp; Official Document Records (<?= count($documents) ?>)</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table class="user-table" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Document Title &amp; Category</th>
                            <th>Associated Event</th>
                            <th>Assigned Task</th>
                            <th>Responsible Staff</th>
                            <th>Approval Status</th>
                            <th>Task Status</th>
                            <th>Upload Date</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($documents)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:30px; color:#64748b;">No media or documentation records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td>
                                    <strong style="color:#082d21;"><?= e($doc['title']) ?></strong>
                                    <span class="table-subtext" style="color:#0284c7; font-weight:600;">
                                        <?= e($doc['category']) ?>
                                    </span>
                                    <?php if (!empty($doc['description'])): ?>
                                        <small style="color:#64748b; display:block; margin-top:2px;"><?= e(mb_strimwidth($doc['description'], 0, 80, '...')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($doc['event_title'])): ?>
                                        <span class="badge badge-subtle">📅 <?= e($doc['event_title']) ?></span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">General University</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= e($doc['media_task'] ?: 'Press & Media Coverage') ?></strong>
                                </td>
                                <td>
                                    <?= e($doc['staff_name'] ?? 'Communication Lead') ?>
                                </td>
                                <td>
                                    <?php if ($doc['approval_status'] === 'Approved'): ?>
                                        <span class="badge" style="background:#d1fae5; color:#065f46; font-weight:700;">✓ Approved</span>
                                    <?php elseif ($doc['approval_status'] === 'Pending Review'): ?>
                                        <span class="badge" style="background:#fef3c7; color:#92400e; font-weight:700;">⏳ Pending Review</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#f1f5f9; color:#475569;"><?= e($doc['approval_status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;">
                                        <?= e($doc['documentation_status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= date('d M Y', strtotime($doc['created_at'])) ?>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; gap:6px;">
                                        <?php if (!empty($doc['file_path']) && file_exists(__DIR__ . '/' . $doc['file_path'])): ?>
                                            <a href="<?= BASE_URL . '/' . e($doc['file_path']) ?>" download class="btn btn-sm btn-light" style="font-size:11px; padding:4px 8px;">
                                                📥 Download
                                            </a>
                                        <?php endif; ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                                            <input type="hidden" name="approval_status" value="Approved">
                                            <input type="hidden" name="documentation_status" value="Completed">
                                            <?php if ($doc['approval_status'] !== 'Approved'): ?>
                                                <button type="submit" class="btn btn-sm btn-primary" style="background:#059669; border-color:#059669; font-size:11px; padding:4px 8px;">
                                                    ✓ Approve
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- ADD MEDIA DOC MODAL -->
<div id="addDocModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:12px; max-width:600px; width:100%; max-height:90vh; overflow-y:auto; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="margin:0; font-size:18px; color:#082d21;">New Media Document &amp; Task</h2>
            <button type="button" onclick="document.getElementById('addDocModal').style.display='none'" style="border:none; background:transparent; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Document / Media Title *</label>
                <input type="text" name="title" required placeholder="e.g. Chief Guest Convocation Address Speech" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Category *</label>
                    <select name="category" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                        <option value="Press Release">Press Release</option>
                        <option value="Photography">Photography</option>
                        <option value="Live Stream / Video">Live Stream / Video</option>
                        <option value="Social Media">Social Media</option>
                        <option value="Official Briefing">Official Briefing</option>
                        <option value="Documentation">Documentation</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Associated Event</label>
                    <select name="event_id" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                        <option value="">General University Document</option>
                        <?php foreach ($events as $ev): ?>
                            <option value="<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Media Task</label>
                    <input type="text" name="media_task" placeholder="e.g. Stage photo session coverage" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Responsible Staff</label>
                    <select name="responsible_staff_id" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                        <option value="">Select Staff...</option>
                        <?php foreach ($staffMembers as $sm): ?>
                            <option value="<?= (int)$sm['id'] ?>"><?= e($sm['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Attach Document / Media Asset (PDF, DOC, JPG, PNG, MP4, max 25MB)</label>
                <input type="file" name="doc_file" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box; font-size:12px;">
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Description / Press Notes</label>
                <textarea name="description" rows="2" placeholder="Summary of contents, distribution list, embargo guidelines..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-light" onclick="document.getElementById('addDocModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Document</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
