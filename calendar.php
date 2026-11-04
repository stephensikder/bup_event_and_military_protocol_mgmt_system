
<?php
require_once __DIR__ . '/config/auth.php';
require_login();

$pdo = db();
$page_title = 'Event Calendar | BUP Protocol';

/* =========================================================
   MONTH SELECTION
========================================================= */

$month = $_GET['month'] ?? date('Y-m');

if (
    !is_string($month) ||
    !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
) {
    $month = date('Y-m');
}

$month_timestamp = strtotime($month . '-01');

if ($month_timestamp === false) {
    $month = date('Y-m');
    $month_timestamp = strtotime($month . '-01');
}

$year = (int) date('Y', $month_timestamp);
$month_number = (int) date('n', $month_timestamp);
$month_name = date('F Y', $month_timestamp);
$days_in_month = (int) date('t', $month_timestamp);

/* Sunday = 0, Saturday = 6 */
$first_day = (int) date('w', $month_timestamp);

$previous_month = date(
    'Y-m',
    strtotime('-1 month', $month_timestamp)
);

$next_month = date(
    'Y-m',
    strtotime('+1 month', $month_timestamp)
);

$current_month = date('Y-m');
$today_date = date('Y-m-d');

/* =========================================================
   FILTERS
========================================================= */

$status_options = [
    'Upcoming',
    'Ongoing',
    'Completed',
    'Cancelled'
];

$type_options = [
    'Academic',
    'Official',
    'Military',
    'Ceremonial',
    'Seminar',
    'Other'
];

$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';

if (
    !is_string($status_filter) ||
    !in_array($status_filter, $status_options, true)
) {
    $status_filter = '';
}

if (
    !is_string($type_filter) ||
    !in_array($type_filter, $type_options, true)
) {
    $type_filter = '';
}

/* =========================================================
   FETCH EVENTS
========================================================= */

$sql = "
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
    WHERE event_date >= ?
    AND event_date < ?
";

$params = [
    date('Y-m-d', $month_timestamp),
    date(
        'Y-m-d',
        strtotime('+1 month', $month_timestamp)
    )
];

if ($status_filter !== '') {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
}

if ($type_filter !== '') {
    $sql .= " AND event_type = ?";
    $params[] = $type_filter;
}

$sql .= "
    ORDER BY
        event_date ASC,
        event_time ASC,
        name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

/* =========================================================
   GROUP EVENTS BY DATE
========================================================= */

$events_by_date = [];

foreach ($events as $event) {
    $date_key = $event['event_date'];

    if (!isset($events_by_date[$date_key])) {
        $events_by_date[$date_key] = [];
    }

    $events_by_date[$date_key][] = $event;
}

/* =========================================================
   MONTHLY STATISTICS
========================================================= */

$total_events = count($events);

$upcoming_count = 0;
$ongoing_count = 0;
$completed_count = 0;
$cancelled_count = 0;
$priority_count = 0;

foreach ($events as $event) {

    switch ($event['status']) {

        case 'Upcoming':
            $upcoming_count++;
            break;

        case 'Ongoing':
            $ongoing_count++;
            break;

        case 'Completed':
            $completed_count++;
            break;

        case 'Cancelled':
            $cancelled_count++;
            break;
    }

    if (!empty($event['priority'])) {
        $priority_count++;
    }
}

/* =========================================================
   CALENDAR GRID
========================================================= */

$total_cells = $first_day + $days_in_month;

$remaining = (7 - ($total_cells % 7)) % 7;

/* =========================================================
   URL BUILDER
========================================================= */

function calendar_url(
    string $month,
    string $status = '',
    string $type = ''
): string {

    $query = ['month' => $month];

    if ($status !== '') {
        $query['status'] = $status;
    }

    if ($type !== '') {
        $query['type'] = $type;
    }

    return BASE_URL . '/calendar.php?' .
        http_build_query($query);
}

require __DIR__ . '/includes/header.php';
?>

<!-- ======================================================
     PAGE HEADER
====================================================== -->

