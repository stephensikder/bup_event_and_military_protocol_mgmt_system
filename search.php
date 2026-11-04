<?php

require_once __DIR__ . '/config/auth.php';
require_login();

$pdo = db();

$page_title = 'Advanced Search | BUP Protocol';

$q = trim($_GET['q'] ?? '');

$events = [];
$guests = [];
$duties = [];
$protocol = [];
$invitations = [];
$transport = [];
$emergency = [];
$announcements = [];

$totalResults = 0;


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($q !== '') {

    $like = '%' . $q . '%';


    /*
    |--------------------------------------------------------------------------
    | EVENTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            event_type,
            event_date,
            event_time,
            venue,
            status,
            priority
        FROM events
        WHERE
            name LIKE ?
            OR event_type LIKE ?
            OR venue LIKE ?
            OR description LIKE ?
            OR status LIKE ?
        ORDER BY event_date DESC
        LIMIT 30
    ");

    $stmt->execute([
        $like,
        $like,
        $like,
        $like,
        $like
    ]);

    $events = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | GUESTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            g.*,
            e.name AS event_name
        FROM guests g
        LEFT JOIN events e
            ON e.id = g.event_id
        WHERE
            g.name LIKE ?
            OR g.designation LIKE ?
            OR g.organization LIKE ?
            OR g.phone LIKE ?
            OR g.email LIKE ?
            OR g.category LIKE ?
            OR g.priority_level LIKE ?
            OR g.arrival_status LIKE ?
        ORDER BY g.name ASC
        LIMIT 30
    ");

    $stmt->execute([
        $like,
        $like,
        $like,
        $like,
        $like,
        $like,
        $like,
        $like
    ]);

    $guests = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | DUTIES
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            d.*,
            e.name AS event_name,
            u.name AS assigned_name
        FROM duties d
        LEFT JOIN events e
            ON e.id = d.event_id
        LEFT JOIN users u
            ON u.id = d.assigned_to
        WHERE
            d.title LIKE ?
            OR d.description LIKE ?
            OR d.status LIKE ?
        ORDER BY d.due_at ASC
        LIMIT 30
    ");

    $stmt->execute([
        $like,
        $like,
        $like
    ]);

    $duties = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | PROTOCOL
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            p.*,
            e.name AS event_name
        FROM protocol_items p
        LEFT JOIN events e
            ON e.id = p.event_id
        WHERE
            p.item LIKE ?
            OR p.instructions LIKE ?
        ORDER BY p.sort_order ASC
        LIMIT 30
    ");

    $stmt->execute([
        $like,
        $like
    ]);

    $protocol = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | INVITATIONS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            i.*,
            e.name AS event_name
        FROM invitations i
        LEFT JOIN events e
            ON e.id = i.event_id
        WHERE
            i.guest_name LIKE ?
            OR i.guest_email LIKE ?
            OR i.status LIKE ?
        ORDER BY i.invited_at DESC
        LIMIT 30
    ");

    $stmt->execute([
        $like,
        $like,
        $like
    ]);

    $invitations = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | TRANSPORT
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            t.*,
            e.name AS event_name
        FROM transport t
        LEFT JOIN events e
            ON e.id = t.event_id
        WHERE
            t.guest_name LIKE ?
            OR t.vehicle_no LIKE ?
            OR t.driver_name LIKE ?
            OR t.parking_slot LIKE ?
            OR t.accommodation LIKE ?
        ORDER BY t.created_at DESC
        LIMIT 30
    ");

    $stmt->execute([
        $like,
        $like,
        $like,
        $like,
        $like
    ]);

    $transport = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | EMERGENCY CONTACTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT *
        FROM emergency_contacts
        WHERE
            name LIKE ?
            OR designation LIKE ?
            OR phone LIKE ?
            OR department LIKE ?
            OR notes LIKE ?
        ORDER BY name ASC
        LIMIT 30
    ");

    $stmt->execute([
        $like,
        $like,
        $like,
        $like,
        $like
    ]);

    $emergency = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | ANNOUNCEMENTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            a.*,
            u.name AS publisher_name
        FROM announcements a
        LEFT JOIN users u
            ON u.id = a.created_by
        WHERE
            a.title LIKE ?
            OR a.message LIKE ?
        ORDER BY a.created_at DESC
        LIMIT 30
    ");

    $stmt->execute([
        $like,
        $like
    ]);

    $announcements = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | TOTAL
    |--------------------------------------------------------------------------
    */

    $totalResults =
        count($events)
        + count($guests)
        + count($duties)
        + count($protocol)
        + count($invitations)
        + count($transport)
        + count($emergency)
        + count($announcements);
}


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
            ADVANCED SEARCH
        </span>

        <h1>
            Search Everything
        </h1>

        <p>
            Search across events, guests, duties, protocol, transport,
            invitations and operational information.
        </p>

    </div>

