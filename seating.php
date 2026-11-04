<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';
require_login();

if (is_student()) {
    flash('error', 'Students can view seating status on their dedicated student dashboard.');
    redirect(BASE_URL . '/student_dashboard.php');
}

if (is_security_lead() || is_logistics_lead() || is_communication_lead()) {
    flash('error', 'Access restricted: Seating management is not permitted for your department lead role.');
    redirect(BASE_URL . '/dashboard.php');
}

$page_title = 'Auditorium Seating Plan | BUP Protocol';

$redirectTo = static function (int $eventId): void {
    redirect(BASE_URL . '/seating.php?event_id=' . $eventId);
};

/* =========================================================
   VENUE LAYOUT ARCHITECTURE GENERATOR
   Creates distinct auditorium layouts based on venue type
========================================================= */
function get_venue_layout(string $venue_name): array {
    $v = strtolower(trim($venue_name));

    // 1. BUP Grand Auditorium (Flagship 3-Block Auditorium)
    if (str_contains($v, 'grand') || str_contains($v, 'auditorium')) {
        return [
            'type' => 'grand_auditorium',
            'title' => 'BUP Grand Auditorium',
            'subtitle' => 'Main Tiered Hall · 3 Blocks (Left Wing, Center Stalls, Right Wing)',
            'stage_label' => 'S T A G E &nbsp;&bull;&nbsp; P O D I U M',
            'stage_sub' => 'ম ঞ্চ &nbsp;ও&nbsp; মূ ল &nbsp;ডা য়া স',
            'sections' => [
                [
                    'name' => 'VIP & Dignitaries Tier (Front Stalls)',
                    'type' => 'VIP',
                    'color' => '#d97706',
                    'tag' => 'VIP RESERVED',
                    'rows' => [
                        ['row' => 'A', 'left' => ['A01','A02','A03'], 'center' => ['A04','A05','A06','A07','A08','A09','A10','A11','A12','A13'], 'right' => ['A14','A15','A16']],
                        ['row' => 'B', 'left' => ['B01','B02','B03'], 'center' => ['B04','B05','B06','B07','B08','B09','B10','B11','B12','B13'], 'right' => ['B14','B15','B16']],
                    ]
                ],
                [
                    'name' => 'Executive & Distinguished Stalls',
                    'type' => 'Executive',
                    'color' => '#2563eb',
                    'tag' => 'EXECUTIVE',
                    'rows' => [
                        ['row' => 'C', 'left' => ['C01','C02','C03','C04'], 'center' => ['C05','C06','C07','C08','C09','C10','C11','C12','C13','C14'], 'right' => ['C15','C16','C17','C18']],
                        ['row' => 'D', 'left' => ['D01','D02','D03','D04'], 'center' => ['D05','D06','D07','D08','D09','D10','D11','D12','D13','D14'], 'right' => ['D15','D16','D17','D18']],
                        ['row' => 'E', 'left' => ['E01','E02','E03','E04'], 'center' => ['E05','E06','E07','E08','E09','E10','E11','E12','E13','E14'], 'right' => ['E15','E16','E17','E18']],
                    ]
                ],
                [
                    'name' => 'General Delegates & Officers Gallery',
                    'type' => 'General',
                    'color' => '#64748b',
                    'tag' => 'GENERAL',
                    'rows' => [
                        ['row' => 'F', 'left' => ['F01','F02','F03','F04'], 'center' => ['F05','F06','F07','F08','F09','F10','F11','F12','F13','F14'], 'right' => ['F15','F16','F17','F18']],
                        ['row' => 'G', 'left' => ['G01','G02','G03','G04'], 'center' => ['G05','G06','G07','G08','G09','G10','G11','G12','G13','G14'], 'right' => ['G15','G16','G17','G18']],
                        ['row' => 'H', 'left' => ['H01','H02','H03','H04'], 'center' => ['H05','H06','H07','H08','H09','H10','H11','H12','H13','H14'], 'right' => ['H15','H16','H17','H18']],
                        ['row' => 'J', 'left' => ['J01','J02','J03','J04'], 'center' => ['J05','J06','J07','J08','J09','J10','J11','J12','J13','J14'], 'right' => ['J15','J16','J17','J18']],
                    ]
                ]
            ]
        ];
    }

    // 2. Seminar Hall A (2-Block Tiered Hall with Central Red-Carpet Aisle)
    if (str_contains($v, 'seminar') || str_contains($v, 'hall a') || str_contains($v, 'symposium')) {
        return [
            'type' => 'seminar_hall',
            'title' => 'Seminar Hall A',
            'subtitle' => 'Tiered Symposium Hall · 2 Blocks with Central Carpeted Aisle',
            'stage_label' => 'P R E S E N T A T I O N &nbsp;&bull;&nbsp; S C R E E N',
            'stage_sub' => 'প্রে জে ন্ট টে শ ন &nbsp;স্ক্রি ন &nbsp;ও&nbsp; ডা য়া স',
            'sections' => [
                [
                    'name' => 'Keynote & Faculty Front Rows (Rows A–B)',
                    'type' => 'VIP',
                    'color' => '#d97706',
                    'tag' => 'KEYNOTE & FACULTY',
                    'rows' => [
                        ['row' => 'A', 'left' => ['A01','A02','A03','A04','A05'], 'right' => ['A06','A07','A08','A09','A10']],
                        ['row' => 'B', 'left' => ['B01','B02','B03','B04','B05'], 'right' => ['B06','B07','B08','B09','B10']],
                    ]
                ],
                [
                    'name' => 'Seminar Participant Stalls (Rows C–G)',
                    'type' => 'General',
                    'color' => '#2563eb',
                    'tag' => 'PARTICIPANTS',
                    'rows' => [
                        ['row' => 'C', 'left' => ['C01','C02','C03','C04','C05','C06'], 'right' => ['C07','C08','C09','C10','C11','C12']],
                        ['row' => 'D', 'left' => ['D01','D02','D03','D04','D05','D06'], 'right' => ['D07','D08','D09','D10','D11','D12']],
                        ['row' => 'E', 'left' => ['E01','E02','E03','E04','E05','E06'], 'right' => ['E07','E08','E09','E10','E11','E12']],
                        ['row' => 'F', 'left' => ['F01','F02','F03','F04','F05','F06'], 'right' => ['F07','F08','F09','F10','F11','F12']],
                        ['row' => 'G', 'left' => ['G01','G02','G03','G04','G05','G06'], 'right' => ['G07','G08','G09','G10','G11','G12']],
                    ]
                ]
            ]
        ];
    }

    // 3. Protocol Lounge / Banquet (Diplomatic High-Dais Table + Delegation Flanks)
    if (str_contains($v, 'protocol') || str_contains($v, 'lounge') || str_contains($v, 'banquet')) {
        return [
            'type' => 'protocol_lounge',
            'title' => 'VIP Protocol Lounge',
            'subtitle' => 'High-Dais & Diplomatic Delegation Layout',
            'stage_label' => 'H I G H &nbsp;&bull;&nbsp; D A I S &nbsp;&bull;&nbsp; T A B L E',
            'stage_sub' => 'প্র ধা ন &nbsp;অ তি থি &nbsp;ও&nbsp; রা ষ্ট্র দূ ত &nbsp;আ স ন',
            'sections' => [
                [
                    'name' => 'Chief Guest & Head Table (Dais)',
                    'type' => 'VIP',
                    'color' => '#d97706',
                    'tag' => 'HEAD TABLE (DAIS)',
                    'rows' => [
                        ['row' => 'Dais', 'center' => ['D01','D02','D03','D04','D05','D06']]
                    ]
                ],
                [
                    'name' => 'Diplomatic Delegation Rows (Flanks)',
                    'type' => 'Executive',
                    'color' => '#7c3aed',
                    'tag' => 'DELEGATION FLANKS',
                    'rows' => [
                        ['row' => 'A', 'left' => ['A01','A02','A03','A04'], 'right' => ['A05','A06','A07','A08']],
                        ['row' => 'B', 'left' => ['B01','B02','B03','B04'], 'right' => ['B05','B06','B07','B08']],
                        ['row' => 'C', 'left' => ['C01','C02','C03','C04'], 'right' => ['C05','C06','C07','C08']],
                    ]
                ],
                [
                    'name' => 'Protocol Staff & Media Observers',
                    'type' => 'General',
                    'color' => '#059669',
                    'tag' => 'OBSERVERS & ESCORT',
                    'rows' => [
                        ['row' => 'P', 'left' => ['P01','P02','P03'], 'center' => ['P04','P05','P06','P07'], 'right' => ['P08','P09','P10']],
                    ]
                ]
            ]
        ];
    }

    // 4. Default Auditorium (Balanced 3-Block Auditorium matching user sample)
    return [
        'type' => 'standard_auditorium',
        'title' => $venue_name ?: 'Main Auditorium',
        'subtitle' => '3-Column Stalls Layout with Walkways',
        'stage_label' => 'S T A G E &nbsp;&bull;&nbsp; S C R E E N',
        'stage_sub' => 'মূ ল &nbsp;ম ঞ্চ &nbsp;ও&nbsp; প র্দা',
        'sections' => [
            [
                'name' => 'Front VIP Tier (Rows A–B)',
                'type' => 'VIP',
                'color' => '#d97706',
                'tag' => 'VIP RESERVED',
                'rows' => [
                    ['row' => 'A', 'left' => ['A01','A02','A03'], 'center' => ['A04','A05','A06','A07','A08','A09','A10'], 'right' => ['A11','A12','A13']],
                    ['row' => 'B', 'left' => ['B01','B02','B03'], 'center' => ['B04','B05','B06','B07','B08','B09','B10'], 'right' => ['B11','B12','B13']],
                ]
            ],
            [
                'name' => 'Main Hall Stalls (Rows C–F)',
                'type' => 'General',
                'color' => '#2563eb',
                'tag' => 'GENERAL',
                'rows' => [
                    ['row' => 'C', 'left' => ['C01','C02','C03'], 'center' => ['C04','C05','C06','C07','C08','C09','C10'], 'right' => ['C11','C12','C13']],
                    ['row' => 'D', 'left' => ['D01','D02','D03'], 'center' => ['D04','D05','D06','D07','D08','D09','D10'], 'right' => ['D11','D12','D13']],
                    ['row' => 'E', 'left' => ['E01','E02','E03'], 'center' => ['E04','E05','E06','E07','E08','E09','E10'], 'right' => ['E11','E12','E13']],
                    ['row' => 'F', 'left' => ['F01','F02','F03'], 'center' => ['F04','F05','F06','F07','F08','F09','F10'], 'right' => ['F11','F12','F13']],
                ]
            ]
        ]
    ];
}

