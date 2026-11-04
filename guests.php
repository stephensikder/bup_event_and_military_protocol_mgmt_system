<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/vendor/autoload.php';

require_login();

$pdo = db();
$page_title = 'Guests & VIP | BUP Protocol';

/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | ADD GUEST
    |--------------------------------------------------------------------------
    */
    if ($action === 'add') {

        $event_id = (int)($_POST['event_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $organization = trim($_POST['organization'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $category = $_POST['category'] ?? 'General';
        $priority_level = $_POST['priority_level'] ?? 'Normal';

        if ($event_id <= 0 || $name === '') {
            flash('error', 'Event and guest name are required.');
            redirect(BASE_URL . '/guests.php');
        }

        $assigned_lead_id = !empty($_POST['assigned_lead_id']) ? (int)$_POST['assigned_lead_id'] : null;
        $venue_notes = trim($_POST['venue_notes'] ?? '');

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO guests (
                    event_id,
                    name,
                    designation,
                    organization,
                    phone,
                    email,
                    category,
                    priority_level,
                    arrival_status,
                    assigned_lead_id,
                    venue_notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $event_id,
                $name,
                $designation,
                $organization,
                $phone,
                $email,
                $category,
                $priority_level,
                'Expected',
                $assigned_lead_id,
                $venue_notes
            ]);

            $guest_id = (int)$pdo->lastInsertId();

            $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

            do {
                $token = '';

                for ($i = 0; $i < 5; $i++) {
                    $token .= $characters[
                        random_int(0, strlen($characters) - 1)
                    ];
                }

                $check = $pdo->prepare("
                    SELECT id
                    FROM attendance
                    WHERE qr_token = ?
                    LIMIT 1
                ");

                $check->execute([$token]);
            } while ($check->fetch());

            $stmt = $pdo->prepare("
                INSERT INTO attendance (
                    event_id,
                    guest_id,
                    qr_token,
                    status
                )
                VALUES (?, ?, ?, 'Registered')
            ");

            $stmt->execute([
                $event_id,
                $guest_id,
                $token
            ]);

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (
                    user_id,
                    action,
                    description
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                current_user()['id'],
                'Guest Added',
                'Added guest #' . $guest_id . ' — ' . $name
            ]);

            $pdo->commit();

            // Send the attendance token by email.
            $emailStatus = '';

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    // Get event name for the email.
                    $stmt = $pdo->prepare("
                        SELECT name
                        FROM events
                        WHERE id = ?
                    ");
                    $stmt->execute([$event_id]);
                    $event = $stmt->fetch();

                    if (!$event) {
                        throw new RuntimeException('Event not found.');
                    }

                    // Reuse the existing SMTP configuration.
                    $mailConfig = require __DIR__ . '/config/mail.php';

                    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

                    $mail->isSMTP();
                    $mail->Host       = $mailConfig['host'];
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $mailConfig['username'];
                    $mail->Password   = $mailConfig['password'];
                    $mail->SMTPSecure =
                        \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = (int) $mailConfig['port'];
                    $mail->CharSet    = 'UTF-8';

                    $mail->setFrom(
                        $mailConfig['from_email'],
                        $mailConfig['from_name']
                    );

                    $mail->addAddress($email, $name);
                    $mail->isHTML(true);

                    $safeName = htmlspecialchars(
                        $name,
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    $safeEvent = htmlspecialchars(
                        $event['name'],
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    $mail->Subject = 'Your Attendance Token - ' . $event['name'];

                    $mail->Body = '
                        <div style="font-family:Arial,sans-serif;
                                    max-width:600px;margin:auto;
                                    border:1px solid #e2e8f0;
                                    border-radius:12px;overflow:hidden;">
                            <div style="background:#082d21;color:white;
                                        padding:24px;text-align:center;">
                                <h2 style="margin:0;">BUP Event &amp; Protocol</h2>
                                <p>Guest Attendance Information</p>
                            </div>

                            <div style="padding:28px;color:#243247;">
                                <p>Dear ' . $safeName . ',</p>

                                <p>
                                    You have been registered for the following event:
                                </p>

                                <h3>' . $safeEvent . '</h3>

                                <p>Your five-character attendance token is:</p>

                                <div style="background:#f1f5f9;
                                            padding:20px;text-align:center;
                                            border-radius:8px;">
                                    <span style="font-size:32px;font-weight:bold;
                                                 letter-spacing:7px;color:#082d21;">'
                                        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') .
                                    '</span>
                                </div>

                                <p>
                                    Please keep this token safe and present it to
                                    the event attendance desk when you arrive.
                                </p>

                                <p>
                                    Regards,<br>
                                    <strong>BUP Event &amp; Protocol Team</strong>
                                </p>
                            </div>
                        </div>
                    ';

                    $mail->AltBody =
                        "Dear {$name},\n\n" .
                        "You have been registered for: {$event['name']}.\n" .
                        "Your attendance token is: {$token}\n\n" .
                        "Please present this token at the attendance desk.\n\n" .
                        "BUP Event & Protocol Team";

                    $mail->send();

                    $emailStatus = 'Attendance token emailed to ' . $email . '.';

                    // Record successful email submission.
                    $stmt = $pdo->prepare("
                        INSERT INTO audit_logs (user_id, action, description)
                        VALUES (?, ?, ?)
                    ");

                    $stmt->execute([
                        current_user()['id'],
                        'Attendance Token Email Sent',
                        'Attendance token emailed to ' . $email .
                        ' for guest #' . $guest_id
                    ]);

                } catch (Throwable $mailError) {
                    error_log(
                        'Attendance token email error for guest #' .
                        $guest_id . ': ' . $mailError->getMessage()
                    );

                    $emailStatus =
                        'Guest added, but email failed. Please check the email address and SMTP configuration.';
                }
            } else {
                $emailStatus =
                    'Guest added, but no valid email address was provided.';
            }

            flash(
                'success',
                'Guest added successfully. Token: ' . $token . '. ' . $emailStatus
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Guest creation failed: ' . $e->getMessage());

            flash(
                'error',
                'Guest could not be added. Please try again.'
            );
        }

        redirect(BASE_URL . '/guests.php');
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE GUEST
    |--------------------------------------------------------------------------
    */
    if ($action === 'update') {

        $id = (int)($_POST['id'] ?? 0);

        $event_id = (int)($_POST['event_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $organization = trim($_POST['organization'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $category = $_POST['category'] ?? 'General';
        $priority_level = $_POST['priority_level'] ?? 'Normal';
        $arrival_status = $_POST['arrival_status'] ?? 'Expected';

        $assigned_lead_id = !empty($_POST['assigned_lead_id']) ? (int)$_POST['assigned_lead_id'] : null;
        $venue_notes = trim($_POST['venue_notes'] ?? '');

        if ($id <= 0 || $event_id <= 0 || $name === '') {
            flash('error', 'Invalid guest information.');
            redirect(BASE_URL . '/guests.php');
        }

        $stmt = $pdo->prepare("
            UPDATE guests
            SET
                event_id = ?,
                name = ?,
                designation = ?,
                organization = ?,
                phone = ?,
                email = ?,
                category = ?,
                priority_level = ?,
                arrival_status = ?,
                assigned_lead_id = ?,
                venue_notes = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $event_id,
            $name,
            $designation,
            $organization,
            $phone,
            $email,
            $category,
            $priority_level,
            $arrival_status,
            $assigned_lead_id,
            $venue_notes,
            $id
        ]);

        $pdo->prepare("
            INSERT INTO audit_logs(user_id, action, description)
            VALUES (?, ?, ?)
        ")->execute([
            current_user()['id'],
            'Guest Updated',
            'Updated guest #' . $id . ' — ' . $name
        ]);

        flash('success', 'Guest updated successfully.');
        redirect(BASE_URL . '/guests.php');
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE GUEST
    |--------------------------------------------------------------------------
    */
    if ($action === 'delete') {

        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {

            $stmt = $pdo->prepare("SELECT name FROM guests WHERE id = ?");
            $stmt->execute([$id]);
            $guest = $stmt->fetch();

            if ($guest) {

                $pdo->prepare("DELETE FROM guests WHERE id = ?")
                    ->execute([$id]);

                $pdo->prepare("
                    INSERT INTO audit_logs(user_id, action, description)
                    VALUES (?, ?, ?)
                ")->execute([
                    current_user()['id'],
                    'Guest Deleted',
                    'Deleted guest #' . $id . ' — ' . $guest['name']
                ]);

                flash('success', 'Guest deleted successfully.');
            }
        }

        redirect(BASE_URL . '/guests.php');
    }
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$q = trim($_GET['q'] ?? '');
$event_filter = (int)($_GET['event_id'] ?? 0);
$category_filter = $_GET['category'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$arrival_filter = $_GET['arrival'] ?? '';

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

$venue_leads = $pdo->query("
    SELECT id, name, designation, department, role
    FROM users
    WHERE (department IN ('Venue','Protocol','Logistics') OR role IN ('Department Lead','Event Coordinator','Admin') OR level IN ('L2','L3'))
      AND status = 'Active'
    ORDER BY name
")->fetchAll();


/*
|--------------------------------------------------------------------------
| EDIT GUEST
|--------------------------------------------------------------------------
*/

$edit_id = (int)($_GET['edit'] ?? 0);
$edit_guest = null;

if ($edit_id > 0) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM guests
        WHERE id = ?
    ");

    $stmt->execute([$edit_id]);
    $edit_guest = $stmt->fetch();

    if (!$edit_guest) {
        flash('error', 'Guest not found.');
        redirect(BASE_URL . '/guests.php');
    }
}


/*
|--------------------------------------------------------------------------
| GUEST QUERY
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        g.*,
        e.name AS event_name,
        e.mode AS event_mode,
        vl.name AS venue_lead_name,
        vl.department AS venue_lead_dept
    FROM guests g
    LEFT JOIN events e ON e.id = g.event_id
    LEFT JOIN users vl ON vl.id = g.assigned_lead_id
    WHERE 1
";

$params = [];


/* Search */

if ($q !== '') {

    $sql .= "
        AND (
            g.name LIKE ?
            OR g.designation LIKE ?
            OR g.organization LIKE ?
            OR g.phone LIKE ?
            OR g.email LIKE ?
        )
    ";

    $search = "%{$q}%";

    $params = [
        $search,
        $search,
        $search,
        $search,
        $search
    ];
}


/* Event */

if ($event_filter > 0) {

    $sql .= " AND g.event_id = ?";
    $params[] = $event_filter;
}


/* Category */

if ($category_filter !== '') {

    $sql .= " AND g.category = ?";
    $params[] = $category_filter;
}


/* Priority */

if ($priority_filter !== '') {

    $sql .= " AND g.priority_level = ?";
    $params[] = $priority_filter;
}


/* Arrival */

if ($arrival_filter !== '') {

    $sql .= " AND g.arrival_status = ?";
    $params[] = $arrival_filter;
}


$sql .= "
    ORDER BY
        CASE
            WHEN g.priority_level = 'VIP' THEN 1
            WHEN g.priority_level = 'High' THEN 2
            WHEN g.priority_level = 'Official' THEN 3
            ELSE 4
        END,
        g.created_at DESC
";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$guests = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| SUMMARY COUNTS
|--------------------------------------------------------------------------
*/

$total_guests = count($guests);

$vip_count = 0;
$expected_count = 0;
$arrived_count = 0;

foreach ($guests as $guest) {

    if ($guest['priority_level'] === 'VIP') {
        $vip_count++;
    }

    if ($guest['arrival_status'] === 'Expected') {
        $expected_count++;
    }

    if ($guest['arrival_status'] === 'Arrived') {
        $arrived_count++;
    }
}


require __DIR__ . '/includes/header.php';
?>

<div class="page-head">

    <div>
        <span class="eyebrow">GUEST & VIP MANAGEMENT</span>

        <h1>Guests & VIP</h1>

        <p>
            Manage event-wise guests, VIPs, officials, priority and arrival information.
        </p>
    </div>

    <a class="btn btn-primary" href="#guest-form">
        + Add Guest
    </a>

</div>


<!-- SUMMARY -->

<div class="stat-grid">

    <div class="stat-card">
        <span>Total Guests</span>
        <strong><?= $total_guests ?></strong>
        <em>Current filtered records</em>
    </div>

    <div class="stat-card">
        <span>VIP Guests</span>
        <strong><?= $vip_count ?></strong>
        <em>Highest priority guests</em>
    </div>

    <div class="stat-card">
        <span>Expected</span>
        <strong><?= $expected_count ?></strong>
        <em>Awaiting arrival</em>
    </div>

    <div class="stat-card">
        <span>Arrived</span>
        <strong><?= $arrived_count ?></strong>
        <em>Checked in guests</em>
    </div>

</div>


<!-- FILTER -->

<section class="panel">

    <div class="panel-head">

        <div>
            <h2>Guest Search & Filter</h2>
            <p>Find guests by name, event, category or arrival status.</p>
        </div>

    </div>


    <form method="get" class="filter-form">

        <input
            type="text"
            name="q"
            value="<?= e($q) ?>"
            placeholder="Search guest, organization, phone..."
        >


        <select name="event_id">

            <option value="">All Events</option>

            <?php foreach ($events as $ev): ?>

                <option
                    value="<?= $ev['id'] ?>"
                    <?= $event_filter == $ev['id'] ? 'selected' : '' ?>
                >
                    <?= e($ev['name']) ?>
                </option>

            <?php endforeach; ?>

        </select>


        <select name="category">

            <option value="">All Categories</option>

            <?php foreach (['VIP', 'Special Guest', 'Official', 'General'] as $category): ?>

                <option
                    value="<?= e($category) ?>"
                    <?= $category_filter === $category ? 'selected' : '' ?>
                >
                    <?= e($category) ?>
                </option>

            <?php endforeach; ?>

        </select>


        <select name="priority">

            <option value="">All Priority</option>

            <?php foreach (['VIP', 'High', 'Official', 'Normal'] as $priority): ?>

                <option
                    value="<?= e($priority) ?>"
                    <?= $priority_filter === $priority ? 'selected' : '' ?>
                >
                    <?= e($priority) ?>
                </option>

            <?php endforeach; ?>

        </select>


        <select name="arrival">

            <option value="">All Arrival Status</option>

            <?php foreach (['Expected', 'Arrived', 'Departed'] as $arrival): ?>

                <option
                    value="<?= e($arrival) ?>"
                    <?= $arrival_filter === $arrival ? 'selected' : '' ?>
                >
                    <?= e($arrival) ?>
                </option>

            <?php endforeach; ?>

        </select>


        <button class="btn btn-dark" type="submit">
            Filter
        </button>

        <a class="btn btn-light" href="<?= BASE_URL ?>/guests.php">
            Reset
        </a>

    </form>

</section>


<!-- GUEST REGISTER -->

<section class="panel">

    <div class="panel-head">

        <div>
            <h2>Guest Register</h2>
            <p><?= count($guests) ?> guest records found</p>
        </div>

    </div>


    <div class="table-wrap">

        <table>

            <thead>

                <tr>

                    <th>Guest</th>
                    <th>Event</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Arrival</th>
                    <th>Venue Lead / Protocol</th>
                    <th>Contact</th>
                    <th>Action</th>

                </tr>

            </thead>


            <tbody>

            <?php foreach ($guests as $g): ?>

                <tr>

                    <td>

                        <strong>
                            <?= e($g['name']) ?>
                        </strong>

                        <small>
                            <?= e($g['designation'] ?: 'Designation not provided') ?>

                            <?php if (!empty($g['organization'])): ?>
                                · <?= e($g['organization']) ?>
                            <?php endif; ?>
                        </small>

                    </td>


                    <td>
                        <?= e($g['event_name'] ?? 'Unassigned') ?>
                    </td>


                    <td>

                        <span class="badge neutral">
                            <?= e($g['category']) ?>
                        </span>

                    </td>


                    <td>

                        <?php if ($g['priority_level'] === 'VIP'): ?>

                            <span class="priority">
                                VIP
                            </span>

                        <?php elseif ($g['priority_level'] === 'High'): ?>

                            <span class="priority">
                                High
                            </span>

                        <?php else: ?>

                            <?= e($g['priority_level']) ?>

                        <?php endif; ?>

                    </td>


                    <td>

                        <?php
                        $arrival_class = strtolower($g['arrival_status']);
                        ?>

                        <span class="badge <?= e($arrival_class) ?>">
                            <?= e($g['arrival_status']) ?>
                        </span>

                    </td>

                    <td>
                        <?php if (!empty($g['venue_lead_name'])): ?>
                            <strong style="color:#0f766e;"><?= e($g['venue_lead_name']) ?></strong>
                            <br><small style="color:#64748b;"><?= e($g['venue_lead_dept'] ?: 'Venue Lead') ?></small>
                        <?php else: ?>
                            <span style="color:#94a3b8; font-size:11px;">Unassigned</span>
                        <?php endif; ?>
                        <?php if (!empty($g['venue_notes'])): ?>
                            <br><small style="color:#b45309; font-weight:600;">📋 <?= e($g['venue_notes']) ?></small>
                        <?php endif; ?>
                    </td>


                    <td>

                        <?php if (!empty($g['phone'])): ?>

                            <small>
                                <?= e($g['phone']) ?>
                            </small>

                        <?php endif; ?>

                        <?php if (!empty($g['email'])): ?>

                            <small>
                                <?= e($g['email']) ?>
                            </small>

                        <?php endif; ?>

                        <?php if (empty($g['phone']) && empty($g['email'])): ?>

                            <small>—</small>

                        <?php endif; ?>

                    </td>


                    <td>

                        <a
                            class="table-link"
                            href="<?= BASE_URL ?>/guests.php?edit=<?= $g['id'] ?>#guest-form"
                        >
                            Edit
                        </a>


                        <form
                            class="inline-form"
                            method="post"
                            style="display:inline;"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="delete"
                            >

                            <input
                                type="hidden"
                                name="id"
                                value="<?= $g['id'] ?>"
                            >

                            <button
                                type="submit"
                                class="link-danger"
                                onclick="return confirm('Delete this guest?')"
                            >
                                Delete
                            </button>

                        </form>

                    </td>

                </tr>

            <?php endforeach; ?>


            <?php if (!$guests): ?>

                <tr>

                    <td
                        colspan="7"
                        class="empty"
                    >
                        No guest records found.
                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>


<!-- ADD / EDIT FORM -->

<section
    id="guest-form"
    class="panel form-panel"
>

    <div class="panel-head">

        <div>

            <h2>
                <?= $edit_guest ? 'Edit Guest' : 'Add Guest' ?>
            </h2>

            <p>
                <?= $edit_guest
                    ? 'Update guest information and arrival status.'
                    : 'Register a guest, VIP or official for an event.'
                ?>
            </p>

        </div>

        <?php if ($edit_guest): ?>

            <a
                class="btn btn-light"
                href="<?= BASE_URL ?>/guests.php"
            >
                Cancel Edit
            </a>

        <?php endif; ?>

    </div>


    <form method="post" class="form-grid">

        <input
            type="hidden"
            name="action"
            value="<?= $edit_guest ? 'update' : 'add' ?>"
        >


        <?php if ($edit_guest): ?>

            <input
                type="hidden"
                name="id"
                value="<?= $edit_guest['id'] ?>"
            >

        <?php endif; ?>


        <label>

            Event

            <select name="event_id" required>

                <option value="">
                    Select event
                </option>

                <?php foreach ($events as $ev): ?>

                    <option
                        value="<?= $ev['id'] ?>"
                        <?= (
                            ($edit_guest && $edit_guest['event_id'] == $ev['id'])
                            ? 'selected'
                            : (
                                !$edit_guest && $event_filter == $ev['id']
                                ? 'selected'
                                : ''
                            )
                        ) ?>
                    >
                        <?= e($ev['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </label>


        <label>

            Full Name

            <input
                type="text"
                name="name"
                value="<?= e($edit_guest['name'] ?? '') ?>"
                required
            >

        </label>


        <label>

            Designation

            <input
                type="text"
                name="designation"
                value="<?= e($edit_guest['designation'] ?? '') ?>"
            >

        </label>


        <label>

            Organization

            <input
                type="text"
                name="organization"
                value="<?= e($edit_guest['organization'] ?? '') ?>"
            >

        </label>


        <label>

            Phone

            <input
                type="text"
                name="phone"
                value="<?= e($edit_guest['phone'] ?? '') ?>"
            >

        </label>


        <label>

            Email

            <input
                type="email"
                name="email"
                value="<?= e($edit_guest['email'] ?? '') ?>"
            >

        </label>


        <label>

            Category

            <select name="category">

                <?php foreach (['VIP', 'Special Guest', 'Official', 'General'] as $category): ?>

                    <option
                        value="<?= e($category) ?>"
                        <?= (($edit_guest['category'] ?? '') === $category) ? 'selected' : '' ?>
                    >
                        <?= e($category) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </label>


        <label>

            Priority

            <select name="priority_level">

                <?php foreach (['VIP', 'High', 'Official', 'Normal'] as $priority): ?>

                    <option
                        value="<?= e($priority) ?>"
                        <?= (($edit_guest['priority_level'] ?? '') === $priority) ? 'selected' : '' ?>
                    >
                        <?= e($priority) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </label>


        <?php if ($edit_guest): ?>

            <label>

                Arrival Status

                <select name="arrival_status">

                    <?php foreach (['Expected', 'Arrived', 'Departed'] as $arrival): ?>

                        <option
                            value="<?= e($arrival) ?>"
                            <?= (($edit_guest['arrival_status'] ?? '') === $arrival) ? 'selected' : '' ?>
                        >
                            <?= e($arrival) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </label>
        <?php endif; ?>

        <label>
            Assign Venue / Protocol Lead
            <select name="assigned_lead_id">
                <option value="">-- Unassigned (General Protocol Desk) --</option>
                <?php foreach ($venue_leads as $vl): ?>
                    <option value="<?= (int)$vl['id'] ?>" <?= ((int)($edit_guest['assigned_lead_id'] ?? 0) === (int)$vl['id']) ? 'selected' : '' ?>>
                        <?= e($vl['name']) ?> (<?= e($vl['department'] ?: $vl['role']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color:#94a3b8; font-size:11px;">Venue Lead receives assigned guest to coordinate venue requirements.</small>
        </label>

        <label class="span-2">
            Venue / Protocol Requirements &amp; Notes
            <textarea
                name="venue_notes"
                rows="2"
                placeholder="e.g. Front row escort, floral bouquet, dais reception requirements, VIP car parking slot..."
            ><?= e($edit_guest['venue_notes'] ?? '') ?></textarea>
        </label>

        <div class="span-2">

            <button
                class="btn btn-primary"
                type="submit"
            >
                <?= $edit_guest ? 'Update Guest' : 'Add Guest' ?>
            </button>

            <?php if ($edit_guest): ?>

                <a
                    class="btn btn-light"
                    href="<?= BASE_URL ?>/guests.php"
                >
                    Cancel
                </a>

            <?php endif; ?>

        </div>

    </form>

</section>

<style>
/* ==========================================
   GUESTS PAGE — CARD SPACING & LAYOUT
   CSS stays inside guests.php
========================================== */

/* Space between page heading and summary */
.page-head {
    margin-bottom: 28px;
}

/* Summary cards */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 28px;
}

/* Separate filter, register and form panels */
.main-content > .panel,
.main-content > section.panel {
    margin-bottom: 26px;
}

/* Card shape and clipping */
.main-content .panel {
    min-width: 0;
    border-radius: 20px;
    overflow: hidden;
}

/* Panel header spacing */
.main-content .panel-head {
    padding: 24px 28px;
    margin: 0;
    border-bottom: 1px solid #e5eaf1;
}

/* Filter form layout */
.main-content .filter-form {
    display: grid;
    grid-template-columns: minmax(180px, 1.2fr) repeat(4, minmax(130px, 1fr)) auto auto;
    gap: 12px;
    align-items: center;
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

/* Keep filter buttons separated */
.main-content .filter-form .btn {
    min-height: 58px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    white-space: nowrap;
}

/* Guest register table */
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

.main-content .table-wrap td strong,
.main-content .table-wrap td small {
    display: block;
}

.main-content .table-wrap td small {
    margin-top: 5px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

/* Edit and delete actions */
.main-content .table-wrap td:last-child {
    white-space: nowrap;
}

.main-content .table-wrap td:last-child .inline-form {
    display: inline-flex !important;
    margin-left: 8px;
    vertical-align: middle;
}

/* Add / Edit Guest form */
#guest-form {
    margin-top: 26px;
    padding: 0;
}

#guest-form .panel-head {
    margin-bottom: 0;
}

#guest-form > .form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px 24px;
    padding: 26px 28px 30px;
}

#guest-form .form-grid label {
    display: flex;
    flex-direction: column;
    gap: 9px;
    min-width: 0;
    font-weight: 600;
}

#guest-form .form-grid input,
#guest-form .form-grid select {
    box-sizing: border-box;
    width: 100%;
    min-width: 0;
    min-height: 52px;
    margin: 0;
}

#guest-form .form-grid .span-2 {
    grid-column: 1 / -1;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

/* Tablet */
@media (max-width: 1200px) {
    .main-content .filter-form {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .stat-grid {
        gap: 16px;
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
        margin-bottom: 20px;
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

    #guest-form > .form-grid {
        grid-template-columns: 1fr;
        gap: 18px;
        padding: 20px;
    }

    #guest-form .form-grid .span-2 {
        grid-column: 1;
    }

    .main-content .table-wrap table {
        min-width: 900px;
    }
}

@media (max-width: 420px) {
    .stat-grid {
        gap: 10px;
    }

    .main-content .panel-head {
        padding: 16px;
    }

    #guest-form > .form-grid {
        padding: 16px;
    }
}
</style>



<?php require __DIR__ . '/includes/footer.php'; ?>