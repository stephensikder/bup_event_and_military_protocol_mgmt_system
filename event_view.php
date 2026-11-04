<?php

require_once __DIR__ . '/config/auth.php';

require_login();

$pdo = db();

$id = (int) ($_GET['id'] ?? 0);


/*
|--------------------------------------------------------------------------
| Event Information
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "SELECT
        e.*,
        u.name AS creator
     FROM events e
     LEFT JOIN users u
        ON u.id = e.created_by
     WHERE e.id = ?"
);

$stmt->execute([$id]);

$event = $stmt->fetch();


if (!$event) {

    flash(
        'error',
        'Event not found.'
    );

    redirect(
        BASE_URL . '/events.php'
    );
}


/*
|--------------------------------------------------------------------------
| Guests
|--------------------------------------------------------------------------
*/

$guest_stmt = $pdo->prepare(
    "SELECT *
     FROM guests
     WHERE event_id = ?
     ORDER BY priority_level DESC, name"
);

$guest_stmt->execute([$id]);

$guests = $guest_stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Duties
|--------------------------------------------------------------------------
*/

$duty_stmt = $pdo->prepare(
    "SELECT
        d.*,
        u.name AS assignee
     FROM duties d
     LEFT JOIN users u
        ON u.id = d.assigned_to
     WHERE d.event_id = ?
     ORDER BY d.status, d.due_at"
);

$duty_stmt->execute([$id]);

$duties = $duty_stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Protocol Checklist
|--------------------------------------------------------------------------
*/

$protocol_stmt = $pdo->prepare(
    "SELECT *
     FROM protocol_items
     WHERE event_id = ?
     ORDER BY sort_order, id"
);

$protocol_stmt->execute([$id]);

$protocol = $protocol_stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| Summary Counts
|--------------------------------------------------------------------------
*/

$total_guests = count($guests);

$total_duties = count($duties);

$completed_duties = 0;

foreach ($duties as $duty) {

    if (strtolower($duty['status']) === 'completed') {
        $completed_duties++;
    }
}


$total_protocol = count($protocol);

$completed_protocol = 0;

foreach ($protocol as $item) {

    if (!empty($item['completed'])) {
        $completed_protocol++;
    }
}


/*
|--------------------------------------------------------------------------
| Progress Percentages
|--------------------------------------------------------------------------
*/

$duty_progress = $total_duties > 0
    ? round(($completed_duties / $total_duties) * 100)
    : 0;

$protocol_progress = $total_protocol > 0
    ? round(($completed_protocol / $total_protocol) * 100)
    : 0;


/*
|--------------------------------------------------------------------------
| Page Title
|--------------------------------------------------------------------------
*/

$page_title = 'Event Details | ' . $event['name'];

require __DIR__ . '/includes/header.php';

?>


<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="page-head">

    <div>

        <span class="eyebrow">
            EVENT DETAILS
        </span>

        <h1>
            <?= e($event['name']) ?>
        </h1>

        <p>
            <?= e($event['description']) ?>
        </p>

    </div>


    <div
        style="
            display:flex;
            gap:9px;
            flex-wrap:wrap;
        "
    >

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/events.php"
        >
            ← Events
        </a>

        <?php if (can_create_events()): ?>
        <a
            class="btn btn-primary"
            href="<?= BASE_URL ?>/events.php?edit=<?= (int) $event['id'] ?>#edit-event"
        >
            Edit Event
        </a>
        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     EVENT HERO
========================================================= -->

<div class="detail-hero">

    <div>

        <span
            class="badge <?= e(strtolower($event['status'])) ?>"
        >
            <?= e($event['status']) ?>
        </span>


        <h2>
            <?= e($event['event_type']) ?> Event
        </h2>


        <p>
            <?= e($event['event_date']) ?>

            ·

            <?= e(substr($event['event_time'], 0, 5)) ?>

            ·

            <?= e($event['venue']) ?>
        </p>

    </div>


    <div class="detail-owner">

        <small>
            Created by
        </small>

        <strong>
            <?= e($event['creator'] ?? 'System') ?>
        </strong>

    </div>

</div>


<!-- =========================================================
     EVENT SUMMARY
========================================================= -->