/* =========================================================
   LOAD EVENTS (MongoDB Atlas)
========================================================= */
$eventsCursor = mcoll('events')->find(
    ['status' => ['$ne' => 'Completed']],
    ['sort' => ['event_date' => -1, 'event_time' => -1]]
);
$events = [];
foreach ($eventsCursor as $eDoc) {
    $events[] = mongo_doc_to_array($eDoc);
}

$selected = (int)($_GET['event_id'] ?? ($events[0]['id'] ?? 0));
$selectedEvent = null;

foreach ($events as $event) {
    if ((int)$event['id'] === $selected) {
        $selectedEvent = $event;
        break;
    }
}

if (!$selectedEvent && !empty($events)) {
    $selected = (int)$events[0]['id'];
    $selectedEvent = $events[0];
}

// Generate the venue layout for the active event
$venueLayout = $selectedEvent ? get_venue_layout($selectedEvent['venue'] ?? '') : null;

// Build map of all valid seats in this venue layout
$allVenueSeats = [];
$vipSeatsMap = [];
if ($venueLayout) {
    foreach ($venueLayout['sections'] as $sec) {
        $isVip = ($sec['type'] === 'VIP');
        foreach ($sec['rows'] as $r) {
            foreach (['left', 'center', 'right'] as $blk) {
                if (!empty($r[$blk])) {
                    foreach ($r[$blk] as $seatCode) {
                        $allVenueSeats[$seatCode] = [
                            'code' => $seatCode,
                            'row' => $r['row'],
                            'section' => $sec['name'],
                            'type' => $sec['type'],
                            'is_vip' => $isVip
                        ];
                        if ($isVip) {
                            $vipSeatsMap[$seatCode] = true;
                        }
                    }
                }
            }
        }
    }
}

