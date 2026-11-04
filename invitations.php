<?php

require_once __DIR__ . '/config/auth.php';
require_login();

$pdo = db();

$page_title = 'Invitations | BUP Protocol';

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =====================================================
// HANDLE POST ACTIONS
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

            flash(
                'error',
                'Event, guest name and email are required.'
            );

            redirect(
                BASE_URL . '/invitations.php'
            );
        }


        if (!filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {

            flash(
                'error',
                'Please enter a valid email address.'
            );

            redirect(
                BASE_URL . '/invitations.php'
            );
        }


        // Verify event
        $stmt = $pdo->prepare("
            SELECT
                id,
                name,
                event_date,
                event_time,
                venue,
                description
            FROM events
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([$eventId]);
        $event = $stmt->fetch();


        if (!$event) {

            flash(
                'error',
                'Selected event was not found.'
            );

            redirect(
                BASE_URL . '/invitations.php'
            );
        }


        try {
            $pdo->beginTransaction();

            // 1. Check if guest exists, or create guest for this event
            $stmt = $pdo->prepare("SELECT id FROM guests WHERE event_id = ? AND (email = ? OR name = ?) LIMIT 1");
            $stmt->execute([$eventId, $guestEmail, $guestName]);
            $guestId = $stmt->fetchColumn();

            if (!$guestId) {
                $stmt = $pdo->prepare("
                    INSERT INTO guests (event_id, name, email, category, priority_level, arrival_status)
                    VALUES (?, ?, ?, 'Invited Guest', 'Normal', 'Expected')
                ");
                $stmt->execute([$eventId, $guestName, $guestEmail]);
                $guestId = (int)$pdo->lastInsertId();
            }

            // 2. Generate unique 5-char attendance token
            $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            do {
                $token = '';
                for ($i = 0; $i < 5; $i++) {
                    $token .= $characters[random_int(0, strlen($characters) - 1)];
                }
                $check = $pdo->prepare("SELECT id FROM attendance WHERE qr_token = ? LIMIT 1");
                $check->execute([$token]);
            } while ($check->fetch());

            // 3. Register in attendance table if not already present
            $attCheck = $pdo->prepare("SELECT id, qr_token FROM attendance WHERE event_id = ? AND guest_id = ? LIMIT 1");
            $attCheck->execute([$eventId, $guestId]);
            $existingAtt = $attCheck->fetch();

            if ($existingAtt && !empty($existingAtt['qr_token'])) {
                $token = $existingAtt['qr_token'];
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO attendance (event_id, guest_id, qr_token, status)
                    VALUES (?, ?, ?, 'Registered')
                ");
                $stmt->execute([$eventId, $guestId, $token]);
            }

            // 4. Create invitation record
            $stmt = $pdo->prepare("
                INSERT INTO invitations
                    (event_id, guest_name, guest_email, qr_token, status, invited_at)
                VALUES (?, ?, ?, ?, 'Sent', NOW())
            ");

            $stmt->execute([
                $eventId,
                $guestName,
                $guestEmail,
                $token
            ]);

            $invitationId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, description)
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                current_user()['id'],
                'Invitation Created',
                'Invitation #' . $invitationId .
                ' created for ' . $guestName .
                ' (token: ' . $token . ') for event "' . $event['name'] . '".'
            ]);

            $pdo->commit();

            // 5. Send email through PHPMailer SMTP with QR Code & Token
            $emailSent = false;
            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . urlencode($token);

            try {
                $mailConfig = require __DIR__ . '/config/mail.php';

                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = $mailConfig['host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $mailConfig['username'];
                $mail->Password   = $mailConfig['password'];
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = (int) $mailConfig['port'];
                $mail->CharSet    = 'UTF-8';
                $mail->Timeout    = 8;
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ]
                ];

                $mail->setFrom(
                    $mailConfig['from_email'],
                    $mailConfig['from_name']
                );

                $mail->addAddress($guestEmail, $guestName);

                $mail->isHTML(true);
                $mail->Subject = 'Official Invitation & Attendance Pass: ' . $event['name'];

                $safeGuest = htmlspecialchars(
                    $guestName,
                    ENT_QUOTES,
                    'UTF-8'
                );

                $safeEvent = htmlspecialchars(
                    $event['name'] ?? 'Official Event',
                    ENT_QUOTES,
                    'UTF-8'
                );

                $safeVenue = htmlspecialchars(
                    $event['venue'] ?? 'To be announced',
                    ENT_QUOTES,
                    'UTF-8'
                );

                $safeDescription = htmlspecialchars(
                    $event['description'] ?? '',
                    ENT_QUOTES,
                    'UTF-8'
                );

                $eventDate = !empty($event['event_date'])
                    ? date('l, d F Y', strtotime($event['event_date']))
                    : 'To be announced';

                $eventTime = !empty($event['event_time'])
                    ? date('h:i A', strtotime($event['event_time']))
                    : 'To be announced';

                $safeDescription = nl2br($safeDescription);

                $mail->Body = '
                    <!DOCTYPE html>
                    <html lang="en">
                    <head>
                        <meta charset="UTF-8">
                        <meta name="viewport" content="width=device-width, initial-scale=1.0">
                        <title>Official Event Invitation</title>
                    </head>
                    <body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#243247;">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f1f5f9;padding:30px 10px;">
                    <tr>
                    <td align="center">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:650px;background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
                        <tr>
                            <td align="center" style="background:#082d21;padding:32px 24px;border-bottom:5px solid #d4ad5b;">
                                <div style="display:inline-block;border:1px solid #d4ad5b;color:#f5d98d;font-size:12px;font-weight:bold;letter-spacing:3px;padding:8px 14px;border-radius:4px;margin-bottom:16px;">
                                    BANGLADESH UNIVERSITY OF PROFESSIONALS
                                </div>
                                <h1 style="margin:0;color:#ffffff;font-size:27px;line-height:1.4;">
                                    Official Event Invitation
                                </h1>
                                <p style="margin:10px 0 0;color:#dbe5f1;font-size:13px;line-height:1.6;">
                                    Event &amp; Military Protocol Management System
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:32px 28px 18px;">
                                <p style="margin:0 0 18px;font-size:16px;line-height:1.8;">
                                    Dear <strong>' . $safeGuest . '</strong>,
                                </p>
                                <p style="margin:0 0 20px;color:#475569;font-size:15px;line-height:1.8;">
                                    On behalf of the BUP Event &amp; Protocol Team, we are pleased to invite you to attend the following official event.
                                </p>
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:5px solid #d4ad5b;border-radius:10px;padding:22px;margin-bottom:24px;">
                                    <p style="margin:0 0 8px;color:#a47b2c;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">
                                        You Are Cordially Invited To
                                    </p>
                                    <h2 style="margin:0;color:#082d21;font-size:24px;line-height:1.5;">
                                        ' . $safeEvent . '
                                    </h2>
                                </div>
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e2e8f0;border-radius:9px;overflow:hidden;">
                                    <tr>
                                        <td width="35%" style="padding:14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:13px;font-weight:bold;">DATE</td>
                                        <td style="padding:14px;border-bottom:1px solid #e2e8f0;color:#082d21;font-size:14px;font-weight:bold;">' . htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8') . '</td>
                                    </tr>
                                    <tr>
                                        <td width="35%" style="padding:14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:13px;font-weight:bold;">TIME</td>
                                        <td style="padding:14px;border-bottom:1px solid #e2e8f0;color:#082d21;font-size:14px;font-weight:bold;">' . htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8') . '</td>
                                    </tr>
                                    <tr>
                                        <td width="35%" style="padding:14px;background:#f8fafc;color:#64748b;font-size:13px;font-weight:bold;">VENUE</td>
                                        <td style="padding:14px;color:#082d21;font-size:14px;font-weight:bold;">' . $safeVenue . '</td>
                                    </tr>
                                </table>

                                <!-- ATTENDANCE PASS & QR CODE -->
                                <div style="margin:26px 0;padding:24px 20px;background:#f8fafc;border:2px dashed #082d21;border-radius:12px;text-align:center;">
                                    <p style="margin:0 0 10px;color:#a47b2c;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">
                                        Your Official Attendance Pass
                                    </p>
                                    <div style="display:inline-block;background:#ffffff;padding:12px;border:1px solid #cbd5e1;border-radius:10px;margin-bottom:12px;">
                                        <img src="' . $qrUrl . '" alt="Attendance QR Code" width="170" height="170" style="display:block;margin:auto;" />
                                    </div>
                                    <p style="margin:8px 0 6px;color:#475569;font-size:13px;font-weight:bold;">
                                        Attendance Check-in Token:
                                    </p>
                                    <div style="display:inline-block;background:#082d21;color:#f5d98d;font-size:28px;font-weight:bold;letter-spacing:7px;font-family:monospace;padding:8px 22px;border-radius:8px;border:1px solid #d4ad5b;">
                                        ' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '
                                    </div>
                                    <p style="margin:12px 0 0;color:#64748b;font-size:12px;line-height:1.6;">
                                        Please present this QR code or 5-character token at the arrival counter to record your attendance.
                                    </p>
                                </div>

                                ' . (trim($event['description'] ?? '') !== '' ? '
                                <div style="margin-top:20px;padding:18px;background:#ffffff;border:1px solid #e2e8f0;border-radius:9px;">
                                    <h3 style="margin:0 0 8px;color:#082d21;font-size:15px;">About the Event</h3>
                                    <p style="margin:0;color:#475569;font-size:13px;line-height:1.7;">' . $safeDescription . '</p>
                                </div>
                                ' : '') . '

                                <p style="margin:24px 0 16px;color:#475569;font-size:14px;line-height:1.8;">
                                    We would be honoured by your presence and look forward to welcoming you to this event.
                                </p>
                                <p style="margin:0;color:#475569;font-size:14px;line-height:1.8;">
                                    Yours sincerely,<br>
                                    <strong style="color:#082d21;">BUP Event &amp; Protocol Team</strong><br>
                                    <span style="color:#64748b;font-size:12px;">Event &amp; Military Protocol Management System</span>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <td align="center" style="padding:20px;background:#082d21;border-top:3px solid #d4ad5b;">
                                <p style="margin:0 0 5px;color:#ffffff;font-size:12px;font-weight:bold;">BUP Protocol</p>
                                <p style="margin:0;color:#cbd5e1;font-size:11px;">This is an official event invitation email. Please retain your QR token for attendance.</p>
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
                    "OFFICIAL EVENT INVITATION\n\n" .
                    "Dear {$guestName},\n\n" .
                    "You are invited to attend:\n" .
                    ($event['name'] ?? 'Official Event') . "\n\n" .
                    "Date: {$eventDate}\n" .
                    "Time: {$eventTime}\n" .
                    "Venue: " . ($event['venue'] ?? 'To be announced') . "\n\n" .
                    "ATTENDANCE CHECK-IN TOKEN: {$token}\n" .
                    "Present this 5-character token at the reception desk to complete check-in.\n\n" .
                    "We look forward to welcoming you.\n\n" .
                    "Yours sincerely,\n" .
                    "BUP Event & Protocol Team";

                $mail->send();
                $emailSent = true;

                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action, description)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([
                    current_user()['id'],
                    'Invitation Email Sent',
                    'Email submitted successfully to ' . $guestEmail .
                    ' for invitation #' . $invitationId . ' (token: ' . $token . ').'
                ]);

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
                $invMsg = 'You have received an invitation to join ' . $event['name'] . '.';
                $pdo->prepare("INSERT INTO announcements (title, message, created_by) VALUES (?, ?, ?)")
                    ->execute([
                        'Event Invitation: ' . $event['name'],
                        $invMsg . ' Date: ' . $eventDate . ' | Venue: ' . ($event['venue'] ?? 'To be announced') . '. Actions: [Accept] [Decline] [View Details]',
                        current_user()['id']
                    ]);
            } catch (Throwable $annEx) {
                // Ignore announcement error
            }

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Invitation creation error: ' . $e->getMessage());

            flash(
                'error',
                'Unable to create invitation: ' . $e->getMessage()
            );
        }


        redirect(
            BASE_URL . '/invitations.php'
        );
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

            flash(
                'error',
                'Invalid invitation status.'
            );

            redirect(
                BASE_URL . '/invitations.php'
            );
        }


        // Get invitation
        $stmt = $pdo->prepare("
            SELECT
                i.*,
                e.name AS event_name
            FROM invitations i
            LEFT JOIN events e
                ON e.id = i.event_id
            WHERE i.id = ?
        ");

        $stmt->execute([$id]);

        $invitation = $stmt->fetch();


        if (!$invitation) {

            flash(
                'error',
                'Invitation not found.'
            );

            redirect(
                BASE_URL . '/invitations.php'
            );
        }


        try {

            $pdo->beginTransaction();


            /*
             * Accepted / Declined means
             * guest has responded.
             *
             * Sent / Pending keeps responded_at NULL.
             */

            if (
                $status === 'Accepted' ||
                $status === 'Declined'
            ) {

                $stmt = $pdo->prepare("
                    UPDATE invitations
                    SET
                        status = ?,
                        responded_at = NOW()
                    WHERE id = ?
                ");

                $stmt->execute([
                    $status,
                    $id
                ]);

            } else {

                $stmt = $pdo->prepare("
                    UPDATE invitations
                    SET
                        status = ?,
                        responded_at = NULL
                    WHERE id = ?
                ");

                $stmt->execute([
                    $status,
                    $id
                ]);
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
                current_user()['id'],
                'Invitation Status Updated',
                'Invitation #' .
                $id .
                ' for ' .
                $invitation['guest_name'] .
                ' changed to ' .
                $status .
                '.'
            ]);


            $pdo->commit();


            flash(
                'success',
                'Invitation status updated to ' .
                $status .
                '.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'error',
                'Unable to update invitation status.'
            );
        }


        redirect(
            BASE_URL . '/invitations.php'
        );
    }


    // =================================================
    // DELETE INVITATION
    // =================================================

    if ($action === 'delete') {

        $id = (int)($_POST['id'] ?? 0);


        if (!$id) {

            flash(
                'error',
                'Invalid invitation.'
            );

            redirect(
                BASE_URL . '/invitations.php'
            );
        }


        $stmt = $pdo->prepare("
            SELECT id, guest_name
            FROM invitations
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        $invitation = $stmt->fetch();


        if (!$invitation) {

            flash(
                'error',
                'Invitation not found.'
            );

            redirect(
                BASE_URL . '/invitations.php'
            );
        }


        try {

            $pdo->beginTransaction();


            $stmt = $pdo->prepare("
                DELETE FROM invitations
                WHERE id = ?
            ");

            $stmt->execute([$id]);


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
                current_user()['id'],
                'Invitation Deleted',
                'Invitation #' .
                $id .
                ' for ' .
                $invitation['guest_name'] .
                ' was deleted.'
            ]);


            $pdo->commit();


            flash(
                'success',
                'Invitation deleted successfully.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            flash(
                'error',
                'Unable to delete invitation.'
            );
        }


        redirect(
            BASE_URL . '/invitations.php'
        );
    }
}