<div class="stat-grid">

    <div class="stat-card">

        <span>
            Registered Guests
        </span>

        <strong>
            <?= $total_guests ?>
        </strong>

        <em>
            Guest & VIP records
        </em>

    </div>


    <div class="stat-card">

        <span>
            Total Duties
        </span>

        <strong>
            <?= $total_duties ?>
        </strong>

        <em>
            <?= $completed_duties ?> completed
        </em>

    </div>


    <div class="stat-card">

        <span>
            Protocol Tasks
        </span>

        <strong>
            <?= $total_protocol ?>
        </strong>

        <em>
            <?= $completed_protocol ?> completed
        </em>

    </div>


    <div class="stat-card">

        <span>
            Protocol Progress
        </span>

        <strong>
            <?= $protocol_progress ?>%
        </strong>

        <em>
            Checklist completion
        </em>

    </div>

</div>


<!-- =========================================================
     MAIN EVENT OPERATIONS
========================================================= -->

<div class="three-grid">


    <!-- =====================================================
         GUESTS & VIP
    ====================================================== -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>
                    Guests & VIP
                </h2>

                <p>
                    <?= $total_guests ?> registered
                </p>

            </div>

            <a
                href="<?= BASE_URL ?>/guests.php?event_id=<?= (int) $id ?>"
            >
                Manage
            </a>

        </div>


        <?php foreach (array_slice($guests, 0, 6) as $guest): ?>

            <div class="mini-row">

                <span class="avatar">

                    <?= e(
                        strtoupper(
                            substr(
                                $guest['name'],
                                0,
                                1
                            )
                        )
                    ) ?>

                </span>


                <div>

                    <strong>
                        <?= e($guest['name']) ?>
                    </strong>

                    <small>
                        <?= e($guest['designation']) ?>
                    </small>

                </div>


                <span class="badge neutral">

                    <?= e($guest['priority_level']) ?>

                </span>

            </div>

        <?php endforeach; ?>


        <?php if (!$guests): ?>

            <div class="empty">
                No guests registered for this event.
            </div>

        <?php elseif ($total_guests > 6): ?>

            <div
                style="
                    padding:12px 16px;
                    text-align:center;
                    border-top:1px solid #edf0f3;
                "
            >

                <a
                    class="table-link"
                    href="<?= BASE_URL ?>/guests.php?event_id=<?= (int) $id ?>"
                >
                    View all <?= $total_guests ?> guests →
                </a>

            </div>

        <?php endif; ?>

    </section>


    <!-- =====================================================
         DUTIES
    ====================================================== -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>
                    Duty Status
                </h2>

                <p>
                    <?= $total_duties ?> duties
                </p>

            </div>

            <a
                href="<?= BASE_URL ?>/duties.php?event_id=<?= (int) $id ?>"
            >
                Manage
            </a>

        </div>


        <?php foreach (array_slice($duties, 0, 6) as $duty): ?>

            <?php

            $duty_status = strtolower(
                trim($duty['status'])
            );

            ?>

            <div class="mini-row">

                <div>

                    <strong>
                        <?= e($duty['title']) ?>
                    </strong>

                    <small>
                        <?= e(
                            $duty['assignee'] ?? 'Unassigned'
                        ) ?>
                    </small>

                </div>


                <span
                    class="badge <?= e($duty_status) ?>"
                >
                    <?= e($duty['status']) ?>
                </span>

            </div>

        <?php endforeach; ?>


        <?php if (!$duties): ?>

            <div class="empty">
                No duties assigned yet.
            </div>

        <?php elseif ($total_duties > 6): ?>

            <div
                style="
                    padding:12px 16px;
                    text-align:center;
                    border-top:1px solid #edf0f3;
                "
            >

                <a
                    class="table-link"
                    href="<?= BASE_URL ?>/duties.php?event_id=<?= (int) $id ?>"
                >
                    View all <?= $total_duties ?> duties →
                </a>

            </div>

        <?php endif; ?>

    </section>


    <!-- =====================================================
         PROTOCOL
    ====================================================== -->

    <section class="panel">

        <div class="panel-head">

            <div>

                <h2>
                    Protocol Checklist
                </h2>

                <p>
                    <?= $completed_protocol ?>
                    /
                    <?= $total_protocol ?>
                    complete
                </p>

            </div>

            <a
                href="<?= BASE_URL ?>/protocol.php?event_id=<?= (int) $id ?>"
            >
                Manage
            </a>

        </div>


        <?php if ($total_protocol > 0): ?>

            <div style="padding:15px 16px 5px;">

                <div
                    style="
                        display:flex;
                        justify-content:space-between;
                        margin-bottom:7px;
                        font-size:9px;
                        color:#7f8b98;
                    "
                >

                    <span>
                        Completion
                    </span>

                    <strong>
                        <?= $protocol_progress ?>%
                    </strong>

                </div>


                <div class="progress">

                    <span
                        style="
                            width:<?= $protocol_progress ?>%;
                        "
                    ></span>

                </div>

            </div>

        <?php endif; ?>


        <?php foreach ($protocol as $item): ?>

            <div class="check-row">

                <span
                    class="check <?= !empty($item['completed']) ? 'done' : '' ?>"
                >
                    ✓
                </span>


                <span
                    class="<?= !empty($item['completed']) ? 'strike' : '' ?>"
                >
                    <?= e($item['item']) ?>
                </span>

            </div>

        <?php endforeach; ?>


        <?php if (!$protocol): ?>

            <div class="empty">
                No protocol items yet.
            </div>

        <?php endif; ?>

    </section>

