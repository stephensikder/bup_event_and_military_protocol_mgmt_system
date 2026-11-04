<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_once __DIR__ . '/vendor/autoload.php';

require_login();

$page_title = 'Guests & VIP | BUP Protocol';

/*
|--------------------------------------------------------------------------
| POST ACTIONS (MongoDB Atlas)
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
            $guest_id = mongo_next_id('guests');
            $now = date('Y-m-d H:i:s');

            $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            do {
                $token = '';
                for ($i = 0; $i < 5; $i++) {
                    $token .= $characters[random_int(0, strlen($characters) - 1)];
                }
                $check = mcoll('attendance')->findOne(['qr_token' => $token]);
            } while ($check !== null);

            mcoll('guests')->insertOne([
                'id' => $guest_id,
                'event_id' => $event_id,
                'name' => $name,
                'designation' => $designation,
                'organization' => $organization,
                'phone' => $phone,
                'email' => $email,
                'category' => $category,
                'priority_level' => $priority_level,
                'arrival_status' => 'Expected',
                'assigned_lead_id' => $assigned_lead_id,
                'venue_notes' => $venue_notes,
                'created_at' => $now,
                'updated_at' => $now
            ]);

            $attendance_id = mongo_next_id('attendance');
            mcoll('attendance')->insertOne([
                'id' => $attendance_id,
                'event_id' => $event_id,
                'guest_id' => $guest_id,
                'qr_token' => $token,
                'status' => 'Registered',
                'check_in_time' => null,
                'check_out_time' => null,
                'notes' => null,
                'created_at' => $now
            ]);

            mongo_audit_log(
                'Guest Added',
                'Added guest #' . $guest_id . ' — ' . $name
            );

            // Send the attendance token by email.
            $emailStatus = '';

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $eventDoc = mcoll('events')->findOne(['id' => $event_id]);
                    if (!$eventDoc) {
                        throw new RuntimeException('Event not found.');
                    }
                    $event = mongo_doc_to_array($eventDoc);

                    // Reuse the existing SMTP configuration (Environment or local mail.php)
                    $mailConfig = get_mail_config();

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
                        $event['name'] ?? 'Event',
                        ENT_QUOTES,
                        'UTF-8'
                    );

                    $mail->Subject = 'Your Attendance Token - ' . ($event['name'] ?? 'Event');

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
                        "You have been registered for: " . ($event['name'] ?? 'Event') . ".\n" .
                        "Your attendance token is: {$token}\n\n" .
                        "Please present this token at the attendance desk.\n\n" .
                        "BUP Event & Protocol Team";

                    $mail->send();

                    $emailStatus = 'Attendance token emailed to ' . $email . '.';

                    mongo_audit_log(
                        'Attendance Token Email Sent',
                        'Attendance token emailed to ' . $email . ' for guest #' . $guest_id
                    );

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

        $now = date('Y-m-d H:i:s');
        mcoll('guests')->updateOne(
            ['id' => $id],
            ['$set' => [
                'event_id' => $event_id,
                'name' => $name,
                'designation' => $designation,
                'organization' => $organization,
                'phone' => $phone,
                'email' => $email,
                'category' => $category,
                'priority_level' => $priority_level,
                'arrival_status' => $arrival_status,
                'assigned_lead_id' => $assigned_lead_id,
                'venue_notes' => $venue_notes,
                'updated_at' => $now
            ]]
        );

        mongo_audit_log(
            'Guest Updated',
            'Updated guest #' . $id . ' — ' . $name
        );

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
            $guestDoc = mcoll('guests')->findOne(['id' => $id]);
            if ($guestDoc) {
                $guest = mongo_doc_to_array($guestDoc);
                mcoll('guests')->deleteOne(['id' => $id]);
                mcoll('attendance')->deleteMany(['guest_id' => $id]);
                mcoll('seating_assignments')->deleteMany(['guest_id' => $id]);

                mongo_audit_log(
                    'Guest Deleted',
                    'Deleted guest #' . $id . ' — ' . ($guest['name'] ?? '')
                );

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
| EVENTS & VENUE LEADS (MongoDB Atlas)
|--------------------------------------------------------------------------
*/