// =====================================================
// FILTERS
// =====================================================

$search = trim($_GET['q'] ?? '');
$eventFilter = (int)($_GET['event_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');


// =====================================================
// EVENTS
// =====================================================

$events = $pdo->query("
    SELECT
        id,
        name,
        event_date,
        event_time,
        venue
    FROM events
    ORDER BY event_date DESC
")->fetchAll();


// =====================================================
// STATISTICS
// =====================================================

$stats = [

    'total' => (int)$pdo->query("
        SELECT COUNT(*)
        FROM invitations
    ")->fetchColumn(),

    'pending' => (int)$pdo->query("
        SELECT COUNT(*)
        FROM invitations
        WHERE status = 'Pending'
    ")->fetchColumn(),

    'sent' => (int)$pdo->query("
        SELECT COUNT(*)
        FROM invitations
        WHERE status = 'Sent'
    ")->fetchColumn(),

    'accepted' => (int)$pdo->query("
        SELECT COUNT(*)
        FROM invitations
        WHERE status = 'Accepted'
    ")->fetchColumn(),

    'declined' => (int)$pdo->query("
        SELECT COUNT(*)
        FROM invitations
        WHERE status = 'Declined'
    ")->fetchColumn()
];


// =====================================================
// INVITATION QUERY
// =====================================================

$sql = "
    SELECT
        i.*,
        e.name AS event_name,
        e.event_date
    FROM invitations i
    LEFT JOIN events e
        ON e.id = i.event_id
    WHERE 1=1
";

$params = [];


// Search

if ($search !== '') {

    $sql .= "
        AND (
            i.guest_name LIKE ?
            OR i.guest_email LIKE ?
            OR e.name LIKE ?
        )
    ";

    $term = '%' . $search . '%';

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}


// Event filter

if ($eventFilter) {

    $sql .= "
        AND i.event_id = ?
    ";

    $params[] = $eventFilter;
}


// Status filter

if ($statusFilter !== '') {

    $sql .= "
        AND i.status = ?
    ";

    $params[] = $statusFilter;
}


$sql .= "
    ORDER BY i.invited_at DESC
";


$stmt = $pdo->prepare($sql);

$stmt->execute($params);

$rows = $stmt->fetchAll();


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