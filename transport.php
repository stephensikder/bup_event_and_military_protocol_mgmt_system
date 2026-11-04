<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
require_login();

$pdo = db();
$page_title = 'Transport & Fleet Logistics | BUP Protocol';

// Permissions check
if (is_student() || is_staff()) {
    flash('error', 'You do not have permission to manage transport logistics.');
    redirect(BASE_URL . '/dashboard.php');
}

// =====================================================
// HANDLE ACTIONS (CREATE / EDIT / DELETE)
// =====================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'edit') {
        $event_id = (int)($_POST['event_id'] ?? 0);
        $guest_name = trim($_POST['guest_name'] ?? '');
        $vehicle_no = trim($_POST['vehicle_no'] ?? '');
        $driver_name = trim($_POST['driver_name'] ?? '');
        $parking_slot = trim($_POST['parking_slot'] ?? '');
        $arrival_expected = !empty($_POST['arrival_expected']) ? $_POST['arrival_expected'] : null;
        $arrival_actual = !empty($_POST['arrival_actual']) ? $_POST['arrival_actual'] : null;
        $accommodation = trim($_POST['accommodation'] ?? '');

        if ($event_id <= 0 || $guest_name === '') {
            flash('error', 'Event selection and Guest Name are required.');
            redirect(BASE_URL . '/transport.php');
        }

        try {
            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO transport 
                    (event_id, guest_name, vehicle_no, driver_name, parking_slot, arrival_expected, arrival_actual, accommodation)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $event_id, $guest_name, $vehicle_no, $driver_name, $parking_slot, $arrival_expected, $arrival_actual, $accommodation
                ]);
                $newId = (int)$pdo->lastInsertId();

                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'Transport Created', ?)");
                $audit->execute([current_user()['id'], "Created transport entry #{$newId} for guest: {$guest_name} (Vehicle: {$vehicle_no})"]);

                flash('success', 'Transport dispatch record created successfully.');
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("
                    UPDATE transport 
                    SET event_id = ?, guest_name = ?, vehicle_no = ?, driver_name = ?, parking_slot = ?, arrival_expected = ?, arrival_actual = ?, accommodation = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $event_id, $guest_name, $vehicle_no, $driver_name, $parking_slot, $arrival_expected, $arrival_actual, $accommodation, $id
                ]);

                $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'Transport Updated', ?)");
                $audit->execute([current_user()['id'], "Updated transport entry #{$id} for guest: {$guest_name}"]);

                flash('success', 'Transport dispatch record updated successfully.');
            }

            mongo_sync_table('transport');
            redirect(BASE_URL . '/transport.php');
        } catch (Exception $e) {
            flash('error', 'Database operation failed: ' . $e->getMessage());
            redirect(BASE_URL . '/transport.php');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM transport WHERE id = ?");
            $stmt->execute([$id]);

            $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, action, description) VALUES (?, 'Transport Deleted', ?)");
            $audit->execute([current_user()['id'], "Deleted transport entry #{$id}"]);

            mongo_sync_table('transport');
            flash('success', 'Transport entry deleted.');
        }
        redirect(BASE_URL . '/transport.php');
    }

    if ($action === 'mark_arrived') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE transport SET arrival_actual = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            mongo_sync_table('transport');
            flash('success', 'Guest arrival confirmed and logged.');
        }
        redirect(BASE_URL . '/transport.php');
    }
}

// Fetch events for selection
$events = $pdo->query("SELECT id, name, event_date FROM events ORDER BY event_date DESC")->fetchAll();

// Fetch registered guests for VIP dropdown
$guests_list = $pdo->query("SELECT id, name, organization, designation, priority_level FROM guests ORDER BY name ASC")->fetchAll();

// Designated BUP fleet vehicles
$bup_vehicles = [
    'BUP-V-101 (Toyota Coaster VIP Bus)',
    'BUP-V-102 (Toyota HiAce Microbus)',
    'BUP-V-103 (Toyota Prado TX - Command SUV)',
    'BUP-V-104 (Toyota Corolla Altis - Protocol Sedan)',
    'BUP-V-105 (Toyota Camry Hybrid - VIP Protocol)',
    'BUP-V-106 (Mitsubishi Pajero Sport - Security Escort)',
    'BUP-V-107 (Nissan Civilian 30-Seater Shuttle)',
    'BUP-V-108 (Isuzu D-Max Protocol Support Truck)'
];