<div class="page-head calendar-page-head">

    <div>
        <span class="eyebrow">SCHEDULE</span>

        <h1>Event Calendar</h1>

        <p>
            View scheduled events, operational activities
            and priority events by date.
        </p>
    </div>

    <?php if (can_create_events()): ?>
    <a
        href="<?= BASE_URL ?>/events.php"
        class="btn btn-primary"
    >
        + Create Event
    </a>
    <?php endif; ?>

</div>

<!-- ======================================================
     STATISTICS
====================================================== -->

<div class="stat-grid calendar-summary">

    <div class="stat-card">
        <span>This Month</span>
        <strong><?= $total_events ?></strong>
        <em>Scheduled events</em>
    </div>

    <div class="stat-card">
        <span>Upcoming</span>
        <strong><?= $upcoming_count ?></strong>
        <em>Future events</em>
    </div>

    <div class="stat-card">
        <span>Ongoing</span>
        <strong><?= $ongoing_count ?></strong>
        <em>Currently active</em>
    </div>

    <div class="stat-card">
        <span>Priority</span>
        <strong><?= $priority_count ?></strong>
        <em>High-priority events</em>
    </div>

</div>

<!-- ======================================================
     CALENDAR PANEL
====================================================== -->

<section class="panel calendar-panel">

    <!-- MONTH NAVIGATION -->

    <div class="calendar-toolbar">

        <div class="calendar-toolbar-info">

            <span class="eyebrow">MONTH VIEW</span>

            <h2><?= e($month_name) ?></h2>

            <p>
                <?= $total_events ?>
                <?= $total_events === 1 ? 'event' : 'events' ?>
                scheduled this month.
            </p>

        </div>

        <div class="calendar-navigation">

            <a
                class="btn btn-light btn-sm"
                href="<?= e(calendar_url(
                    $previous_month,
                    $status_filter,
                    $type_filter
                )) ?>"
            >
                ← Previous
            </a>

            <a
                class="btn btn-dark btn-sm"
                href="<?= e(calendar_url(
                    $current_month,
                    $status_filter,
                    $type_filter
                )) ?>"
            >
                Today
            </a>

            <a
                class="btn btn-light btn-sm"
                href="<?= e(calendar_url(
                    $next_month,
                    $status_filter,
                    $type_filter
                )) ?>"
            >
                Next →
            </a>

        </div>

    </div>

    <!-- FILTER BAR -->

    <div class="calendar-filter">

        <form method="get" class="calendar-filter-form">

            <div class="calendar-filter-field">

                <label for="calendar-month">
                    Select Month
                </label>

                <input
                    type="month"
                    id="calendar-month"
                    name="month"
                    value="<?= e($month) ?>"
                >

            </div>

            <div class="calendar-filter-field">

                <label for="calendar-type">
                    Event Type
                </label>

                <select id="calendar-type" name="type">

                    <option value="">
                        All Event Types
                    </option>

                    <?php foreach ($type_options as $type): ?>

                        <option
                            value="<?= e($type) ?>"
                            <?= $type_filter === $type
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($type) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="calendar-filter-field">

                <label for="calendar-status">
                    Status
                </label>

                <select id="calendar-status" name="status">

                    <option value="">
                        All Status
                    </option>

                    <?php foreach ($status_options as $status): ?>

                        <option
                            value="<?= e($status) ?>"
                            <?= $status_filter === $status
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($status) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="calendar-filter-actions">

                <button class="btn btn-dark" type="submit">
                    Apply Filter
                </button>

                <a
                    class="btn btn-light"
                    href="<?= e(calendar_url($month)) ?>"
                >
                    Reset
                </a>

            </div>

        </form>

    </div>

    <!-- WEEKDAY HEADER -->

    <div class="calendar-weekdays">

        <div>Sun</div>
        <div>Mon</div>
        <div>Tue</div>
        <div>Wed</div>
        <div>Thu</div>
        <div>Fri</div>
        <div>Sat</div>

    </div>

    <!-- MONTH GRID -->

    <div class="calendar-grid">

        <!-- EMPTY DAYS BEFORE MONTH START -->

        <?php for ($i = 0; $i < $first_day; $i++): ?>

            <div
                class="calendar-day calendar-empty"
                aria-hidden="true"
            ></div>

        <?php endfor; ?>

        <!-- ALL DAYS OF THE MONTH -->

        <?php for (
            $day = 1;
            $day <= $days_in_month;
            $day++
        ): ?>

            <?php

            $date_key = sprintf(
                '%04d-%02d-%02d',
                $year,
                $month_number,
                $day
            );

            $day_events = $events_by_date[$date_key] ?? [];

            $is_today = $date_key === $today_date;

            ?>

            <div
                class="calendar-day
                    <?= $is_today ? 'calendar-today' : '' ?>
                    <?= $day_events ? 'has-events' : '' ?>"
            >

                <!-- DATE NUMBER -->

                <div class="calendar-day-head">

                    <span
                        class="calendar-day-number
                        <?= $is_today ? 'today-number' : '' ?>"
                    >
                        <?= $day ?>
                    </span>

                    <?php if ($day_events): ?>

                        <span class="calendar-event-count">

                            <?= count($day_events) ?>

                            <?= count($day_events) === 1
                                ? 'event'
                                : 'events' ?>

                        </span>

                    <?php endif; ?>

                </div>

                <!-- SHORT EVENT CHIPS -->

                <div class="calendar-events">

                    <?php foreach ($day_events as $event): ?>

                        <?php

                        $status_class = strtolower(
                            (string) $event['status']
                        );

                        $event_time = !empty($event['event_time'])
                            ? substr(
                                (string) $event['event_time'],
                                0,
                                5
                            )
                            : '';

                        $tooltip = $event['name'];

                        if ($event_time !== '') {
                            $tooltip .= ' · ' . $event_time;
                        }

                        if (!empty($event['venue'])) {
                            $tooltip .= ' · ' . $event['venue'];
                        }

                        if (!empty($event['priority'])) {
                            $tooltip .= ' · High priority';
                        }

                        ?>

                        <a
                            href="<?= BASE_URL ?>/event_view.php?id=<?= (int)$event['id'] ?>"
                            class="calendar-event-chip
                                status-<?= e($status_class) ?>
                                <?= !empty($event['priority'])
                                    ? 'priority-chip'
                                    : '' ?>"
                            title="<?= e($tooltip) ?>"
                            aria-label="<?= e($tooltip) ?>"
                        >

                            <span class="chip-title">
                                <?= e($event['name']) ?>
                            </span>

                            <?php if (!empty($event['priority'])): ?>

                                <span class="chip-priority">
                                    ●
                                </span>

                            <?php endif; ?>

                        </a>

                    <?php endforeach; ?>

                </div>

            </div>

        <?php endfor; ?>

        <!-- EMPTY DAYS AFTER MONTH END -->

        <?php for ($i = 0; $i < $remaining; $i++): ?>

            <div
                class="calendar-day calendar-empty"
                aria-hidden="true"
            ></div>

        <?php endfor; ?>

    </div>

    <!-- LEGEND -->

    <div class="calendar-legend">

        <span>
            <i class="legend-dot upcoming"></i>
            Upcoming
        </span>

        <span>
            <i class="legend-dot ongoing"></i>
            Ongoing
        </span>

        <span>
            <i class="legend-dot completed"></i>
            Completed
        </span>

        <span>
            <i class="legend-dot cancelled"></i>
            Cancelled
        </span>

        <span>
            <i class="legend-dot priority"></i>
            Priority
        </span>

    </div>

