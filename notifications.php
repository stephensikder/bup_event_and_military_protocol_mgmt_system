<?php

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_login();

$page_title = 'Announcements | BUP Protocol';

// =====================================================
// HANDLE POST ACTIONS (MongoDB Atlas)
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // -------------------------------------------------
    // PUBLISH ANNOUNCEMENT
    // -------------------------------------------------
    if ($action === 'publish') {
        if (is_staff()) {
            flash('error', 'Staff members cannot publish announcements.');
            redirect(BASE_URL . '/notifications.php');
        }

        $title = trim($_POST['title'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($title === '' || $message === '') {
            flash('error', 'Title and message are required.');
            redirect(BASE_URL . '/notifications.php');
        }

        if (mb_strlen($title) > 200) {
            flash('error', 'Announcement title is too long.');
            redirect(BASE_URL . '/notifications.php');
        }

        try {
            $announcementId = mongo_next_id('announcements');
            $currentUser = current_user();
            $userId = (int)($currentUser['id'] ?? 0);
            $now = date('Y-m-d H:i:s');

            mcoll('announcements')->insertOne([
                'id' => $announcementId,
                'title' => $title,
                'message' => $message,
                'created_by' => $userId,
                'created_at' => $now
            ]);

            mongo_audit_log(
                $userId,
                'Announcement Published',
                'Announcement #' . $announcementId . ' "' . $title . '" was published.'
            );

            flash('success', 'Announcement published successfully.');
        } catch (Throwable $e) {
            flash('error', 'Unable to publish announcement: ' . $e->getMessage());
        }

        redirect(BASE_URL . '/notifications.php');
    }

    // -------------------------------------------------
    // DELETE ANNOUNCEMENT
    // -------------------------------------------------
    if ($action === 'delete') {
        if (is_staff()) {
            flash('error', 'Staff members cannot delete announcements.');
            redirect(BASE_URL . '/notifications.php');
        }

        $id = (int)($_POST['id'] ?? 0);

        if (!$id) {
            flash('error', 'Invalid announcement.');
            redirect(BASE_URL . '/notifications.php');
        }

        $announcementDoc = mcoll('announcements')->findOne(['id' => $id]);

        if (!$announcementDoc) {
            flash('error', 'Announcement not found.');
            redirect(BASE_URL . '/notifications.php');
        }

        $announcement = mongo_doc_to_array($announcementDoc);

        try {
            mcoll('announcements')->deleteOne(['id' => $id]);

            mongo_audit_log(
                current_user()['id'],
                'Announcement Deleted',
                'Announcement #' . $announcement['id'] . ' "' . ($announcement['title'] ?? '') . '" was deleted.'
            );

            flash('success', 'Announcement deleted successfully.');
        } catch (Throwable $e) {
            flash('error', 'Unable to delete announcement.');
        }

        redirect(BASE_URL . '/notifications.php');
    }
}

// =====================================================
// SEARCH
// =====================================================
$search = trim($_GET['q'] ?? '');

// =====================================================
// ANNOUNCEMENT STATISTICS (MongoDB Atlas)
// =====================================================
$todayPrefix = date('Y-m-d');
$weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

$distinctPublishers = mcoll('announcements')->distinct('created_by', [
    'created_by' => ['$ne' => null, '$exists' => true]
]);

$stats = [
    'total' => mcoll('announcements')->countDocuments(),
    'today' => mcoll('announcements')->countDocuments(['created_at' => ['$regex' => '^' . preg_quote($todayPrefix, '/')]]),
    'this_week' => mcoll('announcements')->countDocuments(['created_at' => ['$gte' => $weekAgo]]),
    'publishers' => count(array_filter($distinctPublishers, fn($v) => !is_null($v) && $v !== ''))
];

// =====================================================
// FETCH ANNOUNCEMENTS (MongoDB Atlas Aggregation Pipeline)
// =====================================================
$pipeline = [
    [
        '$lookup' => [
            'from' => 'users',
            'localField' => 'created_by',
            'foreignField' => 'id',
            'as' => 'pub_user'
        ]
    ],
    [
        '$unwind' => [
            'path' => '$pub_user',
            'preserveNullAndEmptyArrays' => true
        ]
    ]
];

if ($search !== '') {
    $searchRegex = new MongoDB\BSON\Regex(preg_quote($search, '/'), 'i');
    $pipeline[] = [
        '$match' => [
            '$or' => [
                ['title' => $searchRegex],
                ['message' => $searchRegex],
                ['pub_user.name' => $searchRegex]
            ]
        ]
    ];
}

$pipeline[] = [
    '$sort' => [
        'created_at' => -1,
        'id' => -1
    ]
];

$cursor = mcoll('announcements')->aggregate($pipeline);
$rows = [];
foreach ($cursor as $doc) {
    $row = mongo_doc_to_array($doc);
    $row['publisher'] = $row['pub_user']['name'] ?? null;
    $row['publisher_role'] = $row['pub_user']['role'] ?? null;
    unset($row['pub_user']);
    $rows[] = $row;
}

require __DIR__ . '/includes/header.php';

?>


<div class="page-head">

    <div>

        <span class="eyebrow">
            COMMUNICATION
        </span>

        <h1>
            Announcements & Notifications
        </h1>

        <p>
            Publish event notices and maintain a centralized communication center.
        </p>

    </div>

</div>


<!-- =====================================================
     STATISTICS
====================================================== -->

<div class="stat-grid notification-stats">

    <div class="stat-card">

        <span>Total Notices</span>

        <strong>
            <?= $stats['total'] ?>
        </strong>

        <em>
            All published announcements
        </em>

    </div>


    <div class="stat-card">

        <span>Today</span>

        <strong>
            <?= $stats['today'] ?>
        </strong>

        <em>
            Published today
        </em>

    </div>


    <div class="stat-card">

        <span>Last 7 Days</span>

        <strong>
            <?= $stats['this_week'] ?>
        </strong>

        <em>
            Recent communication activity
        </em>

    </div>


    <div class="stat-card">

        <span>Publishers</span>

        <strong>
            <?= $stats['publishers'] ?>
        </strong>

        <em>
            Active contributors
        </em>

    </div>

</div>


<!-- =====================================================
     MAIN GRID
====================================================== -->

<div class="notification-layout">


    <!-- =================================================
         ANNOUNCEMENT REGISTER
    ================================================== -->

    <section class="panel announcement-panel">

        <div class="panel-head">

            <div>

                <span class="eyebrow">
                    NOTIFICATION CENTER
                </span>

                <h2>
                    Published Notices
                </h2>

            </div>

            <span class="record-count">
                <?= count($rows) ?>
                notice<?= count($rows) !== 1 ? 's' : '' ?>
            </span>

        </div>


        <!-- SEARCH -->

        <form
            method="get"
            class="notification-search"
        >

            <input
                type="search"
                name="q"
                value="<?= e($search) ?>"
                placeholder="Search announcements..."
            >

            <button class="btn btn-dark">
                Search
            </button>


            <?php if ($search !== ''): ?>

                <a
                    href="<?= BASE_URL ?>/notifications.php"
                    class="btn btn-light"
                >
                    Reset
                </a>

            <?php endif; ?>

        </form>


        <!-- NOTICES -->

        <?php if ($rows): ?>

            <div class="announcement-list">

                <?php foreach ($rows as $r): ?>

                    <article class="announcement-card">


                        <!-- HEADER -->

                        <div class="announcement-card-head">

                            <div class="announcement-icon">
                                !
                            </div>


                            <div class="announcement-title">

                                <h3>
                                    <?= e($r['title']) ?>
                                </h3>

                                <div class="announcement-meta">

                                    <span>
                                        <?= e(
                                            date(
                                                'd M Y',
                                                strtotime(
                                                    $r['created_at']
                                                )
                                            )
                                        ) ?>
                                    </span>

                                    <span>
                                        <?= e(
                                            date(
                                                'h:i A',
                                                strtotime(
                                                    $r['created_at']
                                                )
                                            )
                                        ) ?>
                                    </span>

                                    <span>
                                        <?= e(
                                            $r['publisher']
                                            ?? 'System'
                                        ) ?>
                                    </span>

                                </div>

                            </div>


                            <!-- DELETE -->
                            <?php if (!is_staff() && (has_role('Admin') || is_event_director() || (int)$r['created_by'] === (int)current_user()['id'])): ?>
                            <form
                                method="post"
                                class="announcement-delete"
                                onsubmit="return confirm('Delete this announcement?')"
                            >
                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete"
                                >
                                <input
                                    type="hidden"
                                    name="id"
                                    value="<?= (int)$r['id'] ?>"
                                >
                                <button
                                    type="submit"
                                    class="btn btn-sm btn-danger"
                                >
                                    Delete
                                </button>
                            </form>
                            <?php endif; ?>

                        </div>


                        <!-- MESSAGE -->

                        <div class="announcement-message">

                            <?= nl2br(
                                e($r['message'])
                            ) ?>

                        </div>


                        <!-- FOOTER -->

                        <div class="announcement-footer">

                            <span>

                                Published by
                                <strong>
                                    <?= e(
                                        $r['publisher']
                                        ?? 'System'
                                    ) ?>
                                </strong>

                                <?php if (!empty($r['publisher_role'])): ?>

                                    ·
                                    <?= e(
                                        $r['publisher_role']
                                    ) ?>

                                <?php endif; ?>

                            </span>


                            <span class="announcement-status">
                                Published
                            </span>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty">

                <strong>
                    No announcements found
                </strong>

                <p>

                    <?php if ($search !== ''): ?>

                        No notice matched your search.

                    <?php else: ?>

                        Publish your first announcement from the panel.

                    <?php endif; ?>

                </p>

            </div>

        <?php endif; ?>

    </section>


    <!-- =================================================
         PUBLISH FORM
    ================================================== -->

    <?php if (!is_staff()): ?>
    <section class="panel form-panel publish-panel">

        <div class="panel-head">

            <div>

                <span class="eyebrow">
                    COMMUNICATION
                </span>

                <h2>
                    Publish Notice
                </h2>

                <p>
                    Create a new announcement for the system notification center.
                </p>

            </div>

        </div>


        <form method="post">

            <input
                type="hidden"
                name="action"
                value="publish"
            >


            <label>

                Notice Title

                <input
                    type="text"
                    name="title"
                    maxlength="200"
                    placeholder="Enter announcement title"
                    required
                >

                <small class="field-hint">
                    Maximum 200 characters.
                </small>

            </label>


            <label>

                Message

                <textarea
                    name="message"
                    id="announcementMessage"
                    rows="9"
                    maxlength="5000"
                    placeholder="Write the announcement message..."
                    required
                ></textarea>

                <div class="character-counter">

                    <span id="messageCount">
                        0
                    </span>

                    / 5000

                </div>

            </label>


            <div class="publish-info">

                <strong>
                    Publishing Notice
                </strong>

                <p>
                    Your name and role will be recorded with this announcement.
                </p>

            </div>


            <button
                type="submit"
                class="btn btn-primary btn-lg"
            >
                Publish Announcement
            </button>

        </form>

    </section>
    <?php endif; ?>

</div>


<style>

/* =========================================
   MAIN LAYOUT
========================================= */

.notification-layout {
    display: grid;
    grid-template-columns:
        minmax(0, 1.45fr)
        minmax(320px, .75fr);
    gap: 18px;
    margin-top: 18px;
}

.announcement-panel,
.publish-panel {
    min-width: 0;
}


/* =========================================
   SEARCH
========================================= */

.notification-search {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
}

.notification-search input {
    flex: 1;
    min-width: 0;
}

.record-count {
    color: var(--muted);
    font-size: 12px;
}


/* =========================================
   ANNOUNCEMENT LIST
========================================= */

.announcement-list {
    display: grid;
    gap: 13px;
}

.announcement-card {
    padding: 18px;
    border-radius: 14px;
    border: 1px solid rgba(148,163,184,.15);
    background: rgba(148,163,184,.035);
    transition: .18s ease;
}

.announcement-card:hover {
    border-color: rgba(201,163,76,.4);
    transform: translateY(-1px);
}


/* =========================================
   CARD HEADER
========================================= */

.announcement-card-head {
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.announcement-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    border-radius: 11px;
    display: grid;
    place-items: center;
    background: rgba(201,163,76,.13);
    color: var(--gold);
    font-weight: 900;
    font-size: 18px;
}

.announcement-title {
    flex: 1;
    min-width: 0;
}

.announcement-title h3 {
    margin: 0;
    font-size: 16px;
}

.announcement-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 6px;
    color: var(--muted);
    font-size: 11px;
}

