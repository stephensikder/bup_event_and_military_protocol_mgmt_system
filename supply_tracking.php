<?php
require_once __DIR__ . '/config/auth.php';
require_login();

// Access control: Logistics Lead, Coordinator, Director, Admin
if (!is_logistics_lead() && !is_event_coordinator() && !is_event_director() && !has_role('Admin')) {
    flash('error', 'Access restricted: Supply Tracking is managed by Logistics and Event Command.');
    redirect(BASE_URL . '/dashboard.php');
}

$pdo = db();
$page_title = 'Supply & Inventory Tracking | BUP Protocol';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADD NEW SUPPLY ITEM
    if ($action === 'add') {
        $item_name = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? 'General');
        $qty_required = max(1, (int)($_POST['qty_required'] ?? 1));
        $qty_available = max(0, (int)($_POST['qty_available'] ?? 0));
        $qty_issued = max(0, (int)($_POST['qty_issued'] ?? 0));
        $unit = trim($_POST['unit'] ?? 'Units');
        $supplier = trim($_POST['supplier'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $responsible_person = trim($_POST['responsible_person'] ?? current_user()['name']);
        $req_date = !empty($_POST['req_date']) ? $_POST['req_date'] : null;
        $delivery_date = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
        $delivery_status = $_POST['delivery_status'] ?? 'Pending';
        $stock_status = $_POST['stock_status'] ?? 'Adequate';
        $notes = trim($_POST['notes'] ?? '');

        if ($item_name === '') {
            flash('error', 'Supply item name is required.');
            redirect(BASE_URL . '/supply_tracking.php');
        }

        // Auto determine stock status if not explicitly overridden
        $remaining = $qty_available - $qty_issued;
        if ($remaining <= 0) {
            $stock_status = 'Out of Stock';
        } elseif ($remaining < ($qty_required * 0.25)) {
            $stock_status = 'Critical';
        } elseif ($remaining < ($qty_required * 0.5)) {
            $stock_status = 'Low Stock';
        }

        $stmt = $pdo->prepare("
            INSERT INTO supplies 
            (item_name, category, qty_required, qty_available, qty_issued, unit, supplier, location, responsible_person, req_date, delivery_date, delivery_status, stock_status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $item_name, $category, $qty_required, $qty_available, $qty_issued, $unit,
            $supplier, $location, $responsible_person, $req_date, $delivery_date,
            $delivery_status, $stock_status, $notes
        ]);

        $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'Supply Added', ?)")
            ->execute([current_user()['id'], 'Added supply item "' . $item_name . '" (Qty: ' . $qty_available . ' ' . $unit . ')']);

        flash('success', 'Supply item <strong>' . e($item_name) . '</strong> added successfully.');
        redirect(BASE_URL . '/supply_tracking.php');
    }

    // 2. ISSUE STOCK / UPDATE QUANTITY
    if ($action === 'issue_stock') {
        $id = (int)($_POST['id'] ?? 0);
        $issue_qty = max(1, (int)($_POST['issue_qty'] ?? 1));
        $recipient = trim($_POST['recipient'] ?? 'Ground Staff');

        $stmt = $pdo->prepare("SELECT * FROM supplies WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $item = $stmt->fetch();

        if ($item) {
            $currentAvailable = (int)$item['qty_available'];
            $currentIssued = (int)$item['qty_issued'];
            $remaining = $currentAvailable - $currentIssued;

            if ($issue_qty > $remaining) {
                flash('error', 'Cannot issue ' . $issue_qty . ' units. Only ' . $remaining . ' ' . $item['unit'] . ' remaining.');
            } else {
                $newIssued = $currentIssued + $issue_qty;
                $newRemaining = $currentAvailable - $newIssued;
                $newStockStatus = $newRemaining <= 0 ? 'Out of Stock' : ($newRemaining < ($item['qty_required'] * 0.3) ? 'Low Stock' : 'Adequate');

                $pdo->prepare("
                    UPDATE supplies 
                    SET qty_issued = ?, stock_status = ?, notes = CONCAT(IFNULL(notes,''), '\n[Issued: ', ?, ' ', unit, ' to ', ?, ' on ', NOW(), ']')
                    WHERE id = ?
                ")->execute([$newIssued, $newStockStatus, $issue_qty, $recipient, $id]);

                $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'Stock Issued', ?)")
                    ->execute([current_user()['id'], 'Issued ' . $issue_qty . ' ' . $item['unit'] . ' of "' . $item['item_name'] . '" to ' . $recipient]);

                flash('success', 'Successfully issued ' . $issue_qty . ' ' . e($item['unit']) . ' to ' . e($recipient) . '.');
            }
        }
        redirect(BASE_URL . '/supply_tracking.php');
    }

    // 3. UPDATE DELIVERY / STOCK RECORD
    if ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $delivery_status = $_POST['delivery_status'] ?? 'Delivered';
        $stock_status = $_POST['stock_status'] ?? 'Adequate';
        $add_qty = max(0, (int)($_POST['add_qty'] ?? 0));

        if ($add_qty > 0) {
            $pdo->prepare("
                UPDATE supplies 
                SET qty_available = qty_available + ?, delivery_status = ?, stock_status = ?
                WHERE id = ?
            ")->execute([$add_qty, $delivery_status, $stock_status, $id]);
        } else {
            $pdo->prepare("
                UPDATE supplies 
                SET delivery_status = ?, stock_status = ?
                WHERE id = ?
            ")->execute([$delivery_status, $stock_status, $id]);
        }

        flash('success', 'Supply record updated successfully.');
        redirect(BASE_URL . '/supply_tracking.php');
    }
}

// Search and filters
$q = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "SELECT * FROM supplies WHERE 1=1";
$params = [];

if ($q !== '') {
    $sql .= " AND (item_name LIKE ? OR supplier LIKE ? OR responsible_person LIKE ? OR location LIKE ?)";
    $like = '%' . $q . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($categoryFilter !== '') {
    $sql .= " AND category = ?";
    $params[] = $categoryFilter;
}
if ($statusFilter !== '') {
    $sql .= " AND (stock_status = ? OR delivery_status = ?)";
    $params[] = $statusFilter;
    $params[] = $statusFilter;
}

$sql .= " ORDER BY id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$supplies = $stmt->fetchAll();

// Statistics
$totalItems = (int)$pdo->query("SELECT COUNT(*) FROM supplies")->fetchColumn();
$lowStockCount = (int)$pdo->query("SELECT COUNT(*) FROM supplies WHERE stock_status IN ('Low Stock', 'Critical', 'Out of Stock')")->fetchColumn();
$deliveredCount = (int)$pdo->query("SELECT COUNT(*) FROM supplies WHERE delivery_status = 'Delivered'")->fetchColumn();
$categories = $pdo->query("SELECT DISTINCT category FROM supplies ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

require __DIR__ . '/includes/header.php';
?>

        <!-- HEADER -->
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:24px;">
            <div>
                <span class="eyebrow" style="color:#d97706; font-weight:800;">LOGISTICS &amp; PROCUREMENT CELL</span>
                <h1 style="margin:2px 0 0; font-size:24px; color:#082d21;">Supply &amp; Inventory Tracking</h1>
                <p style="margin:4px 0 0; color:#64748b; font-size:13px;">Manage real inventory, issue stock, monitor shortages, and coordinate delivery statuses.</p>
            </div>
            <div>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('addSupplyModal').style.display='flex'">
                    + Add Supply Item
                </button>
            </div>
        </div>

        <!-- STAT METRICS -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #2563eb;">
                <span class="eyebrow" style="color:#2563eb;">TOTAL CATALOG ITEMS</span>
                <div style="font-size:26px; font-weight:800; color:#082d21; margin-top:4px;"><?= $totalItems ?></div>
                <small style="color:#64748b;">Registered inventory lines</small>
            </div>
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid <?= $lowStockCount > 0 ? '#ef4444' : '#10b981' ?>;">
                <span class="eyebrow" style="color:<?= $lowStockCount > 0 ? '#ef4444' : '#10b981' ?>;">STOCK ALERTS / SHORTAGE</span>
                <div style="font-size:26px; font-weight:800; color:<?= $lowStockCount > 0 ? '#dc2626' : '#082d21' ?>; margin-top:4px;"><?= $lowStockCount ?></div>
                <small style="color:#64748b;"><?= $lowStockCount > 0 ? 'Low stock or critical shortage' : 'All stocks adequate' ?></small>
            </div>
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #10b981;">
                <span class="eyebrow" style="color:#10b981;">DELIVERY FULFILLMENT</span>
                <div style="font-size:26px; font-weight:800; color:#082d21; margin-top:4px;"><?= $deliveredCount ?></div>
                <small style="color:#64748b;">Fully delivered to warehouse</small>
            </div>
            <div class="panel" style="padding:16px; margin-bottom:0; border-left:4px solid #d97706;">
                <span class="eyebrow" style="color:#d97706;">EQUIPMENT CATEGORIES</span>
                <div style="font-size:26px; font-weight:800; color:#082d21; margin-top:4px;"><?= count($categories) ?></div>
                <small style="color:#64748b;">Active supply domains</small>
            </div>
        </div>

        <!-- FILTER & SEARCH BAR -->
        <section class="panel" style="padding:16px; margin-bottom:20px;">
            <form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search item, supplier, custodian, location..." style="flex:1; min-width:240px; padding:9px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
                <select name="category" style="padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c) ?>" <?= $categoryFilter === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" style="padding:9px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
                    <option value="">All Statuses</option>
                    <option value="Adequate" <?= $statusFilter === 'Adequate' ? 'selected' : '' ?>>Adequate Stock</option>
                    <option value="Low Stock" <?= $statusFilter === 'Low Stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="Out of Stock" <?= $statusFilter === 'Out of Stock' ? 'selected' : '' ?>>Out of Stock</option>
                    <option value="Delivered" <?= $statusFilter === 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending Delivery</option>
                </select>
                <button type="submit" class="btn btn-dark" style="padding:9px 16px;">Filter</button>
                <?php if ($q !== '' || $categoryFilter !== '' || $statusFilter !== ''): ?>
                    <a href="<?= BASE_URL ?>/supply_tracking.php" class="btn btn-light" style="padding:9px 14px;">Reset</a>
                <?php endif; ?>
            </form>
        </section>

        <!-- SUPPLY INVENTORY TABLE -->
        <section class="panel">
            <div class="panel-head">
                <div>
                    <span class="eyebrow">PERSISTED INVENTORY RECORDS</span>
                    <h2>Active Supply Records (<?= count($supplies) ?>)</h2>
                </div>
            </div>
            <div class="table-wrap">
                <table class="user-table" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Item Name &amp; Category</th>
                            <th>Required</th>
                            <th>Available</th>
                            <th>Issued</th>
                            <th>Remaining</th>
                            <th>Location &amp; Custodian</th>
                            <th>Delivery Status</th>
                            <th>Stock Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($supplies)): ?>
                            <tr><td colspan="9" style="text-align:center; padding:30px; color:#64748b;">No supply records found matching your filter criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($supplies as $item): ?>
                                <?php
                                $rem = (int)$item['qty_available'] - (int)$item['qty_issued'];
                                $isLow = ($item['stock_status'] === 'Low Stock' || $item['stock_status'] === 'Critical' || $item['stock_status'] === 'Out of Stock' || $rem < 5);
                                ?>
                                <tr style="<?= $isLow ? 'background:#fffbeb;' : '' ?>">
                                    <td>
                                        <strong style="color:#082d21; font-size:13px;"><?= e($item['item_name']) ?></strong>
                                        <span class="table-subtext" style="color:#64748b; font-size:11px;">
                                            Category: <strong style="color:#2563eb;"><?= e($item['category']) ?></strong> &bull; Unit: <?= e($item['unit']) ?>
                                        </span>
                                        <?php if (!empty($item['supplier'])): ?>
                                            <span class="table-subtext" style="color:#94a3b8; font-size:11px;">Supplier: <?= e($item['supplier']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= (int)$item['qty_required'] ?></strong> <?= e($item['unit']) ?></td>
                                    <td><span style="color:#059669; font-weight:700;"><?= (int)$item['qty_available'] ?></span></td>
                                    <td><span style="color:#d97706; font-weight:700;"><?= (int)$item['qty_issued'] ?></span></td>
                                    <td>
                                        <strong style="font-size:14px; color:<?= $rem <= 0 ? '#dc2626' : ($rem < 5 ? '#d97706' : '#059669') ?>;">
                                            <?= $rem ?>
                                        </strong> <?= e($item['unit']) ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:600; color:#334155;">📍 <?= e($item['location'] ?: 'Central Store') ?></div>
                                        <small style="color:#64748b;">Custodian: <?= e($item['responsible_person'] ?: 'Logistics Cell') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700; font-size:11px;">
                                            <?= e($item['delivery_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($item['stock_status'] === 'Adequate'): ?>
                                            <span class="badge" style="background:#d1fae5; color:#065f46; font-weight:700; font-size:11px;">✓ Adequate</span>
                                        <?php elseif ($item['stock_status'] === 'Low Stock'): ?>
                                            <span class="badge" style="background:#fef3c7; color:#92400e; font-weight:700; font-size:11px;">⚠️ Low Stock</span>
                                        <?php elseif ($item['stock_status'] === 'Critical'): ?>
                                            <span class="badge" style="background:#fee2e2; color:#991b1b; font-weight:700; font-size:11px;">🚨 Critical</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#f1f5f9; color:#475569; font-weight:700; font-size:11px;"><?= e($item['stock_status']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <div style="display:inline-flex; gap:6px;">
                                            <button type="button" class="btn btn-sm btn-light" style="font-size:11px; padding:4px 8px; font-weight:700;" onclick="openIssueModal(<?= (int)$item['id'] ?>, '<?= e(addslashes($item['item_name'])) ?>', <?= $rem ?>, '<?= e($item['unit']) ?>')">
                                                📦 Issue
                                            </button>
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

<!-- ADD SUPPLY MODAL -->
<div id="addSupplyModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:12px; max-width:600px; width:100%; max-height:90vh; overflow-y:auto; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h2 style="margin:0; font-size:18px; color:#082d21;">Add New Supply Item</h2>
            <button type="button" onclick="document.getElementById('addSupplyModal').style.display='none'" style="border:none; background:transparent; font-size:20px; cursor:pointer;">&times;</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Supply Item Name *</label>
                <input type="text" name="item_name" required placeholder="e.g. Stage Sound Monitors" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Category *</label>
                    <select name="category" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                        <option value="General">General</option>
                        <option value="Equipment">Equipment / Resources</option>
                        <option value="Communication Equipment">Communication Equipment</option>
                        <option value="Security Gear">Security Gear</option>
                        <option value="Venue Decoration">Venue Decoration</option>
                        <option value="AV / Sound">AV / Sound</option>
                        <option value="Transport / Protocol">Transport / Protocol</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Unit of Measurement *</label>
                    <select name="unit" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                        <option value="Pieces">Pieces</option>
                        <option value="Sets" selected>Sets</option>
                        <option value="Boxes">Boxes</option>
                        <option value="Rolls">Rolls</option>
                        <option value="Packs">Packs</option>
                        <option value="Bottles">Bottles</option>
                        <option value="Badges">Badges</option>
                        <option value="Bundles">Bundles</option>
                        <option value="Meters">Meters</option>
                        <option value="Units">Units</option>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Qty Required</label>
                    <input type="number" name="qty_required" value="10" min="1" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Qty Available</label>
                    <input type="number" name="qty_available" value="10" min="0" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Qty Issued</label>
                    <input type="number" name="qty_issued" value="0" min="0" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Supplier / Source</label>
                    <select name="supplier" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                        <option value="BUP Central Store" selected>BUP Central Store</option>
                        <option value="Directorate of Procurement & Works">Directorate of Procurement &amp; Works</option>
                        <option value="Armed Forces Institute of Pathology (AFIP)">Armed Forces Institute of Pathology (AFIP)</option>
                        <option value="Military Operations Supply Branch">Military Operations Supply Branch</option>
                        <option value="Sena Kalyan Sangstha Logistics">Sena Kalyan Sangstha Logistics</option>
                        <option value="Dhaka Cantonment Logistics Depot">Dhaka Cantonment Logistics Depot</option>
                        <option value="Official University Press">Official University Press</option>
                        <option value="Station Headquarters Mirpur">Station Headquarters Mirpur</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Storage Location</label>
                    <select name="location" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                        <option value="Central Store Room 102" selected>Central Store Room 102</option>
                        <option value="Auditorium Logistics Bay">Auditorium Logistics Bay</option>
                        <option value="VIP Protocol Holding Area">VIP Protocol Holding Area</option>
                        <option value="Campus Medical Center Depot">Campus Medical Center Depot</option>
                        <option value="Security Armory Post A">Security Armory Post A</option>
                        <option value="Transport Fleet Garage Depot">Transport Fleet Garage Depot</option>
                        <option value="Multipurpose Shed Logistics Area">Multipurpose Shed Logistics Area</option>
                    </select>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Custodian / Lead</label>
                    <input type="text" name="responsible_person" value="<?= e(current_user()['name']) ?>" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                </div>
                <div>
                    <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Delivery Status</label>
                    <select name="delivery_status" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
                        <option value="Delivered">Delivered</option>
                        <option value="Pending">Pending</option>
                        <option value="Shipped">Shipped</option>
                        <option value="Delayed">Delayed</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Notes / Specification</label>
                <textarea name="notes" rows="2" placeholder="Inspection remarks, serial numbers, condition..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;"></textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-light" onclick="document.getElementById('addSupplyModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Supply Item</button>
            </div>
        </form>
    </div>
</div>

<!-- ISSUE STOCK MODAL -->
<div id="issueModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; border-radius:12px; max-width:440px; width:100%; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h3 id="issueTitle" style="margin:0 0 12px; font-size:16px; color:#082d21;">Issue Supply Stock</h3>
        <p id="issueSubtitle" style="margin:0 0 16px; font-size:13px; color:#64748b;"></p>
        <form method="post">
            <input type="hidden" name="action" value="issue_stock">
            <input type="hidden" name="id" id="issueItemId" value="0">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Quantity to Issue</label>
                <input type="number" name="issue_qty" id="issueQty" value="1" min="1" required style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
            </div>
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:4px;">Issuing Recipient / Ground Unit</label>
                <input type="text" name="recipient" required placeholder="e.g. Sergeant T. Paul (Logistics Team A)" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-light" onclick="document.getElementById('issueModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary">Confirm Issue</button>
            </div>
        </form>
    </div>
</div>

<script>
function openIssueModal(id, name, remaining, unit) {
    document.getElementById('issueItemId').value = id;
    document.getElementById('issueTitle').textContent = 'Issue Stock: ' + name;
    document.getElementById('issueSubtitle').textContent = 'Available to issue: ' + remaining + ' ' + unit;
    document.getElementById('issueQty').max = remaining;
    document.getElementById('issueQty').value = Math.min(1, remaining);
    document.getElementById('issueModal').style.display = 'flex';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