</section>

<!-- ======================================================
     MONTHLY EVENT REGISTER
====================================================== -->

<section class="panel monthly-register">

    <div class="panel-head">

        <div>

            <span class="eyebrow">
                EVENT REGISTER
            </span>

            <h2>Monthly Event Register</h2>

            <p>
                All events scheduled for
                <?= e($month_name) ?>.
            </p>

        </div>

        <a
            class="table-link"
            href="<?= BASE_URL ?>/events.php"
        >
            View All Events
        </a>

    </div>

    <div class="table-wrap">

        <table>

            <thead>
                <tr>
                    <th>Event</th>
                    <th>Date &amp; Time</th>
                    <th>Venue</th>
                    <th>Priority</th>
                    <th>Status</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($events as $event): ?>

                    <tr>

                        <td>

                            <a
                                class="table-link"
                                href="<?= BASE_URL ?>/event_view.php?id=<?= (int)$event['id'] ?>"
                            >
                                <strong>
                                    <?= e($event['name']) ?>
                                </strong>
                            </a>

                            <small>
                                <?= e($event['event_type']) ?>
                            </small>

                        </td>

                        <td>

                            <?= e(date(
                                'd M Y',
                                strtotime($event['event_date'])
                            )) ?>

                            <small>
                                <?= e(
                                    !empty($event['event_time'])
                                        ? substr(
                                            (string)$event['event_time'],
                                            0,
                                            5
                                        )
                                        : '—'
                                ) ?>
                            </small>

                        </td>

                        <td>
                            <?= e($event['venue'] ?? '—') ?>
                        </td>

                        <td>

                            <?php if (!empty($event['priority'])): ?>

                                <span class="priority">
                                    High
                                </span>

                            <?php else: ?>

                                <span class="muted-text">
                                    Normal
                                </span>

                            <?php endif; ?>

                        </td>

                        <td>

                            <span
                                class="badge <?= e(
                                    strtolower(
                                        (string)$event['status']
                                    )
                                ) ?>"
                            >
                                <?= e($event['status']) ?>
                            </span>

                        </td>

                    </tr>

                <?php endforeach; ?>

                <?php if (!$events): ?>

                    <tr>
                        <td colspan="5" class="empty">
                            No events scheduled for this month.
                        </td>
                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>