// Assigned official drivers
$bup_drivers = [
    'Subedar Kabir Hossain (01711-234501)',
    'Havildar M. Rafiqul Islam (01819-345602)',
    'Naik Abdur Rahim (01912-456703)',
    'Lance Naik Jahangir Alam (01611-567804)',
    'Md. Tariqul Islam (01722-678905)',
    'Md. Sohel Rana (01833-789006)'
];

// Reserved parking bays and plots
$parking_bays = [
    'VIP Bay Alpha (VVIP Dais / Main Auditorium)',
    'VIP Bay Bravo (Auditorium North Portico)',
    'Parking Slot C-1 (Faculty of Arts & Social Sciences)',
    'Parking Slot C-2 (Science & Technology Complex)',
    'Holding Area Delta (Central Parade Grounds)',
    'Security Escort Bay E (Main Entrance Gate 1)',
    'Admin Portico Bay F (Vice Chancellor Complex)'
];

// Designated accommodations
$accommodation_options = [
    'BUP Officers Mess — VIP Suite 01',
    'BUP Officers Mess — VIP Suite 02',
    'Mirpur DOHS BUP Guest House — Executive Suite',
    'Mirpur DOHS BUP Guest House — Deluxe Room 102',
    'Commandant Protocol Suite — Main Campus',
    'Auditorium Green Room VIP Lounge',
    'Not Required (Day Visit / Non-Resident)'
];

// Filters
$q = trim($_GET['q'] ?? '');
$event_filter = (int)($_GET['event_id'] ?? 0);

$query = "
    SELECT t.*, e.name AS event_name, e.event_date 
    FROM transport t
    LEFT JOIN events e ON t.event_id = e.id
    WHERE 1=1
";
$params = [];

if ($q !== '') {
    $query .= " AND (t.guest_name LIKE ? OR t.vehicle_no LIKE ? OR t.driver_name LIKE ? OR t.parking_slot LIKE ?)";
    $like = "%{$q}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($event_filter > 0) {
    $query .= " AND t.event_id = ?";
    $params[] = $event_filter;
}

$query .= " ORDER BY t.arrival_expected ASC, t.id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transports = $stmt->fetchAll();

// Edit record if requested
$edit_entry = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmtEdit = $pdo->prepare("SELECT * FROM transport WHERE id = ? LIMIT 1");
    $stmtEdit->execute([$edit_id]);
    $edit_entry = $stmtEdit->fetch();
}