.announcement-meta span {
    display: inline-flex;
    align-items: center;
}

.announcement-meta span + span::before {
    content: "•";
    margin-right: 7px;
    opacity: .5;
}

.announcement-delete {
    margin: 0;
}


/* =========================================
   MESSAGE
========================================= */

.announcement-message {
    margin-top: 15px;
    padding-left: 50px;
    color: var(--text);
    line-height: 1.7;
    font-size: 14px;
}


/* =========================================
   FOOTER
========================================= */

.announcement-footer {
    margin-top: 16px;
    padding-top: 12px;
    border-top: 1px solid rgba(148,163,184,.12);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    color: var(--muted);
    font-size: 11px;
}

.announcement-footer strong {
    color: inherit;
}

.announcement-status {
    display: inline-flex;
    padding: 5px 9px;
    border-radius: 999px;
    background: rgba(34,197,94,.1);
    color: #16a34a;
    font-weight: 800;
}


/* =========================================
   FORM
========================================= */

.publish-panel {
    align-self: start;
}

.field-hint {
    display: block;
    margin-top: 5px;
    color: var(--muted);
    font-size: 11px;
}

.character-counter {
    text-align: right;
    color: var(--muted);
    font-size: 11px;
    margin-top: 5px;
}

.publish-info {
    margin: 5px 0 16px;
    padding: 13px;
    border-radius: 11px;
    background: rgba(148,163,184,.06);
    border: 1px solid rgba(148,163,184,.12);
}