<!-- ======================================================
     CALENDAR PAGE CSS
====================================================== -->

<style>

/* Page */

.calendar-page-head {
    margin-bottom: 22px;
}

.calendar-summary {
    margin-bottom: 22px;
}

/* Calendar Panel */

.calendar-panel {
    overflow: hidden;
}

/* Toolbar */

.calendar-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 22px 24px 18px;
    border-bottom: 1px solid #e8edf2;
}

.calendar-toolbar-info h2 {
    margin: 5px 0;
    color: var(--navy);
    font-size: 22px;
}

.calendar-toolbar-info p {
    margin: 0;
    color: #7a8794;
    font-size: 12px;
}

.calendar-navigation {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

/* Filter */

.calendar-filter {
    padding: 15px 20px;
    background: #fafbfc;
    border-bottom: 1px solid #e8edf2;
}

.calendar-filter-form {
    display: grid;
    grid-template-columns:
        minmax(0, 1fr)
        minmax(0, 1fr)
        minmax(0, 1fr)
        auto;
    gap: 12px;
    align-items: end;
}

.calendar-filter-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.calendar-filter-field label {
    margin: 0;
    font-size: 11px;
    font-weight: 700;
    color: #667482;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.calendar-filter-field select,
.calendar-filter-field input {
    width: 100%;
    box-sizing: border-box;
}

.calendar-filter-actions {
    display: flex;
    gap: 8px;
}

/* Weekdays */

.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    background: #f7f9fc;
    border-left: 1px solid #e4eaf1;
    border-top: 1px solid #e4eaf1;
}

.calendar-weekdays > div {
    padding: 12px 4px;
    text-align: center;
    border-right: 1px solid #e4eaf1;
    border-bottom: 1px solid #e4eaf1;
    color: #52647a;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
}

/* Main Grid */

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 0;
    border-left: 1px solid #e4eaf1;
    background: #ffffff;
}

/* Date Cells */

.calendar-day {
    position: relative;
    min-width: 0;
    min-height: 120px;
    padding: 9px;
    background: #ffffff;
    border-right: 1px solid #e4eaf1;
    border-bottom: 1px solid #e4eaf1;
    overflow: hidden;
    box-sizing: border-box;
}

.calendar-day:hover {
    background: #fbfcfe;
}

.calendar-day.calendar-empty {
    background: #f5f7fa;
}

.calendar-today {
    background: #f0f5ff;
}

.calendar-today:hover {
    background: #f0f5ff;
}

/* Date Header */

.calendar-day-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 5px;
    min-height: 27px;
    margin-bottom: 8px;
}