// Summary Metrics
$total_count = count($transports);
$arrived_count = 0;
$pending_count = 0;
foreach ($transports as $t) {
    if (!empty($t['arrival_actual'])) {
        $arrived_count++;
    } else {
        $pending_count++;
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <span class="eyebrow" style="color:#d4af37; font-weight:800; letter-spacing:2px;">LOGISTICS &amp; FLEET MANAGEMENT</span>
        <h1 style="color:#082d21; margin:4px 0 6px;">Transport &amp; Vehicle Allocation</h1>
        <p style="color:#5e776a; font-size:14px; margin:0;">
            Track VIP convoys, official university transports, parking bay reservations, and live delegation arrivals.
        </p>
    </div>
    <div style="display:flex; gap:10px;">
        <a class="btn btn-primary" href="#transport-form" style="background:linear-gradient(135deg, #082d21 0%, #13533e 100%); border:1px solid #d4af37; color:#fff; font-weight:800;">
            + Allocate Transport
        </a>
    </div>
</div>

<!-- METRIC CARDS -->
<div class="stat-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:24px;">
    <div class="stat-card" style="background:#ffffff; border-radius:14px; border:1px solid #dce6e1; border-left:4px solid #082d21; padding:20px;">
        <span style="font-size:11px; font-weight:800; color:#5e776a; text-transform:uppercase;">Total Allocations</span>
        <strong style="font-size:28px; color:#082d21; display:block; margin:6px 0;"><?= $total_count ?></strong>
        <em style="color:#8da498; font-size:11px; font-style:normal;">Active fleet deployments</em>
    </div>
    <div class="stat-card" style="background:#ffffff; border-radius:14px; border:1px solid #dce6e1; border-left:4px solid #16a34a; padding:20px;">
        <span style="font-size:11px; font-weight:800; color:#16a34a; text-transform:uppercase;">Confirmed Arrived</span>
        <strong style="font-size:28px; color:#16a34a; display:block; margin:6px 0;"><?= $arrived_count ?></strong>
        <em style="color:#8da498; font-size:11px; font-style:normal;">On-campus at parking bays</em>
    </div>
    <div class="stat-card" style="background:#ffffff; border-radius:14px; border:1px solid #dce6e1; border-left:4px solid #d97706; padding:20px;">
        <span style="font-size:11px; font-weight:800; color:#d97706; text-transform:uppercase;">Pending In-Transit</span>
        <strong style="font-size:28px; color:#d97706; display:block; margin:6px 0;"><?= $pending_count ?></strong>
        <em style="color:#8da498; font-size:11px; font-style:normal;">Expected arrivals today</em>
    </div>
</div>

<!-- FILTER BAR -->
<section class="panel" style="margin-bottom:24px; padding:18px 24px; border-radius:14px; background:#fff; border:1px solid #dce6e1;">
    <form method="get" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
        <div style="flex:1; min-width:240px;">
            <input 
                type="text" 
                name="q" 
                value="<?= e($q) ?>" 
                placeholder="Search by Guest, Vehicle No, Driver, or Slot..." 
                class="form-control"
                style="height:44px; margin:0;"
            >
        </div>
        <div style="min-width:220px;">
            <select name="event_id" class="form-control" style="height:44px; margin:0;">
                <option value="0">All Events</option>
                <?php foreach ($events as $ev): ?>
                    <option value="<?= $ev['id'] ?>" <?= $event_filter === (int)$ev['id'] ? 'selected' : '' ?>>
                        <?= e($ev['name']) ?> (<?= e(date('d M', strtotime($ev['event_date']))) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="background:#082d21; border-color:#082d21; height:44px; padding:0 22px;">
            Filter
        </button>
        <?php if ($q !== '' || $event_filter > 0): ?>
            <a href="<?= BASE_URL ?>/transport.php" class="btn btn-light" style="height:44px; line-height:44px; padding:0 16px;">
                Reset
            </a>
        <?php endif; ?>
    </form>
</section>

<!-- TRANSPORT REGISTER TABLE -->
<section class="panel" style="border-radius:16px; border:1px solid #dce6e1; background:#ffffff; overflow:hidden; margin-bottom:30px;">
    <div class="panel-head" style="padding:18px 24px; border-bottom:1px solid #dce6e1; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h2 style="margin:0; font-size:17px; color:#082d21;">Transport Fleet &amp; Vehicle Register</h2>
            <p style="margin:3px 0 0; color:#5e776a; font-size:12px;"><?= count($transports) ?> fleet assignments recorded</p>
        </div>
    </div>

    <div class="table-wrap">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <thead>
                <tr style="background:#f4f8f6; border-bottom:1px solid #dce6e1; text-align:left;">
                    <th style="padding:12px 18px; color:#082d21; font-weight:800;">Guest / Delegate</th>
                    <th style="padding:12px 18px; color:#082d21; font-weight:800;">Vehicle &amp; Driver</th>
                    <th style="padding:12px 18px; color:#082d21; font-weight:800;">Parking Bay</th>
                    <th style="padding:12px 18px; color:#082d21; font-weight:800;">Expected &amp; Actual Arrival</th>
                    <th style="padding:12px 18px; color:#082d21; font-weight:800;">Accommodation</th>
                    <th style="padding:12px 18px; color:#082d21; font-weight:800; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transports)): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:35px; color:#8da498;">
                            No transport allocation records found. Use the form below to allocate official transport.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transports as $tr): ?>
                        <tr style="border-bottom:1px solid #edf2ef;">
                            <td style="padding:14px 18px;">
                                <strong style="color:#082d21; font-size:14px;"><?= e($tr['guest_name']) ?></strong>
                                <small style="display:block; color:#5e776a; font-size:11px; margin-top:2px;">
                                    Event: <?= e($tr['event_name'] ?? 'General Protocol') ?>
                                </small>
                            </td>
                            <td style="padding:14px 18px;">
                                <div style="display:inline-flex; align-items:center; gap:6px; background:#eaf8f2; border:1px solid #b6e4cc; padding:3px 8px; border-radius:6px; font-weight:800; color:#082d21; font-size:11px; font-family:monospace;">
                                    <?= e($tr['vehicle_no'] ?: 'VEHICLE TBD') ?>
                                </div>
                                <small style="display:block; color:#5e776a; font-size:11px; margin-top:3px;">
                                    Driver: <strong><?= e($tr['driver_name'] ?: 'Not Assigned') ?></strong>
                                </small>
                            </td>
                            <td style="padding:14px 18px;">
                                <?php if (!empty($tr['parking_slot'])): ?>
                                    <span style="background:#fef3c7; color:#92400e; font-weight:800; padding:4px 8px; border-radius:6px; font-size:11px;">
                                        Slot: <?= e($tr['parking_slot']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#94a3b8; font-size:11px;">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:14px 18px;">
                                <div style="font-size:12px; color:#334155;">
                                    Expected: <strong><?= !empty($tr['arrival_expected']) ? date('d M, h:i A', strtotime($tr['arrival_expected'])) : '—' ?></strong>
                                </div>
                                <div style="font-size:11px; margin-top:3px;">
                                    <?php if (!empty($tr['arrival_actual'])): ?>
                                        <span style="color:#16a34a; font-weight:700;">
                                            ✓ Arrived: <?= date('d M, h:i A', strtotime($tr['arrival_actual'])) ?>
                                        </span>
                                    <?php else: ?>
                                        <form method="post" style="display:inline; margin:0;">
                                            <input type="hidden" name="action" value="mark_arrived">
                                            <input type="hidden" name="id" value="<?= $tr['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-light" style="padding:2px 8px; font-size:10px; background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe;">
                                                Confirm Arrival
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="padding:14px 18px; color:#5e776a; font-size:12px;">
                                <?= e($tr['accommodation'] ?: 'N/A') ?>
                            </td>
                            <td style="padding:14px 18px; text-align:right;">
                                <div style="display:inline-flex; gap:8px;">
                                    <a href="<?= BASE_URL ?>/transport.php?edit=<?= $tr['id'] ?>#transport-form" class="table-link" style="color:#082d21; font-weight:700;">
                                        Edit
                                    </a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this vehicle allocation record?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $tr['id'] ?>">
                                        <button type="submit" class="link-danger" style="background:none; border:none; color:#dc2626; cursor:pointer; font-weight:700;">
                                            Delete
                                        </button>
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

<!-- ALLOCATE / EDIT TRANSPORT FORM -->
<section id="transport-form" class="panel form-panel" style="border-radius:16px; border:1px solid #dce6e1; background:#ffffff; padding:24px 28px;">
    <div style="border-bottom:1px solid #dce6e1; padding-bottom:16px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h2 style="margin:0; font-size:18px; color:#082d21;">
                <?= $edit_entry ? 'Edit Transport Allocation' : 'Create Vehicle &amp; Driver Allocation' ?>
            </h2>
            <p style="margin:4px 0 0; color:#5e776a; font-size:12px;">
                Assign official vehicles, chauffeurs, and reserved parking slots for institutional delegates.
            </p>
        </div>
        <?php if ($edit_entry): ?>
            <a href="<?= BASE_URL ?>/transport.php" class="btn btn-light btn-sm">Cancel Edit</a>
        <?php endif; ?>
    </div>

    <form method="post">
        <input type="hidden" name="action" value="<?= $edit_entry ? 'edit' : 'create' ?>">
        <?php if ($edit_entry): ?>
            <input type="hidden" name="id" value="<?= $edit_entry['id'] ?>">
        <?php endif; ?>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
            <label>
                <span style="font-weight:700; color:#082d21; font-size:12px;">Target Event *</span>
                <select name="event_id" required class="form-control" style="margin-top:6px;">
                    <option value="">Select Event</option>
                    <?php foreach ($events as $ev): ?>
                        <option value="<?= $ev['id'] ?>" <?= (($edit_entry && (int)$edit_entry['event_id'] === (int)$ev['id']) || (!$edit_entry && $event_filter === (int)$ev['id'])) ? 'selected' : '' ?>>
                            <?= e($ev['name']) ?> (<?= e(date('d M Y', strtotime($ev['event_date']))) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span style="font-weight:700; color:#082d21; font-size:12px;">Guest / VIP / Delegation Name *</span>
                <select name="guest_name" required class="form-control" style="margin-top:6px;">
                    <option value="">Select Registered Guest / VIP</option>
                    <?php if ($edit_entry && !empty($edit_entry['guest_name'])): ?>
                        <option value="<?= e($edit_entry['guest_name']) ?>" selected><?= e($edit_entry['guest_name']) ?> (Current)</option>
                    <?php endif; ?>
                    <?php foreach ($guests_list as $g): ?>
                        <?php $guestLabel = $g['name'] . ($g['designation'] ? ' — ' . $g['designation'] : '') . ($g['organization'] ? ' (' . $g['organization'] . ')' : ''); ?>
                        <option value="<?= e($guestLabel) ?>" <?= (($edit_entry['guest_name'] ?? '') === $guestLabel) ? 'selected' : '' ?>>
                            <?= e($guestLabel) ?> [<?= e($g['priority_level'] ?: 'VIP') ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-top:14px;">
            <label>
                <span style="font-weight:700; color:#082d21; font-size:12px;">Vehicle Number / Registration</span>
                <select name="vehicle_no" required class="form-control" style="margin-top:6px;">
                    <option value="">Select BUP Fleet Vehicle</option>
                    <?php if ($edit_entry && !empty($edit_entry['vehicle_no']) && !in_array($edit_entry['vehicle_no'], $bup_vehicles, true)): ?>
                        <option value="<?= e($edit_entry['vehicle_no']) ?>" selected><?= e($edit_entry['vehicle_no']) ?> (Current)</option>
                    <?php endif; ?>
                    <?php foreach ($bup_vehicles as $veh): ?>
                        <option value="<?= e($veh) ?>" <?= (($edit_entry['vehicle_no'] ?? '') === $veh) ? 'selected' : '' ?>>
                            <?= e($veh) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span style="font-weight:700; color:#082d21; font-size:12px;">Assigned Driver Name &amp; Contact</span>
                <select name="driver_name" required class="form-control" style="margin-top:6px;">
                    <option value="">Select Official Driver</option>
                    <?php if ($edit_entry && !empty($edit_entry['driver_name']) && !in_array($edit_entry['driver_name'], $bup_drivers, true)): ?>
                        <option value="<?= e($edit_entry['driver_name']) ?>" selected><?= e($edit_entry['driver_name']) ?> (Current)</option>
                    <?php endif; ?>
                    <?php foreach ($bup_drivers as $drv): ?>
                        <option value="<?= e($drv) ?>" <?= (($edit_entry['driver_name'] ?? '') === $drv) ? 'selected' : '' ?>>
                            <?= e($drv) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span style="font-weight:700; color:#082d21; font-size:12px;">Reserved Parking Bay / Slot</span>
                <select name="parking_slot" required class="form-control" style="margin-top:6px;">
                    <option value="">Select Designated Parking Bay</option>
                    <?php if ($edit_entry && !empty($edit_entry['parking_slot']) && !in_array($edit_entry['parking_slot'], $parking_bays, true)): ?>
                        <option value="<?= e($edit_entry['parking_slot']) ?>" selected><?= e($edit_entry['parking_slot']) ?> (Current)</option>
                    <?php endif; ?>
                    <?php foreach ($parking_bays as $bay): ?>
                        <option value="<?= e($bay) ?>" <?= (($edit_entry['parking_slot'] ?? '') === $bay) ? 'selected' : '' ?>>
                            <?= e($bay) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:14px;">
            <label>
                <span style="font-weight:700; color:#082d21; font-size:12px;">Expected Arrival Date &amp; Time</span>
                <input 
                    type="datetime-local" 
                    name="arrival_expected" 
                    value="<?= !empty($edit_entry['arrival_expected']) ? date('Y-m-d\TH:i', strtotime($edit_entry['arrival_expected'])) : '' ?>" 
                    class="form-control" 
                    style="margin-top:6px;"
                >
            </label>

            <label>
                <span style="font-weight:700; color:#082d21; font-size:12px;">Actual Arrival Date &amp; Time</span>
                <input 
                    type="datetime-local" 
                    name="arrival_actual" 
                    value="<?= !empty($edit_entry['arrival_actual']) ? date('Y-m-d\TH:i', strtotime($edit_entry['arrival_actual'])) : '' ?>" 
                    class="form-control" 
                    style="margin-top:6px;"
                >
            </label>
        </div>

        <div style="margin-top:14px;">
            <label>
                <span style="font-weight:700; color:#082d21; font-size:12px;">Accommodation / Transit Quarters</span>
                <select name="accommodation" required class="form-control" style="margin-top:6px;">
                    <option value="">Select Accommodation / Suite</option>
                    <?php if ($edit_entry && !empty($edit_entry['accommodation']) && !in_array($edit_entry['accommodation'], $accommodation_options, true)): ?>
                        <option value="<?= e($edit_entry['accommodation']) ?>" selected><?= e($edit_entry['accommodation']) ?> (Current)</option>
                    <?php endif; ?>
                    <?php foreach ($accommodation_options as $acc): ?>
                        <option value="<?= e($acc) ?>" <?= (($edit_entry['accommodation'] ?? '') === $acc) ? 'selected' : '' ?>>
                            <?= e($acc) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <div style="margin-top:20px; display:flex; justify-content:flex-end;">
            <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg, #082d21 0%, #13533e 100%); border:1px solid #d4af37; padding:12px 28px; font-size:14px; font-weight:800; color:#fff;">
                <?= $edit_entry ? 'Save Transport Changes' : 'Confirm &amp; Dispatch Transport' ?>
            </button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>