/* =========================================================
   POST ACTION HANDLERS (ASSIGN, UNASSIGN, AUTO-ASSIGN, CLEAR)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_manage_seating()) {
        flash('error', 'You do not have permission to manage seating plans.');
        redirect(BASE_URL . '/seating.php?event_id=' . $selected);
    }

    if (($selectedEvent['mode'] ?? '') === 'Online') {
        flash('error', 'Physical seating is disabled for Online-only events.');
        redirect(BASE_URL . '/seating.php?event_id=' . $selected);
    }

    $action = $_POST['action'] ?? '';
    $eventId = (int)($_POST['event_id'] ?? 0);

    if ($eventId !== $selected) {
        flash('error', 'Event context mismatch.');
        redirect(BASE_URL . '/seating.php?event_id=' . $selected);
    }

    // 1. Assign single seat
    if ($action === 'assign') {
        $guestId = (int)($_POST['guest_id'] ?? 0);
        $seatCode = trim($_POST['seat_code'] ?? '');
        $sectionName = trim($_POST['section_name'] ?? 'Main Hall');
        $seatType = trim($_POST['seat_type'] ?? 'General');

        if ($guestId < 1 || $seatCode === '') {
            flash('error', 'Please select both a valid guest and seat code.');
            $redirectTo($eventId);
        }

        // Verify guest belongs to event
        $guestDoc = mcoll('guests')->findOne(['id' => $guestId, 'event_id' => $eventId]);

        if (!$guestDoc) {
            flash('error', 'Guest does not belong to this event.');
            $redirectTo($eventId);
        }
        $guest = mongo_doc_to_array($guestDoc);

        // Extract numeric portion if any
        preg_match('/\d+/', $seatCode, $numMatch);
        $seatNumber = !empty($numMatch[0]) ? (int)$numMatch[0] : 0;

        try {
            // Delete any existing seat assignment for this guest (reassignment)
            mcoll('seating_assignments')->deleteMany(['event_id' => $eventId, 'guest_id' => $guestId]);

            // Delete any existing occupant of this specific seat
            mcoll('seating_assignments')->deleteMany(['event_id' => $eventId, 'seat_code' => $seatCode]);

            // Insert new assignment
            $assignId = mongo_next_id('seating_assignments');
            mcoll('seating_assignments')->insertOne([
                'id' => $assignId,
                'event_id' => $eventId,
                'guest_id' => $guestId,
                'seat_code' => $seatCode,
                'seat_number' => $seatNumber,
                'section_name' => $sectionName,
                'seat_type' => $seatType,
                'assigned_by' => current_user()['id'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            mongo_audit_log('Seat Assigned', "Assigned {$guest['name']} to seat {$seatCode}");

            flash('success', "Assigned {$guest['name']} to Seat {$seatCode} successfully!");
        } catch (Throwable $e) {
            flash('error', 'Database error saving seating assignment: ' . $e->getMessage());
        }

        $redirectTo($eventId);
    }

    // 2. Unassign single seat
    if ($action === 'unassign') {
        $seatCode = trim($_POST['seat_code'] ?? '');
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);

        if ($assignmentId > 0) {
            mcoll('seating_assignments')->deleteOne(['id' => $assignmentId, 'event_id' => $eventId]);
            flash('success', 'Seat assignment removed.');
        } elseif ($seatCode !== '') {
            mcoll('seating_assignments')->deleteOne(['seat_code' => $seatCode, 'event_id' => $eventId]);
            flash('success', "Seat {$seatCode} unassigned.");
        }

        $redirectTo($eventId);
    }

    // 3. Auto-Assign VIPs to Row A & B
    if ($action === 'auto_assign_vips') {
        // Find existing assigned guest IDs for this event
        $assignedGuestIds = [];
        $takenSeats = [];
        $existingAssigns = mcoll('seating_assignments')->find(['event_id' => $eventId]);
        foreach ($existingAssigns as $ea) {
            $assignedGuestIds[] = (int)$ea['guest_id'];
            $takenSeats[] = $ea['seat_code'];
        }

        // Find unassigned VIPs
        $vipFilter = [
            'event_id' => $eventId,
            '$or' => [
                ['priority_level' => 'VIP'],
                ['category' => 'VIP'],
                ['priority_level' => 'High']
            ]
        ];
        if (!empty($assignedGuestIds)) {
            $vipFilter['id'] = ['$nin' => $assignedGuestIds];
        }

        $vipsCursor = mcoll('guests')->aggregate([
            ['$match' => $vipFilter],
            ['$addFields' => [
                'is_vip_prio' => ['$cond' => [['$eq' => ['$priority_level', 'VIP']], 1, 2]]
            ]],
            ['$sort' => ['is_vip_prio' => 1, 'name' => 1]]
        ]);

        $vips = [];
        foreach ($vipsCursor as $vDoc) {
            $vips[] = mongo_doc_to_array($vDoc);
        }

        $assignedCount = 0;
        foreach ($vips as $vip) {
            // Find first available VIP seat
            foreach ($vipSeatsMap as $code => $trueVal) {
                if (!in_array($code, $takenSeats, true)) {
                    preg_match('/\d+/', $code, $nm);
                    $num = !empty($nm[0]) ? (int)$nm[0] : 0;

                    $newId = mongo_next_id('seating_assignments');
                    mcoll('seating_assignments')->insertOne([
                        'id' => $newId,
                        'event_id' => $eventId,
                        'guest_id' => (int)$vip['id'],
                        'seat_code' => $code,
                        'seat_number' => $num,
                        'section_name' => 'VIP Tier',
                        'seat_type' => 'VIP',
                        'assigned_by' => current_user()['id'] ?? null,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);

                    $takenSeats[] = $code;
                    $assignedCount++;
                    break;
                }
            }
        }

        flash('success', "Auto-assigned {$assignedCount} VIP guest(s) to reserved front rows!");
        $redirectTo($eventId);
    }

    // 4. Clear all seating assignments for event
    if ($action === 'clear_all') {
        mcoll('seating_assignments')->deleteMany(['event_id' => $eventId]);
        flash('success', 'All seat assignments for this event have been reset.');
        $redirectTo($eventId);
    }
}

/* =========================================================
   LOAD GUESTS AND SEAT ASSIGNMENTS FOR ACTIVE EVENT
========================================================= */
$guests = [];
$assignments = [];
$guestToSeatMap = [];

if ($selectedEvent) {
    // Load guests
    $guestCursor = mcoll('guests')->aggregate([
        ['$match' => ['event_id' => $selected]],
        ['$addFields' => [
            'prio_order' => [
                '$switch' => [
                    'branches' => [
                        ['case' => ['$eq' => ['$priority_level', 'VIP']], 'then' => 1],
                        ['case' => ['$eq' => ['$category', 'VIP']], 'then' => 2],
                        ['case' => ['$eq' => ['$priority_level', 'High']], 'then' => 3],
                        ['case' => ['$eq' => ['$priority_level', 'Official']], 'then' => 4],
                    ],
                    'default' => 5
                ]
            ]
        ]],
        ['$sort' => ['prio_order' => 1, 'name' => 1]]
    ]);

    foreach ($guestCursor as $gDoc) {
        $guests[] = mongo_doc_to_array($gDoc);
    }

    // Load seating assignments
    $saCursor = mcoll('seating_assignments')->aggregate([
        ['$match' => ['event_id' => $selected]],
        ['$lookup' => [
            'from' => 'guests',
            'localField' => 'guest_id',
            'foreignField' => 'id',
            'as' => 'guest_docs'
        ]],
        ['$match' => ['guest_docs' => ['$ne' => []]]],
        ['$addFields' => [
            'guest_name' => ['$arrayElemAt' => ['$guest_docs.name', 0]],
            'designation' => ['$arrayElemAt' => ['$guest_docs.designation', 0]],
            'organization' => ['$arrayElemAt' => ['$guest_docs.organization', 0]],
            'category' => ['$arrayElemAt' => ['$guest_docs.category', 0]],
            'priority_level' => ['$arrayElemAt' => ['$guest_docs.priority_level', 0]],
        ]],
        ['$project' => ['guest_docs' => 0]],
        ['$sort' => ['seat_code' => 1]]
    ]);

    foreach ($saCursor as $saDoc) {
        $assignment = mongo_doc_to_array($saDoc);
        $assignments[$assignment['seat_code']] = $assignment;
        $guestToSeatMap[(int)$assignment['guest_id']] = $assignment['seat_code'];
    }
}