.publish-info strong {
    display: block;
    font-size: 12px;
}

.publish-info p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
}


/* =========================================
   RESPONSIVE
========================================= */

@media (max-width: 1000px) {

    .notification-layout {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 720px) {

    .notification-search {
        flex-direction: column;
    }

    .notification-search .btn {
        width: 100%;
    }

    .announcement-card-head {
        flex-wrap: wrap;
    }

    .announcement-delete {
        width: 100%;
    }

    .announcement-delete .btn {
        width: 100%;
    }

    .announcement-message {
        padding-left: 0;
    }

    .announcement-footer {
        align-items: flex-start;
        flex-direction: column;
    }

}

</style>


<script>

const messageInput =
    document.getElementById(
        'announcementMessage'
    );

const messageCount =
    document.getElementById(
        'messageCount'
    );


function updateMessageCount() {

    if (!messageInput || !messageCount) {
        return;
    }

    messageCount.textContent =
        messageInput.value.length;
}


if (messageInput) {

    messageInput.addEventListener(
        'input',
        updateMessageCount
    );

    updateMessageCount();
}

</script>


<style>
/* ==========================================
   NOTIFICATIONS — CORRECT CARD SPACING
========================================== */

.notification-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 22px;
    margin: 28px 0 34px;
}

.notification-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.65fr) minmax(320px, 0.9fr);
    align-items: start;
    gap: 28px;
    margin: 0 0 30px;
}