</div>


<!-- =====================================================
     SEARCH BAR
====================================================== -->

<section class="panel global-search-panel">

    <form
        method="get"
        class="global-search-form"
    >

        <div class="search-input-wrap">

            <span class="search-icon">
                ⌕
            </span>

            <input
                type="search"
                name="q"
                value="<?= e($q) ?>"
                placeholder="Search event, guest, organization, duty, phone, vehicle..."
                autocomplete="off"
                autofocus
            >

        </div>


        <button
            type="submit"
            class="btn btn-primary btn-lg"
        >
            Search
        </button>


        <?php if ($q !== ''): ?>

            <a
                href="<?= BASE_URL ?>/search.php"
                class="btn btn-light btn-lg"
            >
                Clear
            </a>

        <?php endif; ?>

    </form>


    <?php if ($q !== ''): ?>

        <div class="search-summary">

            <div>

                Search results for:

                <strong>
                    "<?= e($q) ?>"
                </strong>

            </div>

            <span class="search-result-count">

                <?= $totalResults ?>

                result<?= $totalResults !== 1 ? 's' : '' ?>

            </span>

        </div>

    <?php else: ?>

        <div class="search-hint">

            <strong>
                Search across the complete operational directory
            </strong>

            <span>
                Try an event name, guest name, organization, phone number,
                vehicle number, duty title or protocol instruction.
            </span>

        </div>

    <?php endif; ?>

</section>


<?php if ($q !== ''): ?>


<!-- =====================================================
     RESULT SUMMARY
====================================================== -->

<div class="search-stat-grid">

    <div class="search-stat">
        <span>Events</span>
        <strong><?= count($events) ?></strong>
    </div>

    <div class="search-stat">
        <span>Guests</span>
        <strong><?= count($guests) ?></strong>
    </div>

    <div class="search-stat">
        <span>Duties</span>
        <strong><?= count($duties) ?></strong>
    </div>

    <div class="search-stat">
        <span>Protocol</span>
        <strong><?= count($protocol) ?></strong>
    </div>

    <div class="search-stat">
        <span>Invitations</span>
        <strong><?= count($invitations) ?></strong>
    </div>

    <div class="search-stat">
        <span>Transport</span>
        <strong><?= count($transport) ?></strong>
    </div>

    <div class="search-stat">
        <span>Emergency</span>
        <strong><?= count($emergency) ?></strong>
    </div>

    <div class="search-stat">
        <span>Announcements</span>
        <strong><?= count($announcements) ?></strong>
    </div>

</div>


<!-- =====================================================
     NO RESULTS
====================================================== -->

<?php if ($totalResults === 0): ?>

    <section class="panel search-no-results">

        <div class="no-result-icon">
            ⌕
        </div>

        <h2>
            No results found
        </h2>

        <p>
            No records matched
            <strong>"<?= e($q) ?>"</strong>.
        </p>

        <p class="muted">
            Try a shorter keyword, event name, guest name,
            organization or phone number.
        </p>

    </section>


<?php else: ?>


<!-- =====================================================
     EVENTS
====================================================== -->

<section class="panel search-result-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                EVENT MANAGEMENT
            </span>

            <h2>
                Events
            </h2>

        </div>

        <span class="result-badge">
            <?= count($events) ?>
        </span>

    </div>


    <?php if ($events): ?>

        <div class="search-results-list">

            <?php foreach ($events as $event): ?>

                <a
                    href="<?= BASE_URL ?>/event_view.php?id=<?= (int)$event['id'] ?>"
                    class="search-result-item"
                >

                    <div class="result-icon event-icon">
                        E
                    </div>

                    <div class="result-content">

                        <strong>
                            <?= e($event['name']) ?>
                        </strong>

                        <span>
                            <?= e($event['event_type']) ?>
                            ·
                            <?= e($event['venue']) ?>
                        </span>

                        <small>
                            <?= e($event['event_date']) ?>

                            <?php if (!empty($event['event_time'])): ?>

                                · <?= e($event['event_time']) ?>

                            <?php endif; ?>

                        </small>

                    </div>

                    <div class="result-meta">

                        <span class="badge neutral">
                            <?= e($event['status']) ?>
                        </span>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            No matching events.
        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     GUESTS
====================================================== -->