// Compute statistics
$totalCapacity = count($allVenueSeats);
$occupiedSeats = count($assignments);
$availableSeats = max(0, $totalCapacity - $occupiedSeats);
$unassignedGuestsCount = 0;

foreach ($guests as $g) {
    if (!isset($guestToSeatMap[(int)$g['id']])) {
        $unassignedGuestsCount++;
    }
}

require __DIR__ . '/includes/header.php';
?>

<!-- =========================================================
     PAGE HEADER
========================================================= -->
<div class="page-head seating-page-head">
    <div>
        <span class="eyebrow">MILITARY PROTOCOL &amp; VENUE SEATING SYSTEM</span>
        <h1>Auditorium Seating Plan</h1>
        <p>Interactive seat allocation for dignitaries, VIPs, faculty and delegates.</p>
    </div>

    <?php if ($selectedEvent): ?>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn btn-dark" onclick="window.print()">
                🖨️ Print Seating Plan
            </button>
            <form method="post" style="display:inline;" onsubmit="return confirm('Clear ALL seat assignments for this event?');">
                <input type="hidden" name="action" value="clear_all">
                <input type="hidden" name="event_id" value="<?= $selected ?>">
                <button type="submit" class="btn btn-light" style="color:#ef4444; border-color:#fca5a5;">
                    🗑️ Reset Seating
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- =========================================================
     EVENT SELECTOR & VENUE SWITCHER
