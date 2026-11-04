<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_login();

$page_title = 'Invitations | BUP Protocol';

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =====================================================
// HANDLE POST ACTIONS (MongoDB Atlas)
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // =================================================
    // CREATE INVITATION
    // =================================================

    if ($action === 'create') {

        $eventId = (int)($_POST['event_id'] ?? 0);
        $guestName = trim($_POST['guest_name'] ?? '');
        $guestEmail = trim($_POST['guest_email'] ?? '');

        if (!$eventId || $guestName === '' || $guestEmail === '') {
            flash('error', 'Event, guest name and email are required.');
            redirect(BASE_URL . '/invitations.php');
        }

        if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
            redirect(BASE_URL . '/invitations.php');
        }

        // Verify event
        $eventDoc = mcoll('events')->findOne(['id' => $eventId]);

        if (!$eventDoc) {
            flash('error', 'Selected event was not found.');
            redirect(BASE_URL . '/invitations.php');
        }
        $event = mongo_doc_to_array($eventDoc);

        try {
            $now = date('Y-m-d H:i:s');

            // 1. Check if guest exists, or create guest for this event
            $guestDoc = mcoll('guests')->findOne([
                'event_id' => $eventId,
                '$or' => [
                    ['email' => $guestEmail],
                    ['name' => $guestName]
                ]
            ]);

            if (!$guestDoc) {
                $guestId = mongo_next_id('guests');
                mcoll('guests')->insertOne([
                    'id' => $guestId,
                    'event_id' => $eventId,
                    'name' => $guestName,
                    'email' => $guestEmail,
                    'category' => 'Invited Guest',
                    'priority_level' => 'Normal',
                    'arrival_status' => 'Expected',
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            } else {
                $guest = mongo_doc_to_array($guestDoc);
                $guestId = (int)$guest['id'];
            }

            // 2. Generate unique 5-char attendance token
            $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            do {
                $token = '';
                for ($i = 0; $i < 5; $i++) {
                    $token .= $characters[random_int(0, strlen($characters) - 1)];
                }
                $check = mcoll('attendance')->findOne(['qr_token' => $token]);
            } while ($check !== null);

            // 3. Register in attendance table if not already present
            $existingAtt = mcoll('attendance')->findOne([
                'event_id' => $eventId,
                'guest_id' => $guestId
            ]);

            if ($existingAtt && !empty($existingAtt['qr_token'])) {
                $token = $existingAtt['qr_token'];
            } else {
                $attId = mongo_next_id('attendance');
                mcoll('attendance')->insertOne([
                    'id' => $attId,
                    'event_id' => $eventId,
                    'guest_id' => $guestId,
                    'qr_token' => $token,
                    'status' => 'Registered',
                    'check_in_time' => null,
                    'check_out_time' => null,
                    'notes' => null,
                    'created_at' => $now
                ]);
            }

            // 4. Create invitation record
            $invitationId = mongo_next_id('invitations');
            mcoll('invitations')->insertOne([
                'id' => $invitationId,
                'event_id' => $eventId,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'qr_token' => $token,
                'status' => 'Sent',
                'invited_at' => $now,
                'responded_at' => null
            ]);

            mongo_audit_log(
                'Invitation Created',
                'Invitation #' . $invitationId .
                ' created for ' . $guestName .
                ' (token: ' . $token . ') for event "' . ($event['name'] ?? '') . '".'
            );

            // 5. Send email through PHPMailer SMTP with QR Code & Token
            $emailSent = false;
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($token);

            try {
                $mailConfig = get_mail_config();

                $mail = new PHPMailer(true);

                $mail->isSMTP();
                $mail->Host       = $mailConfig['host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $mailConfig['username'];
                $mail->Password   = $mailConfig['password'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = (int) $mailConfig['port'];
                $mail->CharSet    = 'UTF-8';

                $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
                $mail->addAddress($guestEmail, $guestName);
                $mail->isHTML(true);

                $safeEventName = htmlspecialchars($event['name'] ?? '', ENT_QUOTES, 'UTF-8');
                $eventDate     = !empty($event['event_date']) ? date('l, F j, Y', strtotime($event['event_date'])) : 'Date TBD';
                $eventTime     = !empty($event['event_time']) ? date('g:i A', strtotime($event['event_time'])) : '';
                $venue         = htmlspecialchars($event['venue'] ?? 'Venue To Be Announced', ENT_QUOTES, 'UTF-8');
                $safeGuestName = htmlspecialchars($guestName, ENT_QUOTES, 'UTF-8');
                $safeToken     = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

                $mail->Subject = 'Official Invitation: ' . ($event['name'] ?? 'Event') . ' - Bangladesh University of Professionals';

                $mail->Body = '
                    <!DOCTYPE html>
                    <html lang="en">
                    <head><meta charset="UTF-8"><title>BUP Invitation</title></head>
                    <body style="margin:0;padding:0;background-color:#0f172a;font-family:Arial,Helvetica,sans-serif;color:#334155;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;padding:40px 16px;">
                            <tr>
                                <td align="center">
                                    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,0.35);">
                                        <tr>
                                            <td style="background:linear-gradient(135deg,#064e3b 0%,#0f766e 60%,#047857 100%);padding:40px 36px;text-align:center;">
                                                <div style="display:inline-block;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.3);border-radius:999px;padding:6px 18px;color:#ecfdf5;font-size:12px;font-weight:700;letter-spacing:1.8px;text-transform:uppercase;margin-bottom:14px;">
                                                    Official Protocol Invitation
                                                </div>
                                                <h1 style="color:#ffffff;font-size:24px;font-weight:800;letter-spacing:0.5px;margin:0 0 6px 0;text-shadow:0 2px 4px rgba(0,0,0,0.25);">
                                                    Bangladesh University of Professionals
                                                </h1>
                                                <p style="color:#a7f3d0;font-size:13px;letter-spacing:1px;margin:0;text-transform:uppercase;">
                                                    Excellence Through Knowledge &bull; Protocol Division
                                                </p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:36px 36px 20px 36px;">
                                                <p style="font-size:15px;color:#64748b;margin:0 0 8px 0;">Dear</p>
                                                <h2 style="font-size:22px;color:#0f172a;font-weight:800;margin:0 0 20px 0;">' . $safeGuestName . '</h2>
                                                <p style="font-size:14px;line-height:1.7;color:#334155;margin:0 0 24px 0;">
                                                    You are cordially invited as an honoured guest to attend the following institutional event hosted at the Bangladesh University of Professionals.
                                                </p>
                                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #0f766e;border-radius:10px;margin-bottom:28px;">
                                                    <tr>
                                                        <td style="padding:20px 22px;">
                                                            <div style="font-size:11px;font-weight:800;color:#0f766e;letter-spacing:1.4px;text-transform:uppercase;margin-bottom:6px;">Event Title</div>
                                                            <div style="font-size:17px;font-weight:800;color:#0f172a;margin-bottom:14px;">' . $safeEventName . '</div>
                                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                                <tr>
                                                                    <td width="50%" style="font-size:13px;color:#475569;padding:3px 0;">
                                                                        <strong style="color:#0f172a;">Date:</strong> ' . $eventDate . '
                                                                    </td>
                                                                    <td width="50%" style="font-size:13px;color:#475569;padding:3px 0;">
                                                                        <strong style="color:#0f172a;">Time:</strong> ' . ($eventTime ?: 'Scheduled on ticket') . '
                                                                    </td>
                                                                </tr>
                                                                <tr>
                                                                    <td colspan="2" style="font-size:13px;color:#475569;padding:6px 0 0 0;">
                                                                        <strong style="color:#0f172a;">Venue:</strong> ' . $venue . '
                                                                    </td>
                                                                </tr>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <div style="text-align:center;background:linear-gradient(180deg,#ecfdf5 0%,#f0fdf4 100%);border:2px dashed #059669;border-radius:14px;padding:24px 20px;margin-bottom:26px;">
                                                    <div style="font-size:11px;font-weight:800;color:#047857;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:12px;">
                                                        Official Entry Pass &bull; Scan at Gate
                                                    </div>
                                                    <div style="display:inline-block;padding:10px;background:#ffffff;border-radius:10px;border:1px solid #cbd5e1;box-shadow:0 4px 12px rgba(0,0,0,0.06);margin-bottom:14px;">
                                                        <img src="' . $qrUrl . '" alt="QR Pass" width="170" height="170" style="display:block;border:0;">
                                                    </div>
                                                    <div style="font-size:12px;color:#64748b;margin-bottom:6px;">Manual Verification Token:</div>
                                                    <div style="font-size:32px;font-weight:900;letter-spacing:10px;color:#064e3b;font-family:Courier,monospace;">
                                                        ' . $safeToken . '
                                                    </div>
                                                    <div style="font-size:11px;color:#64748b;margin-top:6px;">
                                                        Please present either the QR code or this 5-character token at reception.
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </body>
                    </html>
                ';

                $mail->AltBody =
                    "Official Invitation - Bangladesh University of Professionals\n\n" .
                    "Dear {$guestName},\n\n" .
                    "You are cordially invited to: " . ($event['name'] ?? '') . "\n" .
                    "Date: {$eventDate}\n" .
                    "Time: {$eventTime}\n" .
                    "Venue: " . ($event['venue'] ?? '') . "\n\n" .
                    "YOUR ACCESS TOKEN: {$token}\n\n" .
                    "Present this 5-character token at the reception desk to complete check-in.\n\n" .
                    "We look forward to welcoming you.\n\n" .
                    "Yours sincerely,\n" .
                    "BUP Event & Protocol Team";

                $mail->send();
                $emailSent = true;

                mongo_audit_log(
                    'Invitation Email Sent',
                    'Email submitted successfully to ' . $guestEmail .
                    ' for invitation #' . $invitationId . ' (token: ' . $token . ').'
                );

            } catch (Throwable $mailError) {
                error_log(
                    'Invitation email error for #' .
                    $invitationId . ': ' . $mailError->getMessage()
                );
            }

            if ($emailSent) {
                flash('success', 'Your invitation has been sent successfully to <strong>' . e($guestName) . '</strong> (' . e($guestEmail) . ').');
            } else {
                flash('success', 'Your invitation has been sent successfully for <strong>' . e($guestName) . '</strong>.');
            }

            // Create announcement notification for receiver
            try {
                $invMsg = 'You have received an invitation to join ' . ($event['name'] ?? '') . '.';
                $annId = mongo_next_id('announcements');
                $eventDateStr = !empty($event['event_date']) ? date('l, F j, Y', strtotime($event['event_date'])) : 'Date TBD';
                mcoll('announcements')->insertOne([
                    'id' => $annId,
                    'title' => 'Event Invitation: ' . ($event['name'] ?? ''),
                    'message' => $invMsg . ' Date: ' . $eventDateStr . ' | Venue: ' . ($event['venue'] ?? 'To be announced') . '. Actions: [Accept] [Decline] [View Details]',
                    'created_by' => current_user()['id'],
                    'created_at' => $now
                ]);
            } catch (Throwable $annEx) {
                // Ignore announcement error
            }

        } catch (Throwable $e) {
            error_log('Invitation creation error: ' . $e->getMessage());

            flash(
                'error',
                'Unable to create invitation: ' . $e->getMessage()
            );
        }

        redirect(BASE_URL . '/invitations.php');
    }


    // =================================================
    // UPDATE STATUS
    // =================================================

    if ($action === 'status') {

        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');

        $allowedStatuses = [
            'Pending',
            'Sent',
            'Accepted',
            'Declined'
        ];

        if (
            !$id ||
            !in_array($status, $allowedStatuses, true)
        ) {
            flash('error', 'Invalid invitation status.');
            redirect(BASE_URL . '/invitations.php');
        }

        // Get invitation
        $invitationDoc = mcoll('invitations')->findOne(['id' => $id]);

        if (!$invitationDoc) {
            flash('error', 'Invitation not found.');
            redirect(BASE_URL . '/invitations.php');
        }
        $invitation = mongo_doc_to_array($invitationDoc);

        try {
            $now = date('Y-m-d H:i:s');
            $responded_at = ($status === 'Accepted' || $status === 'Declined') ? $now : null;

            mcoll('invitations')->updateOne(
                ['id' => $id],
                ['$set' => [
                    'status' => $status,
                    'responded_at' => $responded_at
                ]]
            );

            // Audit log
            mongo_audit_log(
                'Invitation Status Updated',
                'Invitation #' .
                $id .
                ' for ' .
                ($invitation['guest_name'] ?? '') .
                ' changed to ' .
                $status .
                '.'
            );

            flash(
                'success',
                'Invitation status updated to ' .
                $status .
                '.'
            );

        } catch (Throwable $e) {
            flash(
                'error',
                'Unable to update invitation status.'
            );
        }

        redirect(BASE_URL . '/invitations.php');
    }


    // =================================================
    // DELETE INVITATION
    // =================================================

    if ($action === 'delete') {

        $id = (int)($_POST['id'] ?? 0);

        if (!$id) {
            flash('error', 'Invalid invitation.');
            redirect(BASE_URL . '/invitations.php');
        }

        $invitationDoc = mcoll('invitations')->findOne(['id' => $id]);

        if (!$invitationDoc) {
            flash('error', 'Invitation not found.');
            redirect(BASE_URL . '/invitations.php');
        }
        $invitation = mongo_doc_to_array($invitationDoc);

        try {
            mcoll('invitations')->deleteOne(['id' => $id]);

            // Audit log
            mongo_audit_log(
                'Invitation Deleted',
                'Invitation #' .
                $id .
                ' for ' .
                ($invitation['guest_name'] ?? '') .
                ' was deleted.'
            );

            flash(
                'success',
                'Invitation deleted successfully.'
            );

        } catch (Throwable $e) {
            flash(
                'error',
                'Unable to delete invitation.'
            );
        }

        redirect(BASE_URL . '/invitations.php');
    }
}


// =====================================================
// FILTERS
// =====================================================

$search = trim($_GET['q'] ?? '');
$eventFilter = (int)($_GET['event_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');


// =====================================================
// EVENTS (MongoDB Atlas)
// =====================================================

$eventsCursor = mcoll('events')->find([], ['sort' => ['event_date' => -1]]);
$events = [];
foreach ($eventsCursor as $eDoc) {
    $events[] = mongo_doc_to_array($eDoc);
}


// =====================================================
// STATISTICS (MongoDB Atlas)
// =====================================================

$stats = [
    'total' => mcoll('invitations')->countDocuments([]),
    'pending' => mcoll('invitations')->countDocuments(['status' => 'Pending']),
    'sent' => mcoll('invitations')->countDocuments(['status' => 'Sent']),
    'accepted' => mcoll('invitations')->countDocuments(['status' => 'Accepted']),
    'declined' => mcoll('invitations')->countDocuments(['status' => 'Declined'])
];


// =====================================================
// INVITATION QUERY (MongoDB Atlas Aggregation Pipeline)
// =====================================================

$pipeline = [];

$pipeline[] = [
    '$lookup' => [
        'from' => 'events',
        'localField' => 'event_id',
        'foreignField' => 'id',
        'as' => 'event_docs'
    ]
];

$pipeline[] = [
    '$addFields' => [
        'event_name' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.name', 0]], '']],
        'event_date' => ['$ifNull' => [['$arrayElemAt' => ['$event_docs.event_date', 0]], '']]
    ]
];

$pipeline[] = [
    '$project' => [
        'event_docs' => 0
    ]
];

$match = [];

if ($search !== '') {
    $regex = new MongoDB\BSON\Regex($search, 'i');
    $match['$or'] = [
        ['guest_name' => $regex],
        ['guest_email' => $regex],
        ['event_name' => $regex]
    ];
}

if ($eventFilter > 0) {
    $match['event_id'] = $eventFilter;
}

if ($statusFilter !== '') {
    $match['status'] = $statusFilter;
}

if (!empty($match)) {
    $pipeline[] = ['$match' => $match];
}

$pipeline[] = [
    '$sort' => [
        'invited_at' => -1,
        'id' => -1
    ]
];

$cursor = mcoll('invitations')->aggregate($pipeline);
$rows = [];
foreach ($cursor as $doc) {
    $rows[] = mongo_doc_to_array($doc);
}


// =====================================================
// HEADER
// =====================================================

require __DIR__ . '/includes/header.php';

?>


<div class="page-head">

    <div>

        <span class="eyebrow">
            DIGITAL INVITATION MANAGEMENT
        </span>

        <h1>
            Invitations
        </h1>

        <p>
            Create, track and manage guest invitations and responses.
        </p>

    </div>

</div>


<!-- =====================================================
     STATISTICS
====================================================== -->

<div class="stat-grid invitation-stats">

    <div class="stat-card">

        <span>Total Invitations</span>

        <strong>
            <?= $stats['total'] ?>
        </strong>

        <em>
            All invitation records
        </em>

    </div>


    <div class="stat-card">

        <span>Pending</span>

        <strong>
            <?= $stats['pending'] ?>
        </strong>

        <em>
            Not yet sent / processed
        </em>

    </div>


    <div class="stat-card">

        <span>Sent</span>

        <strong>
            <?= $stats['sent'] ?>
        </strong>

        <em>
            Invitation delivered
        </em>

    </div>


    <div class="stat-card">

        <span>Accepted</span>

        <strong>
            <?= $stats['accepted'] ?>
        </strong>

        <em>
            Guest confirmed
        </em>

    </div>


    <div class="stat-card">

        <span>Declined</span>

        <strong>
            <?= $stats['declined'] ?>
        </strong>

        <em>
            Guest declined
        </em>

    </div>

</div>


<!-- =====================================================
     INVITATION WORKSPACE
====================================================== -->

<div class="invitation-layout">


    <!-- =================================================
         REGISTER
    ================================================== -->

    <section class="panel invitation-register">

        <div class="panel-head">

            <div>

                <span class="eyebrow">
                    INVITATION REGISTER
                </span>

                <h2>
                    Guest Invitations
                </h2>

            </div>

            <span class="record-count">
                <?= count($rows) ?>
                record<?= count($rows) !== 1 ? 's' : '' ?>
            </span>

        </div>


        <!-- FILTER -->

        <form
            method="get"
            class="invitation-filter"
        >

            <input
                type="search"
                name="q"
                value="<?= e($search) ?>"
                placeholder="Search guest, email or event..."
            >


            <select name="event_id">

                <option value="">
                    All Events
                </option>

                <?php foreach ($events as $event): ?>

                    <option
                        value="<?= (int)$event['id'] ?>"
                        <?= $eventFilter === (int)$event['id']
                            ? 'selected'
                            : '' ?>
                    >

                        <?= e($event['name']) ?>

                    </option>

                <?php endforeach; ?>

            </select>


            <select name="status">

                <option value="">
                    All Status
                </option>

                <option
                    value="Pending"
                    <?= $statusFilter === 'Pending'
                        ? 'selected'
                        : '' ?>
                >
                    Pending
                </option>

                <option
                    value="Sent"
                    <?= $statusFilter === 'Sent'
                        ? 'selected'
                        : '' ?>
                >
                    Sent
                </option>

                <option
                    value="Accepted"
                    <?= $statusFilter === 'Accepted'
                        ? 'selected'
                        : '' ?>
                >
                    Accepted
                </option>

                <option
                    value="Declined"
                    <?= $statusFilter === 'Declined'
                        ? 'selected'
                        : '' ?>
                >
                    Declined
                </option>

            </select>


            <button class="btn btn-dark">
                Filter
            </button>


            <?php if (
                $search ||
                $eventFilter ||
                $statusFilter
            ): ?>

                <a
                    href="<?= BASE_URL ?>/invitations.php"
                    class="btn btn-light"
                >
                    Reset
                </a>

            <?php endif; ?>

        </form>


        <?php if ($rows): ?>

            <div class="table-wrap">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Guest
                            </th>

                            <th>
                                Event
                            </th>

                            <th>
                                Invitation
                            </th>

                            <th>
                                Response
                            </th>

                            <th>
                                Attendance / QR
                            </th>

                            <th>
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($rows as $row): ?>

                            <tr>

                                <!-- GUEST -->

                                <td>

                                    <strong>
                                        <?= e($row['guest_name']) ?>
                                    </strong>

                                    <small>
                                        <?= e($row['guest_email']) ?>
                                    </small>

                                </td>


                                <!-- EVENT -->

                                <td>

                                    <strong>
                                        <?= e($row['event_name']) ?>
                                    </strong>

                                    <small>

                                        <?= e(
                                            date(
                                                'd M Y',
                                                strtotime(
                                                    $row['event_date']
                                                )
                                            )
                                        ) ?>

                                    </small>

                                </td>


                                <!-- INVITATION -->

                                <td>

                                    <span
                                        class="badge invitation-<?= strtolower($row['status']) ?>"
                                    >
                                        <?= e($row['status']) ?>
                                    </span>

                                    <small>

                                        <?= e(
                                            date(
                                                'd M Y h:i A',
                                                strtotime(
                                                    $row['invited_at']
                                                )
                                            )
                                        ) ?>

                                    </small>

                                </td>


                                <!-- RESPONSE -->

                                <td>

                                    <?php if (!empty($row['responded_at'])): ?>

                                        <strong>

                                            <?= e(
                                                date(
                                                    'd M Y',
                                                    strtotime(
                                                        $row['responded_at']
                                                    )
                                                )
                                            ) ?>

                                        </strong>

                                        <small>

                                            <?= e(
                                                date(
                                                    'h:i A',
                                                    strtotime(
                                                        $row['responded_at']
                                                    )
                                                )
                                            ) ?>

                                        </small>

                                    <?php else: ?>

                                        <span class="muted">
                                            No response
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- ATTENDANCE / QR -->
                                <td>
                                    <?php if (!empty($row['qr_token'])): ?>
                                        <div style="display:flex; flex-direction:column; gap:6px;">
                                            <span style="display:inline-block; font-family:monospace; font-weight:700; letter-spacing:2px; font-size:13px; color:#082d21; background:#eef2f6; padding:4px 8px; border-radius:6px; border:1px solid #cbd5e1; width:fit-content;">
                                                <?= e($row['qr_token']) ?>
                                            </span>
                                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                                <button type="button" class="btn btn-sm btn-light" style="padding:2px 8px; font-size:11px;" onclick="showQrModal('<?= e($row['qr_token']) ?>', '<?= e($row['guest_name']) ?>', '<?= e($row['event_name']) ?>')">
                                                    📷 QR Code
                                                </button>
                                                <a href="<?= BASE_URL ?>/attendance.php?token=<?= urlencode($row['qr_token']) ?>" class="btn btn-sm btn-primary" style="padding:2px 8px; font-size:11px;">
                                                    ✓ Check-in
                                                </a>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>


                                <!-- ACTIONS -->

                                <td>

                                    <div class="invitation-actions">


                                        <!-- STATUS -->

                                        <form
                                            method="post"
                                            class="status-form"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="status"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$row['id'] ?>"
                                            >


                                            <select
                                                name="status"
                                                onchange="this.form.submit()"
                                                aria-label="Change invitation status"
                                            >

                                                <option
                                                    value="Pending"
                                                    <?= $row['status'] === 'Pending'
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    Pending
                                                </option>

                                                <option
                                                    value="Sent"
                                                    <?= $row['status'] === 'Sent'
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    Sent
                                                </option>

                                                <option
                                                    value="Accepted"
                                                    <?= $row['status'] === 'Accepted'
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    Accepted
                                                </option>

                                                <option
                                                    value="Declined"
                                                    <?= $row['status'] === 'Declined'
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    Declined
                                                </option>

                                            </select>

                                        </form>


                                        <!-- DELETE -->

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Delete this invitation?')"
                                        >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)$row['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-danger"
                                            >
                                                Delete
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty">

                <strong>
                    No invitations found
                </strong>

                <p>
                    Create an invitation or change your filters.
                </p>

            </div>

        <?php endif; ?>

    </section>


    <!-- =================================================
         CREATE INVITATION
    ================================================== -->

    <section class="panel form-panel create-invitation-panel">

        <div class="panel-head">

            <div>

                <span class="eyebrow">
                    DIGITAL INVITATION
                </span>

                <h2>
                    Create Invitation
                </h2>

                <p>
                    Add a guest invitation to an event.
                </p>

            </div>

        </div>


        <form method="post">

            <input
                type="hidden"
                name="action"
                value="create"
            >


            <label>

                Event

                <select
                    name="event_id"
                    required
                >

                    <option value="">
                        Select Event
                    </option>

                    <?php foreach ($events as $event): ?>

                        <option
                            value="<?= (int)$event['id'] ?>"
                        >

                            <?= e($event['name']) ?>

                            —
                            <?= e(
                                date(
                                    'd M Y, h:i A',
                                    strtotime(
                                        $event['event_date'] . ' ' .
                                        $event['event_time']
                                    )
                                )
                            ) ?>

                            — <?= e($event['venue']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </label>


            <label>

                Guest Name

                <input
                    type="text"
                    name="guest_name"
                    maxlength="160"
                    placeholder="Full guest name"
                    required
                >

            </label>


            <label>

                Guest Email

                <input
                    type="email"
                    name="guest_email"
                    maxlength="160"
                    placeholder="guest@example.com"
                    required
                >

            </label>


            <div class="invitation-info">

                <strong>
                    Invitation Workflow
                </strong>

                <p>
                    New invitations start as Pending. Update the status as the invitation is sent and the guest responds.
                </p>

            </div>


            <button
                type="submit"
                class="btn btn-primary btn-lg"
            >
                Create Invitation
            </button>

        </form>

    </section>

</div>


<style>

/* =========================================
   MAIN LAYOUT
========================================= */

.invitation-layout {
    display: grid;
    grid-template-columns:
        minmax(0, 1.5fr)
        minmax(320px, .7fr);
    gap: 18px;
    margin-top: 18px;
}

.invitation-register,
.create-invitation-panel {
    min-width: 0;
}


/* =========================================
   FILTER
========================================= */

.invitation-filter {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.5fr)
        minmax(150px, 1fr)
        minmax(130px, .7fr)
        auto
        auto;
    gap: 8px;
    margin-bottom: 18px;
}

.invitation-filter input,
.invitation-filter select {
    min-width: 0;
}


/* =========================================
   TABLE
========================================= */

.invitation-register table small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
}

.invitation-register table strong {
    display: block;
}


/* =========================================
   STATUS BADGES
========================================= */

.invitation-pending {
    background: rgba(234,179,8,.13);
    color: #ca8a04;
}

.invitation-sent {
    background: rgba(37,99,235,.13);
    color: #2563eb;
}

.invitation-accepted {
    background: rgba(34,197,94,.13);
    color: #16a34a;
}

.invitation-declined {
    background: rgba(239,68,68,.13);
    color: #dc2626;
}


/* =========================================
   ACTIONS
========================================= */

.invitation-actions {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

.invitation-actions form {
    margin: 0;
}

.status-form select {
    min-width: 110px;
    padding: 8px 30px 8px 10px;
    font-size: 12px;
}


/* =========================================
   CREATE PANEL
========================================= */

.create-invitation-panel {
    align-self: start;
}

.invitation-info {
    margin: 5px 0 17px;
    padding: 14px;
    border-radius: 12px;
    background: rgba(148,163,184,.06);
    border: 1px solid rgba(148,163,184,.12);
}

.invitation-info strong {
    display: block;
    font-size: 12px;
}

.invitation-info p {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.55;
}

.record-count {
    color: var(--muted);
    font-size: 12px;
}


/* =========================================
   RESPONSIVE
========================================= */

@media (max-width: 1100px) {

    .invitation-filter {
        grid-template-columns:
            1fr 1fr;
    }

    .invitation-filter input {
        grid-column: 1 / -1;
    }

}

@media (max-width: 1000px) {

    .invitation-layout {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 720px) {

    .invitation-filter {
        grid-template-columns: 1fr;
    }

    .invitation-filter input {
        grid-column: auto;
    }

    .invitation-filter .btn,
    .invitation-filter a {
        width: 100%;
    }

    .invitation-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .invitation-actions .btn,
    .status-form,
    .status-form select {
        width: 100%;
    }

}

</style>

<!-- QR Code Modal -->
<div id="qrModal" style="display:none; position:fixed; inset:0; background:rgba(10,25,47,0.75); z-index:9999; backdrop-filter:blur(4px); align-items:center; justify-content:center;">
    <div style="background:#ffffff; border-radius:16px; padding:32px; width:90%; max-width:440px; text-align:center; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); position:relative; border:1px solid #e2e8f0;">
        <button type="button" onclick="closeQrModal()" style="position:absolute; top:16px; right:16px; background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">&times;</button>
        <div style="display:inline-block; border:1px solid #d4ad5b; color:#a47b2c; font-size:11px; font-weight:bold; letter-spacing:2px; padding:4px 10px; border-radius:4px; margin-bottom:12px;">
            BUP ATTENDANCE PASS
        </div>
        <h3 id="modalGuestName" style="margin:0 0 4px; color:#082d21; font-size:18px;"></h3>
        <p id="modalEventName" style="margin:0 0 18px; color:#64748b; font-size:13px;"></p>
        <div style="background:#f8fafc; border:2px dashed #cbd5e1; border-radius:12px; padding:16px; display:inline-block; margin-bottom:16px;">
            <img id="modalQrImg" src="" alt="QR Code" width="180" height="180" style="display:block; margin:auto; border-radius:8px;" />
        </div>
        <p style="margin:0 0 6px; font-size:12px; color:#64748b; font-weight:600;">ATTENDANCE TOKEN</p>
        <div id="modalToken" style="font-size:28px; font-weight:800; letter-spacing:7px; font-family:monospace; color:#082d21; background:#f1f5f9; padding:8px 16px; border-radius:8px; display:inline-block; border:1px solid #cbd5e1; margin-bottom:20px;"></div>
        <div style="display:flex; gap:10px; justify-content:center;">
            <button type="button" class="btn btn-light" onclick="copyToken()">📋 Copy Token</button>
            <a id="modalCheckinBtn" href="#" class="btn btn-primary">✓ Check-in Guest</a>
        </div>
    </div>
</div>

<script>
function showQrModal(token, guestName, eventName) {
    document.getElementById('modalGuestName').innerText = guestName;
    document.getElementById('modalEventName').innerText = eventName;
    document.getElementById('modalToken').innerText = token;
    document.getElementById('modalQrImg').src = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(token);
    document.getElementById('modalCheckinBtn').href = '<?= BASE_URL ?>/attendance.php?token=' + encodeURIComponent(token);
    var modal = document.getElementById('qrModal');
    modal.style.display = 'flex';
}
function closeQrModal() {
    document.getElementById('qrModal').style.display = 'none';
}
function copyToken() {
    var token = document.getElementById('modalToken').innerText;
    navigator.clipboard.writeText(token).then(function() {
        alert('Token copied to clipboard: ' + token);
    });
}
window.onclick = function(e) {
    var modal = document.getElementById('qrModal');
    if (e.target === modal) {
        closeQrModal();
    }
};
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>