<section class="panel search-result-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                GUEST MANAGEMENT
            </span>

            <h2>
                Guests & VIP
            </h2>

        </div>

        <span class="result-badge">
            <?= count($guests) ?>
        </span>

    </div>


    <?php if ($guests): ?>

        <div class="search-results-list">

            <?php foreach ($guests as $guest): ?>

                <a
                    href="<?= BASE_URL ?>/guests.php?event_id=<?= (int)$guest['event_id'] ?>"
                    class="search-result-item"
                >

                    <div class="result-icon guest-icon">
                        G
                    </div>

                    <div class="result-content">

                        <strong>
                            <?= e($guest['name']) ?>
                        </strong>

                        <span>

                            <?= e(
                                $guest['designation']
                                ?: 'Guest'
                            ) ?>

                            <?php if (!empty($guest['organization'])): ?>

                                · <?= e($guest['organization']) ?>

                            <?php endif; ?>

                        </span>

                        <small>

                            Event:
                            <?= e(
                                $guest['event_name']
                                ?? 'Unknown Event'
                            ) ?>

                        </small>

                    </div>

                    <div class="result-meta">

                        <span class="badge neutral">
                            <?= e($guest['priority_level']) ?>
                        </span>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            No matching guests.
        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     DUTIES
====================================================== -->

<section class="panel search-result-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                DUTY MANAGEMENT
            </span>

            <h2>
                Duties
            </h2>

        </div>

        <span class="result-badge">
            <?= count($duties) ?>
        </span>

    </div>


    <?php if ($duties): ?>

        <div class="search-results-list">

            <?php foreach ($duties as $duty): ?>

                <a
                    href="<?= BASE_URL ?>/duties.php?event_id=<?= (int)$duty['event_id'] ?>"
                    class="search-result-item"
                >

                    <div class="result-icon duty-icon">
                        D
                    </div>

                    <div class="result-content">

                        <strong>
                            <?= e($duty['title']) ?>
                        </strong>

                        <span>

                            Event:
                            <?= e(
                                $duty['event_name']
                                ?? 'Unknown Event'
                            ) ?>

                        </span>

                        <small>

                            <?php if (!empty($duty['assigned_name'])): ?>

                                Assigned to:
                                <?= e($duty['assigned_name']) ?>

                            <?php else: ?>

                                Unassigned

                            <?php endif; ?>

                            <?php if (!empty($duty['due_at'])): ?>

                                · Due:
                                <?= e($duty['due_at']) ?>

                            <?php endif; ?>

                        </small>

                    </div>

                    <div class="result-meta">

                        <span class="badge neutral">
                            <?= e($duty['status']) ?>
                        </span>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            No matching duties.
        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     PROTOCOL
====================================================== -->

<section class="panel search-result-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                PROTOCOL
            </span>

            <h2>
                Protocol Checklist
            </h2>

        </div>

        <span class="result-badge">
            <?= count($protocol) ?>
        </span>

    </div>


    <?php if ($protocol): ?>

        <div class="search-results-list">

            <?php foreach ($protocol as $item): ?>

                <a
                    href="<?= BASE_URL ?>/protocol.php?event_id=<?= (int)$item['event_id'] ?>"
                    class="search-result-item"
                >

                    <div class="result-icon protocol-icon">
                        P
                    </div>

                    <div class="result-content">

                        <strong>
                            <?= e($item['item']) ?>
                        </strong>

                        <span>
                            Event:
                            <?= e(
                                $item['event_name']
                                ?? 'Unknown Event'
                            ) ?>
                        </span>

                        <small>

                            <?= e(
                                $item['instructions']
                                ?: 'No additional instructions'
                            ) ?>

                        </small>

                    </div>

                    <div class="result-meta">

                        <?php if ((int)$item['completed'] === 1): ?>

                            <span class="badge success">
                                Completed
                            </span>

                        <?php else: ?>

                            <span class="badge warning">
                                Pending
                            </span>

                        <?php endif; ?>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            No matching protocol items.
        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     INVITATIONS
====================================================== -->

<section class="panel search-result-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                INVITATIONS
            </span>

            <h2>
                Invitations
            </h2>

        </div>

        <span class="result-badge">
            <?= count($invitations) ?>
        </span>

    </div>


    <?php if ($invitations): ?>

        <div class="search-results-list">

            <?php foreach ($invitations as $invitation): ?>

                <a
                    href="<?= BASE_URL ?>/invitations.php"
                    class="search-result-item"
                >

                    <div class="result-icon invitation-icon">
                        I
                    </div>

                    <div class="result-content">

                        <strong>
                            <?= e($invitation['guest_name']) ?>
                        </strong>

                        <span>
                            <?= e($invitation['guest_email']) ?>
                        </span>

                        <small>
                            Event:
                            <?= e(
                                $invitation['event_name']
                                ?? 'Unknown Event'
                            ) ?>
                        </small>

                    </div>

                    <div class="result-meta">

                        <span class="badge neutral">
                            <?= e($invitation['status']) ?>
                        </span>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            No matching invitations.
        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     TRANSPORT