========================================================= -->
<section class="panel seating-selector" style="margin-bottom:20px; padding:20px 24px; border-radius:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; margin-bottom:14px;">
        <div>
            <h2 style="font-size:17px; margin:0; color:#082d21;">Select Event &amp; Auditorium</h2>
            <p style="margin:4px 0 0; color:#64748b; font-size:12px;">Choose an event to load its specialized venue hall architecture.</p>
        </div>
        <?php if ($selectedEvent && $unassignedGuestsCount > 0): ?>
            <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="auto_assign_vips">
                <input type="hidden" name="event_id" value="<?= $selected ?>">
                <button type="submit" class="btn btn-primary" style="font-size:12px; padding:6px 14px; background:#a47b2c; border-color:#a47b2c;">
                    ⚡ Auto-Assign VIPs to Row A &amp; B
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($events): ?>
        <form method="get" class="seating-event-form" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
            <select name="event_id" required aria-label="Select event" style="flex:1; min-height:48px; border-radius:10px; border:1px solid #cbd5e1; padding:0 14px; font-weight:600; color:#082d21;">
                <?php foreach ($events as $ev): ?>
                    <option value="<?= (int)$ev['id'] ?>" <?= $selected === (int)$ev['id'] ? 'selected' : '' ?>>
                        <?= e($ev['name']) ?> — 📍 <?= e($ev['venue'] ?: 'Auditorium') ?> (<?= e(date('d M Y', strtotime($ev['event_date']))) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary" style="min-height:48px; padding:0 22px;">
                Load Hall Layout
            </button>
        </form>
    <?php endif; ?>
</section>

<?php if ($selectedEvent): ?>

<?php if (($selectedEvent['mode'] ?? '') === 'Online'): ?>
    <section class="panel" style="background:#f0f9ff; border:1px solid #bae6fd; border-left:6px solid #0284c7; padding:32px 28px; border-radius:14px; margin-bottom:24px;">
        <div style="display:flex; align-items:flex-start; gap:18px;">
            <div style="font-size:36px; line-height:1;">🌐</div>
            <div>
                <span style="display:inline-block; font-size:10px; font-weight:800; letter-spacing:1.5px; color:#0369a1; text-transform:uppercase; margin-bottom:4px;">ONLINE WEBINAR / MEETING MODE</span>
                <h3 style="margin:0 0 8px; color:#0c4a6e; font-size:20px;"><?= e($selectedEvent['name']) ?></h3>
                <p style="margin:0 0 12px; color:#334155; font-size:14px; line-height:1.6;">
                    This event is configured as an <strong>Online Event</strong>. Physical auditorium venue and seating arrangement are disabled and not required.
                </p>
                <?php if (!empty($selectedEvent['meeting_link'])): ?>
                    <div style="background:#ffffff; border:1px solid #e0f2fe; padding:12px 16px; border-radius:8px; display:inline-flex; align-items:center; gap:10px;">
                        <span style="font-size:12px; font-weight:700; color:#0369a1;">Virtual Conference Link:</span>
                        <a href="<?= e($selectedEvent['meeting_link']) ?>" target="_blank" style="color:#0284c7; font-weight:700; text-decoration:underline;">
                            <?= e($selectedEvent['meeting_link']) ?> &nearr;
                        </a>
                    </div>
                <?php else: ?>
                    <span style="font-size:13px; color:#64748b;">(No virtual meeting link provided. You can edit the event to specify a stream/meeting URL.)</span>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php else: ?>
<section class="panel event-seat-info" style="margin-bottom:20px; padding:20px 24px; border-radius:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; background:linear-gradient(135deg, #051d14 0%, #082d21 50%, #0d3b2b 100%); color:#ffffff; border:1px solid rgba(212,175,55,0.25); box-shadow:0 10px 30px rgba(6,40,30,0.12);">
    <div>
        <span style="font-size:10px; font-weight:800; letter-spacing:2px; color:#f5d98d; text-transform:uppercase;">
            AUDITORIUM ARCHITECTURE: <?= e(strtoupper($venueLayout['type'] ?? 'STANDARD')) ?>
        </span>
        <h2 style="margin:4px 0 0; color:#ffffff; font-size:20px;"><?= e($selectedEvent['name']) ?></h2>
        <p style="margin:6px 0 0; color:#cbd5e1; font-size:13px;">
            📍 <strong><?= e($selectedEvent['venue'] ?: 'Grand Auditorium') ?></strong> &bull;
            📅 <?= e(date('l, d F Y', strtotime($selectedEvent['event_date']))) ?>
            <?php if (!empty($selectedEvent['event_time'])): ?>
                &bull; 🕒 <?= e(substr($selectedEvent['event_time'], 0, 5)) ?>
            <?php endif; ?>
        </p>
    </div>

    <div style="display:flex; gap:12px; align-items:center;">
        <span class="badge" style="background:#f5d98d; color:#082d21; font-weight:800; padding:6px 12px; font-size:12px;">
            <?= e($selectedEvent['status']) ?>
        </span>
    </div>
</section>

<!-- CAPACITY STATS -->
<div class="stat-grid seating-stats" style="margin-bottom:24px;">
    <div class="stat-card">
        <span>Auditorium Capacity</span>
        <strong><?= $totalCapacity ?></strong>
        <em>Total available seats in layout</em>
    </div>
    <div class="stat-card">
        <span>Occupied / Assigned</span>
        <strong style="color:#082d21;"><?= $occupiedSeats ?></strong>
        <em>Allocated guest seats</em>
    </div>
    <div class="stat-card">
        <span>Open / Available</span>
        <strong style="color:#16a34a;"><?= $availableSeats ?></strong>
        <em>Ready for assignment</em>
    </div>
    <div class="stat-card">
        <span>Unassigned Guests</span>
        <strong style="color:<?= $unassignedGuestsCount > 0 ? '#d97706' : '#64748b' ?>;"><?= $unassignedGuestsCount ?></strong>
        <em>Guests waiting for seat</em>
    </div>
</div>

<!-- =========================================================
     MAIN AUDITORIUM SEATING LAYOUT + GUEST REGISTER
========================================================= -->
<div class="seating-layout">

    <!-- LEFT: AUDITORIUM SEATING MAP -->
    <section class="panel seating-map-panel" style="padding:26px 28px; border-radius:18px;">

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px; border-bottom:1px solid #e2e8f0; padding-bottom:14px;">
            <div>
                <span class="eyebrow"><?= e($venueLayout['title'] ?? 'Auditorium') ?></span>
                <h2 style="font-size:19px; margin:2px 0 0; color:#082d21;">Hall Seating Plan</h2>
                <small style="color:#64748b;"><?= e($venueLayout['subtitle'] ?? 'Tiered Auditorium Layout') ?></small>
            </div>

            <!-- SEAT STATUS LEGEND -->
            <div class="seat-legend" style="display:flex; gap:14px; align-items:center; flex-wrap:wrap; font-size:11px; font-weight:600;">
                <span style="display:flex; align-items:center; gap:6px;">
                    <span style="display:inline-block; width:14px; height:14px; border-radius:3px; border:1.5px solid #cbd5e1; background:#ffffff;"></span>
                    Available
                </span>
                <span style="display:flex; align-items:center; gap:6px;">
                    <span style="display:inline-block; width:14px; height:14px; border-radius:3px; border:1.5px solid #d97706; background:#fffbeb;"></span>
                    VIP / Reserved
                </span>
                <span style="display:flex; align-items:center; gap:6px;">
                    <span style="display:inline-block; width:14px; height:14px; border-radius:3px; background:#082d21;"></span>
                    Assigned
                </span>
                <span style="display:flex; align-items:center; gap:6px;">
                    <span style="display:inline-block; width:14px; height:14px; border-radius:3px; border:2px solid #2563eb; background:#eff6ff;"></span>
                    Selected
                </span>
            </div>
        </div>

        <!-- STAGE / PODIUM TRAPEZOID HEADER -->
        <div class="screen-trapezoid">
            <div class="screen-inner">
                <span class="screen-title"><?= $venueLayout['stage_label'] ?? 'STAGE / PODIUM' ?></span>
                <small class="screen-sub"><?= $venueLayout['stage_sub'] ?? 'মঞ্চ ও মূল ডায়াস' ?></small>
            </div>
        </div>

        <!-- SECTIONS AND ROWS -->
        <div class="auditorium-container">
            <?php foreach ($venueLayout['sections'] as $secIdx => $sec): ?>
                <div class="seat-zone-header">
                    <div class="seat-zone-bar" style="background:<?= $sec['color'] ?? '#a47b2c' ?>;"></div>
                    <strong><?= e($sec['name']) ?></strong>
                    <span class="zone-badge" style="background:<?= ($sec['type'] === 'VIP') ? '#fef3c7' : '#e2e8f0' ?>; color:<?= ($sec['type'] === 'VIP') ? '#92400e' : '#475569' ?>;">
                        <?= e($sec['tag']) ?>
                    </span>
                </div>

                <div class="auditorium-rows-block">
                    <?php foreach ($sec['rows'] as $r): ?>
                        <div class="auditorium-row">
                            <!-- Left Row Label -->
                            <span class="row-label"><?= e($r['row']) ?></span>

                            <!-- Left Block -->
                            <?php if (!empty($r['left'])): ?>
                                <div class="seat-block left-block">
                                    <?php foreach ($r['left'] as $code): ?>
                                        <?php
                                        $assign = $assignments[$code] ?? null;
                                        $isVipSeat = isset($vipSeatsMap[$code]);
                                        ?>
                                        <div class="seat-cell" data-seat-code="<?= e($code) ?>">
                                            <button
                                                type="button"
                                                class="seat-btn <?= $assign ? 'assigned' : 'available' ?> <?= $isVipSeat ? 'vip-seat' : '' ?>"
                                                data-seat-code="<?= e($code) ?>"
                                                data-seat-type="<?= e($sec['type']) ?>"
                                                data-section-name="<?= e($sec['name']) ?>"
                                                data-assigned="<?= $assign ? '1' : '0' ?>"
                                                onclick="onSeatClicked('<?= e($code) ?>', '<?= e($sec['name']) ?>', '<?= e($sec['type']) ?>')"
                                                title="<?= $assign ? e($assign['guest_name'] . ' (' . ($assign['designation'] ?: 'VIP') . ')') : e($code . ' - Available ' . $sec['type']) ?>"
                                            >
                                                <span class="seat-code-text"><?= e($code) ?></span>
                                                <?php if ($assign): ?>
                                                    <span class="assigned-dot"></span>
                                                    <span class="seat-guest-name">
                                                        <?= e(mb_substr($assign['guest_name'], 0, 8)) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </button>

                                            <?php if ($assign): ?>
                                                <form method="post" class="seat-unassign-btn-wrap">
                                                    <input type="hidden" name="action" value="unassign">
                                                    <input type="hidden" name="event_id" value="<?= $selected ?>">
                                                    <input type="hidden" name="assignment_id" value="<?= (int)$assign['id'] ?>">
                                                    <input type="hidden" name="seat_code" value="<?= e($code) ?>">
                                                    <button type="submit" class="seat-del-btn" title="Remove assignment" onclick="return confirm('Remove seat <?= e($code) ?> assignment?')">&times;</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Left Walkway / Aisle -->
                            <?php if (!empty($r['left']) && !empty($r['center'])): ?>
                                <div class="aisle-gap">
                                    <span class="aisle-marker">AISLE</span>
                                </div>
                            <?php endif; ?>

                            <!-- Center Block -->
                            <?php if (!empty($r['center'])): ?>
                                <div class="seat-block center-block">
                                    <?php foreach ($r['center'] as $code): ?>
                                        <?php
                                        $assign = $assignments[$code] ?? null;
                                        $isVipSeat = isset($vipSeatsMap[$code]);
                                        ?>
                                        <div class="seat-cell" data-seat-code="<?= e($code) ?>">
                                            <button
                                                type="button"
                                                class="seat-btn <?= $assign ? 'assigned' : 'available' ?> <?= $isVipSeat ? 'vip-seat' : '' ?>"
                                                data-seat-code="<?= e($code) ?>"
                                                data-seat-type="<?= e($sec['type']) ?>"
                                                data-section-name="<?= e($sec['name']) ?>"
                                                data-assigned="<?= $assign ? '1' : '0' ?>"
                                                onclick="onSeatClicked('<?= e($code) ?>', '<?= e($sec['name']) ?>', '<?= e($sec['type']) ?>')"
                                                title="<?= $assign ? e($assign['guest_name'] . ' (' . ($assign['designation'] ?: 'VIP') . ')') : e($code . ' - Available ' . $sec['type']) ?>"
                                            >
                                                <span class="seat-code-text"><?= e($code) ?></span>
                                                <?php if ($assign): ?>
                                                    <span class="assigned-dot"></span>
                                                    <span class="seat-guest-name">
                                                        <?= e(mb_substr($assign['guest_name'], 0, 8)) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </button>

                                            <?php if ($assign): ?>
                                                <form method="post" class="seat-unassign-btn-wrap">
                                                    <input type="hidden" name="action" value="unassign">
                                                    <input type="hidden" name="event_id" value="<?= $selected ?>">
                                                    <input type="hidden" name="assignment_id" value="<?= (int)$assign['id'] ?>">
                                                    <input type="hidden" name="seat_code" value="<?= e($code) ?>">
                                                    <button type="submit" class="seat-del-btn" title="Remove assignment" onclick="return confirm('Remove seat <?= e($code) ?> assignment?')">&times;</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Right Walkway / Aisle -->
                            <?php if (!empty($r['right']) && (!empty($r['center']) || !empty($r['left']))): ?>
                                <div class="aisle-gap">
                                    <span class="aisle-marker">AISLE</span>
                                </div>
                            <?php endif; ?>

                            <!-- Right Block -->
                            <?php if (!empty($r['right'])): ?>
                                <div class="seat-block right-block">
                                    <?php foreach ($r['right'] as $code): ?>
                                        <?php
                                        $assign = $assignments[$code] ?? null;
                                        $isVipSeat = isset($vipSeatsMap[$code]);
                                        ?>
                                        <div class="seat-cell" data-seat-code="<?= e($code) ?>">
                                            <button
                                                type="button"
                                                class="seat-btn <?= $assign ? 'assigned' : 'available' ?> <?= $isVipSeat ? 'vip-seat' : '' ?>"
                                                data-seat-code="<?= e($code) ?>"
                                                data-seat-type="<?= e($sec['type']) ?>"
                                                data-section-name="<?= e($sec['name']) ?>"
                                                data-assigned="<?= $assign ? '1' : '0' ?>"
                                                onclick="onSeatClicked('<?= e($code) ?>', '<?= e($sec['name']) ?>', '<?= e($sec['type']) ?>')"
                                                title="<?= $assign ? e($assign['guest_name'] . ' (' . ($assign['designation'] ?: 'VIP') . ')') : e($code . ' - Available ' . $sec['type']) ?>"
                                            >
                                                <span class="seat-code-text"><?= e($code) ?></span>
                                                <?php if ($assign): ?>
                                                    <span class="assigned-dot"></span>
                                                    <span class="seat-guest-name">
                                                        <?= e(mb_substr($assign['guest_name'], 0, 8)) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </button>

                                            <?php if ($assign): ?>
                                                <form method="post" class="seat-unassign-btn-wrap">
                                                    <input type="hidden" name="action" value="unassign">
                                                    <input type="hidden" name="event_id" value="<?= $selected ?>">
                                                    <input type="hidden" name="assignment_id" value="<?= (int)$assign['id'] ?>">
                                                    <input type="hidden" name="seat_code" value="<?= e($code) ?>">
                                                    <button type="submit" class="seat-del-btn" title="Remove assignment" onclick="return confirm('Remove seat <?= e($code) ?> assignment?')">&times;</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Right Row Label -->
                            <span class="row-label right"><?= e($r['row']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- FLOATING ASSIGNMENT STATUS PROMPT -->
        <div class="assignment-status-bar" id="assignmentStatusBar">
            <div style="display:flex; align-items:center; gap:12px;">
                <span class="status-indicator-dot"></span>
                <div id="statusText">
                    <strong>Step 1: Select a guest from the register</strong>
                    <p style="margin:0; font-size:12px; color:#64748b;">Click any guest in the right panel, then click any auditorium seat to assign.</p>
                </div>
            </div>
            <button type="button" class="btn btn-light" id="clearSelectionBtn" style="display:none; font-size:11px; padding:4px 10px;" onclick="cancelGuestSelection()">
                Cancel Selection
            </button>
        </div>

    </section>

    <!-- RIGHT: GUEST REGISTER & ASSIGNMENT PANEL -->
    <section class="panel guest-seat-panel" style="padding:22px 24px; border-radius:18px;">
        <div class="panel-head" style="padding:0 0 14px; margin-bottom:14px; border-bottom:1px solid #e2e8f0;">
            <div>
                <span class="eyebrow">DELEGATE &amp; GUEST REGISTER</span>
                <h2 style="font-size:18px; margin:2px 0 0; color:#082d21;">Guest List (<?= count($guests) ?>)</h2>
                <p style="margin:2px 0 0; font-size:11px; color:#64748b;">Select a guest, then click an open seat in the hall.</p>
            </div>
        </div>

        <?php if ($guests): ?>
            <!-- SEARCH AND FILTER TABS -->
            <div style="margin-bottom:12px;">
                <input
                    type="search"
                    id="guestSearch"
                    class="guest-search"
                    placeholder="🔍 Search guest name, designation..."
                    aria-label="Search guests"
                    style="width:100%; box-sizing:border-box; min-height:42px; border-radius:8px; border:1px solid #cbd5e1; padding:0 12px; font-size:13px; margin-bottom:8px;"
                >

                <div class="guest-filter-tabs" style="display:flex; gap:6px;">
                    <button type="button" class="tab-btn active" data-filter="all" onclick="filterGuestCards('all', this)">All</button>
                    <button type="button" class="tab-btn" data-filter="unassigned" onclick="filterGuestCards('unassigned', this)">Unassigned (<?= $unassignedGuestsCount ?>)</button>
                    <button type="button" class="tab-btn" data-filter="vip" onclick="filterGuestCards('vip', this)">VIP Only</button>
                </div>
            </div>

            <!-- GUEST SELECTION CARDS LIST -->
            <div class="guest-selection-list" id="guestSelectionList">
                <?php foreach ($guests as $guest): ?>
                    <?php
                    $guestSeatCode = $guestToSeatMap[(int)$guest['id']] ?? null;
                    $isVip = ($guest['priority_level'] === 'VIP' || $guest['category'] === 'VIP');
                    ?>
                    <button
                        type="button"
                        class="guest-seat-card <?= $guestSeatCode ? 'has-seat' : 'no-seat' ?>"
                        data-guest-id="<?= (int)$guest['id'] ?>"
                        data-guest-name="<?= e($guest['name']) ?>"
                        data-guest-category="<?= e($guest['category'] ?? 'Guest') ?>"
                        data-guest-priority="<?= e($guest['priority_level'] ?? 'Normal') ?>"
                        data-has-seat="<?= $guestSeatCode ? '1' : '0' ?>"
                        data-is-vip="<?= $isVip ? '1' : '0' ?>"
                        data-search="<?= e(strtolower($guest['name'] . ' ' . ($guest['designation'] ?? '') . ' ' . ($guest['organization'] ?? ''))) ?>"
                        onclick="selectGuest(this)"
                    >
                        <span class="guest-seat-avatar" style="background:<?= $isVip ? '#fef3c7' : '#e0e7ff' ?>; color:<?= $isVip ? '#b45309' : '#3730a3' ?>;">
                            <?= e(mb_strtoupper(mb_substr($guest['name'], 0, 1))) ?>
                        </span>

                        <span class="guest-seat-details">
                            <strong style="color:#0f172a; font-size:13px;"><?= e($guest['name']) ?></strong>

                            <?php if (!empty($guest['designation'])): ?>
                                <small style="color:#475569; display:block;"><?= e($guest['designation']) ?></small>
                            <?php endif; ?>

                            <?php if (!empty($guest['organization'])): ?>
                                <small style="color:#64748b; font-size:11px; display:block;"><?= e($guest['organization']) ?></small>
                            <?php endif; ?>

                            <span class="guest-seat-meta" style="margin-top:6px; display:flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                <span class="badge <?= $isVip ? 'vip-badge' : 'neutral' ?>" style="font-size:10px;">
                                    <?= e($guest['category'] ?? 'Guest') ?>
                                </span>

                                <span class="badge" style="font-size:10px; background:#f1f5f9; color:#475569; border:1px solid #cbd5e1;">
                                    <?= e($guest['arrival_status'] ?? 'Expected') ?>
                                </span>

                                <?php if ($guestSeatCode): ?>
                                    <span class="seat-assigned-pill">
                                        💺 Seat <?= e($guestSeatCode) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="seat-pending-pill">
                                        No Seat
                                    </span>
                                <?php endif; ?>
                            </span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>

            <p class="guest-search-empty" id="guestSearchEmpty" hidden style="text-align:center; padding:20px; color:#64748b;">
                No matching guests found.
            </p>
        <?php else: ?>
            <div class="empty" style="text-align:center; padding:30px;">
                <strong>No guests registered for this event</strong>
                <p style="color:#64748b; font-size:13px; margin:6px 0 14px;">Add guests before assigning seating.</p>
                <a href="<?= BASE_URL ?>/guests.php?event_id=<?= $selected ?>" class="btn btn-primary">
                    + Add Guests
                </a>
            </div>
        <?php endif; ?>
    </section>

</div>
<?php endif; ?>

<?php else: ?>

<section class="panel empty" style="text-align:center; padding:40px;">
    <strong>No events available</strong>
    <p style="color:#64748b; margin:6px 0 16px;">Create an event first before configuring the seating plan.</p>
    <a href="<?= BASE_URL ?>/events.php?action=new" class="btn btn-primary">+ Create New Event</a>
</section>

<?php endif; ?>

<!-- =========================================================
     AUDITORIUM SEATING CSS
========================================================= -->
<style>
/* Layout Grid */
.seating-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.8fr) minmax(320px, 1fr);
    gap: 22px;
    align-items: start;
}