.calendar-day-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 27px;
    width: 27px;
    height: 27px;
    border-radius: 50%;
    color: #334155;
    font-size: 13px;
    font-weight: 800;
}

.today-number {
    background: var(--navy);
    color: #ffffff;
}

.calendar-event-count {
    font-size: 9px;
    color: #8793a0;
    font-weight: 700;
    white-space: nowrap;
}

/* Event Chip Container */

.calendar-events {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-width: 0;
}

/* Short Event Chips */

.calendar-event-chip {
    display: flex;
    align-items: center;
    gap: 5px;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
    padding: 5px 7px;
    border-radius: 4px;
    color: #ffffff;
    text-decoration: none;
    font-size: 10px;
    font-weight: 650;
    line-height: 1.35;
    overflow: hidden;
    transition:
        filter .15s ease,
        transform .15s ease;
}

.calendar-event-chip:hover {
    filter: brightness(.92);
    transform: translateY(-1px);
    color: #ffffff;
}

.chip-title {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}

.chip-priority {
    flex: 0 0 auto;
    color: #ffe08a;
    font-size: 8px;
}

/* Event Status Colors */

.calendar-event-chip.status-upcoming {
    background: #0876a8;
}

.calendar-event-chip.status-ongoing {
    background: #167341;
}

.calendar-event-chip.status-completed {
    background: #52647a;
}

.calendar-event-chip.status-cancelled {
    background: #a83e46;
}

/* Priority Event */

.calendar-event-chip.priority-chip {
    box-shadow:
        inset 0 0 0 1px rgba(255, 224, 138, .45);
}

/* Legend */

.calendar-legend {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 16px;
    flex-wrap: wrap;
    padding: 14px 20px;
    border-top: 1px solid #e5e9ed;
    background: #fafbfc;
}

.calendar-legend span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #687580;
    font-size: 10px;
    font-weight: 700;
}

.legend-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.legend-dot.upcoming {
    background: #0876a8;
}

.legend-dot.ongoing {
    background: #167341;
}

.legend-dot.completed {
    background: #52647a;
}

.legend-dot.cancelled {
    background: #a83e46;
}

.legend-dot.priority {
    background: #d49b18;
}

/* Monthly Register */

.monthly-register {
    margin-top: 20px;
}

.monthly-register td small {
    display: block;
    margin-top: 3px;
    color: #8a96a1;
}

.muted-text {
    color: #8a96a1;
}

/* Tablet */

@media (max-width: 1000px) {

    .calendar-filter-form {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .calendar-day {
        min-height: 105px;
        padding: 7px;
    }

    .calendar-event-chip {
        padding: 4px 5px;
        font-size: 9px;
    }

}

/* Small Screens */

@media (max-width: 760px) {

    .calendar-toolbar {
        align-items: flex-start;
        flex-direction: column;
    }

    .calendar-navigation {
        width: 100%;
    }

    .calendar-navigation .btn {
        flex: 1;
        text-align: center;
    }

    .calendar-filter-form {
        grid-template-columns: 1fr;
    }

    .calendar-filter-actions {
        width: 100%;
    }

    .calendar-filter-actions .btn {
        flex: 1;
        text-align: center;
    }

    .calendar-day {
        min-height: 82px;
        padding: 4px;
    }

    .calendar-weekdays > div {
        padding: 9px 2px;
        font-size: 9px;
    }

    .calendar-day-head {
        margin-bottom: 5px;
    }

    .calendar-event-count {
        display: none;
    }

    .calendar-event-chip {
        padding: 4px;
        font-size: 8px;
        gap: 3px;
    }

    .calendar-legend {
        justify-content: flex-start;
        gap: 10px;
    }

}

/* Mobile */

@media (max-width: 520px) {

    .calendar-day {
        min-height: 66px;
        padding: 3px;
    }

    .calendar-day-number {
        width: 22px;
        height: 22px;
        flex-basis: 22px;
        font-size: 10px;
    }

    .calendar-weekdays > div {
        font-size: 8px;
    }

    .calendar-event-chip {
        font-size: 7px;
        padding: 3px;
    }

    .calendar-legend {
        padding: 10px;
    }

}

</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