====================================================== -->

<section class="panel search-result-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                TRANSPORT
            </span>

            <h2>
                Transport Records
            </h2>

        </div>

        <span class="result-badge">
            <?= count($transport) ?>
        </span>

    </div>


    <?php if ($transport): ?>

        <div class="search-results-list">

            <?php foreach ($transport as $vehicle): ?>

                <a
                    href="<?= BASE_URL ?>/transport.php?event_id=<?= (int)$vehicle['event_id'] ?>"
                    class="search-result-item"
                >

                    <div class="result-icon transport-icon">
                        T
                    </div>

                    <div class="result-content">

                        <strong>
                            <?= e($vehicle['vehicle_no']) ?>
                        </strong>

                        <span>

                            <?= e(
                                $vehicle['guest_name']
                                ?: 'Transport Record'
                            ) ?>

                            <?php if (!empty($vehicle['driver_name'])): ?>

                                · Driver:
                                <?= e($vehicle['driver_name']) ?>

                            <?php endif; ?>

                        </span>

                        <small>

                            Event:
                            <?= e(
                                $vehicle['event_name']
                                ?? 'Unknown Event'
                            ) ?>

                        </small>

                    </div>

                    <?php if (!empty($vehicle['parking_slot'])): ?>

                        <div class="result-meta">

                            <span class="badge neutral">

                                Parking
                                <?= e(
                                    $vehicle['parking_slot']
                                ) ?>

                            </span>

                        </div>

                    <?php endif; ?>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            No matching transport records.
        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     EMERGENCY CONTACTS
====================================================== -->

<section class="panel search-result-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                SAFETY & RESPONSE
            </span>

            <h2>
                Emergency Contacts
            </h2>

        </div>

        <span class="result-badge">
            <?= count($emergency) ?>
        </span>

    </div>


    <?php if ($emergency): ?>

        <div class="search-results-list">

            <?php foreach ($emergency as $contact): ?>

                <a
                    href="<?= BASE_URL ?>/emergency.php"
                    class="search-result-item"
                >

                    <div class="result-icon emergency-icon">
                        !
                    </div>

                    <div class="result-content">

                        <strong>
                            <?= e($contact['name']) ?>
                        </strong>

                        <span>

                            <?= e(
                                $contact['designation']
                                ?: 'Emergency Contact'
                            ) ?>

                            <?php if (!empty($contact['department'])): ?>

                                · <?= e($contact['department']) ?>

                            <?php endif; ?>

                        </span>

                        <small>
                            <?= e($contact['phone']) ?>
                        </small>

                    </div>

                    <div class="result-meta">

                        <span class="badge danger">
                            Emergency
                        </span>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            No matching emergency contacts.
        </div>

    <?php endif; ?>

</section>


<!-- =====================================================
     ANNOUNCEMENTS
====================================================== -->

<section class="panel search-result-panel">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                COMMUNICATION
            </span>

            <h2>
                Announcements
            </h2>

        </div>

        <span class="result-badge">
            <?= count($announcements) ?>
        </span>

    </div>


    <?php if ($announcements): ?>

        <div class="search-results-list">

            <?php foreach ($announcements as $announcement): ?>

                <a
                    href="<?= BASE_URL ?>/notifications.php"
                    class="search-result-item"
                >

                    <div class="result-icon announcement-icon">
                        N
                    </div>

                    <div class="result-content">

                        <strong>
                            <?= e($announcement['title']) ?>
                        </strong>

                        <span>

                            <?= e(
                                mb_strimwidth(
                                    $announcement['message'],
                                    0,
                                    110,
                                    '...'
                                )
                            ) ?>

                        </span>

                        <small>

                            Published by
                            <?= e(
                                $announcement['publisher_name']
                                ?? 'System'
                            ) ?>

                            ·

                            <?= e(
                                $announcement['created_at']
                            ) ?>

                        </small>

                    </div>

                </a>

            <?php endforeach; ?>

        </div>

    <?php else: ?>

        <div class="empty">
            No matching announcements.
        </div>

    <?php endif; ?>

</section>


<?php endif; ?>
<?php endif; ?>


<style>