/* SCREEN / STAGE TRAPEZOID (Matching user uploaded visual) */
.screen-trapezoid {
    position: relative;
    max-width: 540px;
    margin: 12px auto 38px;
    text-align: center;
}
.screen-trapezoid .screen-inner {
    background: linear-gradient(180deg, #ffffff 0%, #f1f5f9 100%);
    border: 2px solid #94a3b8;
    border-top: 5px solid #082d21;
    border-radius: 4px;
    clip-path: polygon(5% 0%, 95% 0%, 100% 100%, 0% 100%);
    padding: 14px 28px 10px;
    box-shadow: 0 8px 20px rgba(0,0,0,0.06);
}
.screen-trapezoid .screen-title {
    display: block;
    font-size: 13px;
    font-weight: 900;
    letter-spacing: 4px;
    color: #082d21;
    text-transform: uppercase;
}
.screen-trapezoid .screen-sub {
    display: block;
    font-size: 11px;
    color: #64748b;
    margin-top: 2px;
    letter-spacing: 2.5px;
    font-weight: 600;
}

/* Auditorium Container */
.auditorium-container {
    width: 100%;
    overflow-x: auto;
    padding-bottom: 14px;
}

/* Section Header */
.seat-zone-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 24px 0 12px;
    padding-bottom: 6px;
    border-bottom: 1px dashed #e2e8f0;
}
.seat-zone-bar {
    width: 5px;
    height: 18px;
    background: #a47b2c;
    border-radius: 3px;
}
.seat-zone-header strong {
    font-size: 13px;
    color: #1e293b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.zone-badge {
    font-size: 10px;
    font-weight: 800;
    padding: 2px 8px;
    border-radius: 4px;
    font-family: monospace;
}