</div>


<!-- =========================================================
     EVENT INFORMATION
========================================================= -->

<section
    class="panel"
    style="margin-top:20px;"
>

    <div class="panel-head">

        <div>

            <h2>
                Event Information
            </h2>

            <p>
                Official event details
            </p>

        </div>

    </div>


    <div
        style="
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:1px;
            background:#edf0f3;
        "
    >

        <div
            style="
                background:#fff;
                padding:18px;
            "
        >

            <small
                style="
                    display:block;
                    color:#8b96a4;
                    font-size:9px;
                    text-transform:uppercase;
                    letter-spacing:.6px;
                "
            >
                Event Type
            </small>

            <strong
                style="
                    display:block;
                    margin-top:5px;
                    font-size:12px;
                "
            >
                <?= e($event['event_type']) ?>
            </strong>

        </div>


        <div
            style="
                background:#fff;
                padding:18px;
            "
        >

            <small
                style="
                    display:block;
                    color:#8b96a4;
                    font-size:9px;
                    text-transform:uppercase;
                    letter-spacing:.6px;
                "
            >
                Date
            </small>

            <strong
                style="
                    display:block;
                    margin-top:5px;
                    font-size:12px;
                "
            >
                <?= e($event['event_date']) ?>
            </strong>

        </div>


        <div
            style="
                background:#fff;
                padding:18px;
            "
        >

            <small
                style="
                    display:block;
                    color:#8b96a4;
                    font-size:9px;
                    text-transform:uppercase;
                    letter-spacing:.6px;
                "
            >
                Time
            </small>

            <strong
                style="
                    display:block;
                    margin-top:5px;
                    font-size:12px;
                "
            >
                <?= e(substr($event['event_time'], 0, 5)) ?>
            </strong>

        </div>


        <div
            style="
                background:#fff;
                padding:18px;
            "
        >

            <small
                style="
                    display:block;
                    color:#8b96a4;
                    font-size:9px;
                    text-transform:uppercase;
                    letter-spacing:.6px;
                "
            >
                Venue
            </small>

            <strong
                style="
                    display:block;
                    margin-top:5px;
                    font-size:12px;
                "
            >
                <?= e($event['venue']) ?>
            </strong>

        </div>

    </div>

</section>


<!-- =========================================================
     EVENT ACTIONS
========================================================= -->

<section
    class="panel"
    style="margin-top:20px;"
>

    <div class="panel-head">

        <div>

            <h2>
                Event Operations
            </h2>

            <p>
                Manage related event activities
            </p>

        </div>

    </div>


    <div
        style="
            display:grid;
            grid-template-columns:repeat(4,1fr);
            gap:10px;
            padding:20px;
        "
    >

        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/guests.php?event_id=<?= (int) $id ?>"
        >
            Guests & VIP
        </a>


        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/duties.php?event_id=<?= (int) $id ?>"
        >
            Duties
        </a>


        <a
            class="btn btn-light"
            href="<?= BASE_URL ?>/protocol.php?event_id=<?= (int) $id ?>"
        >
            Protocol
        </a>


        <?php if (can_create_events()): ?>
        <a
            class="btn btn-primary"
            href="<?= BASE_URL ?>/events.php?edit=<?= (int) $id ?>#edit-event"
        >
            Edit Event
        </a>
        <?php endif; ?>

    </div>

</section>


<!-- =========================================================
     RESPONSIVE
========================================================= -->

<style>

@media (max-width: 850px) {

    .stat-grid {
        grid-template-columns:1fr 1fr;
    }

    .panel > div[style*="repeat(4"] {
        grid-template-columns:1fr 1fr !important;
    }

}

@media (max-width: 600px) {

    .stat-grid {
        grid-template-columns:1fr;
    }

    .panel > div[style*="repeat(4"] {
        grid-template-columns:1fr !important;
    }

}

</style>


<?php require __DIR__ . '/includes/footer.php'; ?>