/* =====================================================
   GLOBAL SEARCH
===================================================== */

.global-search-panel {
    margin-bottom: 18px;
}

.global-search-form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 9px;
    align-items: center;
}

.search-input-wrap {
    position: relative;
}

.search-input-wrap input {
    width: 100%;
    padding-left: 43px;
    min-height: 48px;
}

.search-icon {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 20px;
    pointer-events: none;
}

.search-summary {
    margin-top: 15px;
    padding-top: 14px;
    border-top: 1px solid rgba(148,163,184,.14);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    color: var(--muted);
    font-size: 12px;
}

.search-result-count,
.result-badge {
    padding: 5px 9px;
    border-radius: 999px;
    background: rgba(31, 78, 121, .1);
    color: var(--blue);
    font-size: 11px;
    font-weight: 800;
}

.search-hint {
    margin-top: 14px;
    padding: 13px 14px;
    border-radius: 10px;
    background: rgba(148,163,184,.05);
    color: var(--muted);
    font-size: 12px;
}

.search-hint strong {
    display: block;
    color: var(--text);
    margin-bottom: 4px;
}


/* =====================================================
   SEARCH STATS
===================================================== */

.search-stat-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    gap: 9px;
    margin-bottom: 18px;
}

.search-stat {
    padding: 13px;
    border: 1px solid rgba(148,163,184,.13);
    border-radius: 12px;
    background: var(--card);
}

.search-stat span {
    display: block;
    color: var(--muted);
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.search-stat strong {
    display: block;
    margin-top: 5px;
    font-size: 21px;
}


/* =====================================================
   RESULT PANELS
===================================================== */

.search-result-panel {
    margin-bottom: 18px;
}

.search-results-list {
    display: flex;
    flex-direction: column;
}

.search-result-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 13px 4px;
    color: inherit;
    text-decoration: none;
    border-bottom: 1px solid rgba(148,163,184,.11);
    transition: .16s ease;
}

.search-result-item:last-child {
    border-bottom: 0;
}

.search-result-item:hover {
    padding-left: 8px;
    background: rgba(148,163,184,.035);
}

.result-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: grid;
    place-items: center;
    border-radius: 11px;
    font-size: 13px;
    font-weight: 900;
}

.event-icon {
    background: rgba(31,78,121,.1);
    color: var(--blue);
}

.guest-icon {
    background: rgba(22,163,74,.1);
    color: var(--green);
}

.duty-icon {
    background: rgba(234,179,8,.12);
    color: #a16207;
}

.protocol-icon {
    background: rgba(124,58,237,.1);
    color: #7c3aed;
}

.invitation-icon {
    background: rgba(14,116,144,.1);
    color: #0e7490;
}

.transport-icon {
    background: rgba(8,145,178,.1);
    color: #0891b2;
}

.emergency-icon {
    background: rgba(239,68,68,.1);
    color: var(--red);
}

.announcement-icon {
    background: rgba(99,102,241,.1);
    color: #6366f1;
}

.result-content {
    min-width: 0;
    flex: 1;
}

.result-content strong {
    display: block;
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.result-content span {
    display: block;
    margin-top: 3px;
    color: var(--muted);
    font-size: 11px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.result-content small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 10px;
}

.result-meta {
    flex: 0 0 auto;
}


/* =====================================================
   NO RESULT
===================================================== */

.search-no-results {
    text-align: center;
    padding: 50px 20px;
}

.no-result-icon {
    width: 58px;
    height: 58px;
    margin: 0 auto 13px;
    display: grid;
    place-items: center;
    border-radius: 16px;
    background: rgba(148,163,184,.08);
    color: var(--muted);
    font-size: 27px;
}

.search-no-results h2 {
    margin: 0;
}

.search-no-results p {
    color: var(--muted);
    font-size: 13px;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 1200px) {

    .search-stat-grid {
        grid-template-columns: repeat(4, 1fr);
    }

}

@media (max-width: 900px) {

    .global-search-form {
        grid-template-columns: 1fr 1fr;
    }

    .search-input-wrap {
        grid-column: 1 / -1;
    }

    .emergency-layout {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 720px) {

    .global-search-form {
        grid-template-columns: 1fr;
    }

    .search-input-wrap {
        grid-column: auto;
    }

    .global-search-form .btn {
        width: 100%;
    }

    .search-summary {
        align-items: flex-start;
        flex-direction: column;
    }

    .search-stat-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .search-result-item {
        align-items: flex-start;
    }

    .result-meta {
        display: none;
    }

}

</style>


<?php require __DIR__ . '/includes/footer.php'; ?>