/* Row Layout */
.auditorium-rows-block {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.auditorium-row {
    display: flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
}

/* Row Labels */
.row-label {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 32px;
    font-weight: 800;
    font-size: 13px;
    color: #475569;
    font-family: monospace;
}
.row-label.right {
    opacity: 0.6;
}

/* Seat Blocks */
.seat-block {
    display: flex;
    gap: 6px;
    align-items: center;
}

/* Walkway / Aisle */
.aisle-gap {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 36px;
    margin: 0 4px;
}
.aisle-marker {
    font-size: 8px;
    font-weight: 800;
    color: #94a3b8;
    letter-spacing: 1px;
    writing-mode: vertical-lr;
    transform: rotate(180deg);
    opacity: 0.5;
}

/* Seat Cell & Button */
.seat-cell {
    position: relative;
    display: inline-block;
}
.seat-btn {
    box-sizing: border-box;
    width: 44px;
    height: 36px;
    border-radius: 7px;
    border: 1.5px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
    font-weight: 700;
    font-family: monospace;
    font-size: 11px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 2px;
    transition: all 0.16s ease;
    user-select: none;
}
.seat-btn:hover {
    transform: translateY(-2px);
    border-color: #2563eb;
    box-shadow: 0 4px 10px rgba(37,99,235,0.18);
}

/* VIP Seat border */
.seat-btn.vip-seat {
    border-color: #d97706;
    background: #fffdf5;
}

/* Assigned Seat (Patterned & Filled matching uploaded image F10/G09) */
.seat-btn.assigned {
    background: repeating-linear-gradient(
        -45deg,
        #082d21,
        #082d21 5px,
        #1e3a5f 5px,
        #1e3a5f 10px
    );
    border-color: #082d21;
    color: #ffffff;
}
.seat-btn.assigned .seat-code-text {
    color: #f5d98d;
    font-weight: 800;
}
.seat-btn.assigned .seat-guest-name {
    display: block;
    font-size: 8px;
    color: #e2e8f0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 38px;
    line-height: 1;
}

/* Seat Selected / Highlight */
.seat-btn.selected-seat {
    border-color: #2563eb !important;
    outline: 3px solid rgba(37,99,235,0.4);
    background: #dbeafe !important;
    color: #1e40af !important;
}

/* Seat delete button (x) */
.seat-unassign-btn-wrap {
    position: absolute;
    top: -6px;
    right: -6px;
    margin: 0;
    z-index: 5;
}
.seat-del-btn {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #ef4444;
    color: #ffffff;
    border: 1.5px solid #ffffff;
    font-size: 11px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    padding: 0;
}
.seat-del-btn:hover {
    background: #b91c1c;
    transform: scale(1.15);
}

/* Status Prompt Bar */
.assignment-status-bar {
    margin-top: 24px;
    padding: 14px 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}
.status-indicator-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #10b981;
    display: inline-block;
}