.notification-layout > .panel {
    min-width: 0;
    margin: 0;
    border-radius: 20px;
    overflow: hidden;
    box-sizing: border-box;
}

.notification-layout .panel-head {
    padding: 24px 28px;
}

/* Search form */
.notification-search {
    display: flex;
    align-items: stretch;
    gap: 12px;
    padding: 16px 20px 22px;
    margin: 0;
}

.notification-search input {
    flex: 1;
    min-width: 0;
    width: 100%;
    min-height: 54px;
    box-sizing: border-box;
}

.notification-search button,
.notification-search .btn {
    flex-shrink: 0;
    min-height: 54px;
}

/* Space between notice cards */
.announcement-list {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 20px;
    padding: 0 20px 22px;
}

.announcement-card {
    min-width: 0;
    width: 100%;
    margin: 0;
    padding: 24px;
    box-sizing: border-box;
    border-radius: 18px;
    border: 1px solid #e2e8f0;
    background: #fff;
}

.announcement-card-head {
    display: flex;
    align-items: flex-start;
    gap: 14px;
}

.announcement-title {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}

.announcement-title h3 {
    margin: 0;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.announcement-delete {
    flex-shrink: 0;
}

.announcement-message {
    margin-top: 18px;
    padding-left: 52px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}

.announcement-footer {
    margin-top: 20px;
    padding-top: 15px;
}

/* Publish form */
.notification-layout .publish-panel {
    padding: 0;
    align-self: start;
}

.publish-panel > .panel-head {
    padding: 24px 28px;
}

.publish-panel form {
    display: flex;
    flex-direction: column;
    gap: 22px;
    padding: 26px 28px 30px;
}

.publish-panel form label {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0;
}

.publish-panel form input,
.publish-panel form textarea {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

.publish-panel form textarea {
    min-height: 180px;
}

/* Tablet */
@media (max-width: 1000px) {

    .notification-layout {
        grid-template-columns: minmax(0, 1fr);
        gap: 24px;
    }

    .notification-layout > .panel {
        width: 100%;
    }
}

/* Mobile */
@media (max-width: 720px) {

    .notification-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
        margin: 20px 0 24px;
    }

    .notification-layout {
        gap: 20px;
    }

    .notification-layout .panel-head,
    .publish-panel > .panel-head {
        padding: 20px;
    }

    .notification-search {
        flex-direction: column;
        padding: 14px;
    }

    .notification-search button,
    .notification-search .btn {
        width: 100%;
    }

    .announcement-list {
        gap: 14px;
        padding: 0 12px 16px;
    }

    .announcement-card {
        padding: 18px;
    }

    .announcement-card-head {
        flex-wrap: wrap;
    }

    .announcement-delete {
        margin-left: auto;
    }

    .announcement-message {
        padding-left: 0;
    }

    .announcement-footer {
        align-items: flex-start;
        flex-direction: column;
    }

    .publish-panel form {
        gap: 18px;
        padding: 20px;
    }
}

@media (max-width: 420px) {

    .notification-stats {
        gap: 10px;
    }

    .announcement-card {
        padding: 15px;
    }

    .announcement-list {
        padding-right: 10px;
        padding-left: 10px;
    }
}
</style>


<?php require __DIR__ . '/includes/footer.php'; ?>