$eventsCursor = mcoll('events')->find([], ['sort' => ['event_date' => -1, 'event_time' => -1]]);
$events = [];
foreach ($eventsCursor as $eDoc) {
    $events[] = mongo_doc_to_array($eDoc);
}

$leadFilter = [
    'status' => 'Active',
    '$or' => [
        ['department' => ['$in' => ['Venue', 'Protocol', 'Logistics']]],
        ['role' => ['$in' => ['Department Lead', 'Event Coordinator', 'Admin']]],
        ['level' => ['$in' => ['L2', 'L3']]]
    ]
];
$leadCursor = mcoll('users')->find($leadFilter, ['sort' => ['name' => 1]]);
$venue_leads = [];
foreach ($leadCursor as $uDoc) {
    $venue_leads[] = mongo_doc_to_array($uDoc);
}


/*
|--------------------------------------------------------------------------
| EDIT GUEST
|--------------------------------------------------------------------------
*/

$edit_id = (int)($_GET['edit'] ?? 0);
$edit_guest = null;

if ($edit_id > 0) {
    $editDoc = mcoll('guests')->findOne(['id' => $edit_id]);
    if ($editDoc) {
        $edit_guest = mongo_doc_to_array($editDoc);
    } else {
        flash('error', 'Guest not found.');
        redirect(BASE_URL . '/guests.php');
    }
}


/*
|--------------------------------------------------------------------------
| GUEST QUERY (MongoDB Atlas Aggregation Pipeline)
|--------------------------------------------------------------------------
*/

$match = [];
if ($q !== '') {
    $regex = new MongoDB\BSON\Regex($q, 'i');
    $match['$or'] = [
        ['name' => $regex],
        ['designation' => $regex],
        ['organization' => $regex],
        ['phone' => $regex],
        ['email' => $regex]
    ];
}

if ($event_filter > 0) {
    $match['event_id'] = $event_filter;
}

if ($category_filter !== '') {
    $match['category'] = $category_filter;
}

if ($priority_filter !== '') {
    $match['priority_level'] = $priority_filter;
}

if ($arrival_filter !== '') {
    $match['arrival_status'] = $arrival_filter;
}

$pipeline = [];
if (!empty($match)) {
    $pipeline[] = ['$match' => $match];
}

$pipeline[] = [
    '$lookup' => [
        'from' => 'events',
        'localField' => 'event_id',
        'foreignField' => 'id',
        'as' => 'event_docs'
    ]
];
$pipeline[] = [
    '$lookup' => [
        'from' => 'users',
        'localField' => 'assigned_lead_id',
        'foreignField' => 'id',
        'as' => 'lead_docs'
    ]
];

$pipeline[] = [
    '$addFields' => [
        'event_name' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.name', 0]], '']],
        'event_mode' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.mode', 0]], 'Offline']],
        'venue_lead_name' => ['$ifNull' => [['$arrayElemAt' => ['$lead_docs.name', 0]], null]],
        'venue_lead_dept' => ['$ifNull' => [['$arrayElemAt' => ['$lead_docs.department', 0]], null]],
        'priority_order' => [
            '$switch' => [
                'branches' => [
                    ['case' => ['$eq' => ['$priority_level', 'VIP']], 'then' => 1],
                    ['case' => ['$eq' => ['$priority_level', 'High']], 'then' => 2],
                    ['case' => ['$eq' => ['$priority_level', 'Official']], 'then' => 3],
                ],
                'default' => 4
            ]
        ]
    ]
];

$pipeline[] = [
    '$project' => [
        'event_docs' => 0,
        'lead_docs' => 0
    ]
];

$pipeline[] = [
    '$sort' => [
        'priority_order' => 1,
        'created_at' => -1,
        'id' => -1
    ]
];

$guestCursor = mcoll('guests')->aggregate($pipeline);
$guests = [];
foreach ($guestCursor as $gDoc) {
    $guests[] = mongo_doc_to_array($gDoc);
}


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

    if (($guest['priority_level'] ?? '') === 'VIP') {
        $vip_count++;
    }

    if (($guest['arrival_status'] ?? '') === 'Expected') {
        $expected_count++;
    }

    if (($guest['arrival_status'] ?? '') === 'Arrived') {
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