/* Right Guest Register */
.guest-selection-list {
    max-height: 580px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-right: 4px;
}
.guest-seat-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    background: #ffffff;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    text-align: left;
    cursor: pointer;
    transition: all 0.16s ease;
    width: 100%;
    box-sizing: border-box;
}
.guest-seat-card:hover {
    border-color: #2563eb;
    background: #f8fafc;
    transform: translateX(2px);
}
.guest-seat-card.selected {
    border-color: #2563eb !important;
    background: #eff6ff !important;
    box-shadow: 0 0 0 2px #2563eb;
}
.guest-seat-avatar {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 15px;
    flex-shrink: 0;
}
.seat-assigned-pill {
    font-size: 10px;
    font-weight: 700;
    color: #1e40af;
    background: #dbeafe;
    padding: 2px 7px;
    border-radius: 4px;
    font-family: monospace;
}
.seat-pending-pill {
    font-size: 10px;
    font-weight: 700;
    color: #d97706;
    background: #fef3c7;
    padding: 2px 7px;
    border-radius: 4px;
}
.guest-filter-tabs .tab-btn {
    padding: 5px 12px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
}
.guest-filter-tabs .tab-btn.active {
    background: #082d21;
    color: #ffffff;
    border-color: #082d21;
}

/* Print CSS */
@media print {
    .sidebar, .navbar, .page-head button, .seating-selector, .guest-seat-panel, .assignment-status-bar, footer {
        display: none !important;
    }
    .seating-layout {
        display: block !important;
    }
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
    }
    .screen-trapezoid {
        box-shadow: none !important;
    }
}
</style>

<!-- =========================================================
     INTERACTIVE JAVASCRIPT
========================================================= -->
<script>
'use strict';

let selectedGuest = null;

function selectGuest(button) {
    document.querySelectorAll('.guest-seat-card').forEach(c => c.classList.remove('selected'));
    button.classList.add('selected');

    selectedGuest = {
        id: button.dataset.guestId,
        name: button.dataset.guestName,
        category: button.dataset.guestCategory || 'Guest',
        priority: button.dataset.guestPriority || 'Normal',
        isVip: button.dataset.isVip === '1'
    };

    const statusText = document.getElementById('statusText');
    const cancelBtn = document.getElementById('clearSelectionBtn');

    statusText.innerHTML = `
        <strong style="color:#2563eb;">Guest Selected: ${selectedGuest.name} (${selectedGuest.category})</strong>
        <p style="margin:2px 0 0; font-size:12px; color:#1e293b;">Now click any open seat (or an assigned seat to reassign) in the hall to allocate.</p>
    `;
    if (cancelBtn) cancelBtn.style.display = 'inline-block';

    // Pulse available seats
    document.querySelectorAll('.seat-btn.available').forEach(s => {
        s.style.boxShadow = '0 0 6px rgba(37,99,235,0.4)';
    });
}

function cancelGuestSelection() {
    selectedGuest = null;
    document.querySelectorAll('.guest-seat-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.seat-btn').forEach(s => {
        s.style.boxShadow = '';
    });

    const statusText = document.getElementById('statusText');
    const cancelBtn = document.getElementById('clearSelectionBtn');
    statusText.innerHTML = `
        <strong>Step 1: Select a guest from the register</strong>
        <p style="margin:0; font-size:12px; color:#64748b;">Click any guest in the right panel, then click any auditorium seat to assign.</p>
    `;
    if (cancelBtn) cancelBtn.style.display = 'none';
}

function onSeatClicked(seatCode, sectionName, seatType) {
    if (!selectedGuest) {
        alert('Please select a guest from the right-hand register first!');
        return;
    }

    const seatBtn = document.querySelector(`.seat-btn[data-seat-code="${seatCode}"]`);
    const isAssigned = seatBtn && seatBtn.dataset.assigned === '1';

    if (isAssigned) {
        if (!confirm(`Seat ${seatCode} is currently occupied. Do you want to reassign this seat to ${selectedGuest.name}?`)) {
            return;
        }
    } else {
        if (seatType === 'VIP' && !selectedGuest.isVip) {
            if (!confirm(`Seat ${seatCode} is in the VIP / Reserved section. Assign non-VIP guest "${selectedGuest.name}" here?`)) {
                return;
            }
        }
    }

    // Submit assignment form
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;

    const fields = {
        action: 'assign',
        event_id: '<?= (int)$selected ?>',
        guest_id: selectedGuest.id,
        seat_code: seatCode,
        section_name: sectionName,
        seat_type: seatType
    };

    for (const [k, v] of Object.entries(fields)) {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = k;
        inp.value = v;
        form.appendChild(inp);
    }

    document.body.appendChild(form);
    form.submit();
}

// Guest Search Filtering
const guestSearch = document.getElementById('guestSearch');
if (guestSearch) {
    guestSearch.addEventListener('input', function() {
        const q = this.value.trim().toLowerCase();
        let count = 0;
        document.querySelectorAll('.guest-seat-card').forEach(card => {
            const matches = (card.dataset.search || '').includes(q);
            card.style.display = matches ? 'flex' : 'none';
            if (matches) count++;
        });
        const emptyMsg = document.getElementById('guestSearchEmpty');
        if (emptyMsg) emptyMsg.hidden = (count !== 0);
    });
}

// Tab Filtering
function filterGuestCards(type, btn) {
    document.querySelectorAll('.guest-filter-tabs .tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    document.querySelectorAll('.guest-seat-card').forEach(card => {
        if (type === 'all') {
            card.style.display = 'flex';
        } else if (type === 'unassigned') {
            card.style.display = (card.dataset.hasSeat === '0') ? 'flex' : 'none';
        } else if (type === 'vip') {
            card.style.display = (card.dataset.isVip === '1') ? 'flex' : 'none';
        }
    });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>