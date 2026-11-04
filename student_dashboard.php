<?php
require_once __DIR__ . '/config/auth.php';
require_login();

// View-only restriction: enforce Student role or Admin viewing student perspective
if (!is_student() && !has_role('Admin') && !is_event_director()) {
    redirect(get_role_redirect_url());
}

$pdo = db();
$user = current_user();
$page_title = 'Student Dashboard | BUP Protocol';

// View parameter for sub-pages
$activeTab = $_GET['tab'] ?? 'overview';

// 1. Fetch relevant upcoming events
$events = $pdo->query("
    SELECT id, name as title, event_type, venue, event_date, event_time, status, description
    FROM events
    WHERE status IN ('Upcoming', 'Ongoing')
    ORDER BY event_date ASC, event_time ASC
")->fetchAll();

// 2. Fetch announcements / notifications
$announcements = $pdo->query("
    SELECT a.*, u.name as publisher_name, u.role as publisher_role
    FROM announcements a
    LEFT JOIN users u ON u.id = a.created_by
    ORDER BY a.created_at DESC
    LIMIT 10
")->fetchAll();

// 3. Fetch emergency contacts
$emergencyContacts = $pdo->query("
    SELECT * FROM emergency_contacts
    ORDER BY department ASC, name ASC
")->fetchAll();

// 4. Fetch venue & seating status summary
$venueStatus = $pdo->query("
    SELECT venue, COUNT(*) as event_count
    FROM events
    WHERE status IN ('Upcoming', 'Ongoing')
    GROUP BY venue
")->fetchAll();

$seatingSummary = $pdo->query("
    SELECT e.name as event_title, e.venue,
           COUNT(sa.id) as assigned_seats
    FROM events e
    LEFT JOIN seating_assignments sa ON sa.event_id = e.id
    WHERE e.status IN ('Upcoming', 'Ongoing')
    GROUP BY e.id
")->fetchAll();

require __DIR__ . '/includes/header.php';
?>

        <!-- TOP BANNER -->
        <div style="background:linear-gradient(135deg, #051d14 0%, #082d21 50%, #0e3f2f 100%); color:#fff; border-radius:14px; padding:24px 28px; margin-bottom:24px; border:1px solid rgba(212,175,55,0.25); box-shadow:0 8px 24px rgba(8,45,33,0.15);">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
                <div>
                    <span style="font-size:11px; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; color:#d4af37; display:block; margin-bottom:6px;">
                        BANGLADESH UNIVERSITY OF PROFESSIONALS
                    </span>
                    <h1 style="margin:0; font-size:24px; font-weight:800; color:#ffffff;">
                        Welcome, <?= e($user['name']) ?>
                    </h1>
                    <p style="margin:6px 0 0; color:#c7ded2; font-size:13px;">
                        Student Portal &bull; <?= e($user['department']) ?> &bull; Student ID: <strong><?= e($user['student_id'] ?: $user['designation']) ?></strong>
                    </p>
                </div>
                <div style="display:flex; gap:10px; align-items:center;">
                    <span style="background:rgba(255,255,255,0.12); color:#fff; padding:6px 14px; border-radius:20px; font-size:12px; font-weight:700;">
                        ✓ Account Verified (Active)
                    </span>
                    <span style="background:rgba(212,175,55,0.2); border:1px solid rgba(212,175,55,0.35); color:#fef3c7; padding:6px 14px; border-radius:20px; font-size:12px; font-weight:700;">
                        View-Only Access
                    </span>
                </div>
            </div>
        </div>

        <?php if ($activeTab === 'overview'): ?>
            <!-- STATS CARDS -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
                <div class="panel" style="padding:18px; margin-bottom:0; border-left:4px solid #0284c7;">
                    <span class="eyebrow" style="color:#0284c7;">UPCOMING EVENTS</span>
                    <div style="font-size:28px; font-weight:800; color:#0f172a; margin-top:6px;"><?= count($events) ?></div>
                    <small style="color:#64748b;">Scheduled university ceremonies</small>
                </div>
                <div class="panel" style="padding:18px; margin-bottom:0; border-left:4px solid #10b981;">
                    <span class="eyebrow" style="color:#10b981;">ACTIVE ANNOUNCEMENTS</span>
                    <div style="font-size:28px; font-weight:800; color:#0f172a; margin-top:6px;"><?= count($announcements) ?></div>
                    <small style="color:#64748b;">Notices from event leadership</small>
                </div>
                <div class="panel" style="padding:18px; margin-bottom:0; border-left:4px solid #f59e0b;">
                    <span class="eyebrow" style="color:#f59e0b;">ACTIVE VENUES</span>
                    <div style="font-size:28px; font-weight:800; color:#0f172a; margin-top:6px;"><?= count($venueStatus) ?></div>
                    <small style="color:#64748b;">Auditorium &amp; Hall setups</small>
                </div>
                <div class="panel" style="padding:18px; margin-bottom:0; border-left:4px solid #ef4444;">
                    <span class="eyebrow" style="color:#ef4444;">EMERGENCY HELPLINES</span>
                    <div style="font-size:28px; font-weight:800; color:#0f172a; margin-top:6px;"><?= count($emergencyContacts) ?></div>
                    <small style="color:#64748b;">Duty medical, security &amp; leads</small>
                </div>
            </div>

            <!-- UPCOMING EVENTS OVERVIEW -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="eyebrow">SCHEDULED CALENDAR</span>
                        <h2>Upcoming Relevant Events</h2>
                    </div>
                    <a href="?tab=events" class="btn btn-secondary" style="font-size:12px; padding:6px 12px;">View All Events &rarr;</a>
                </div>
                <div class="table-wrap">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Event Name</th>
                                <th>Category</th>
                                <th>Venue</th>
                                <th>Date &amp; Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($events)): ?>
                                <tr><td colspan="5" style="text-align:center; padding:20px; color:#64748b;">No upcoming events scheduled currently.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($events, 0, 4) as $ev): ?>
                                <tr>
                                    <td><strong><?= e($ev['title']) ?></strong></td>
                                    <td><span class="badge badge-subtle"><?= e($ev['event_type']) ?></span></td>
                                    <td>🏛️ <?= e($ev['venue']) ?></td>
                                    <td><?= date('d M Y', strtotime($ev['event_date'])) ?> &bull; <?= date('h:i A', strtotime($ev['event_time'])) ?></td>
                                    <td><span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;"><?= e($ev['status']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- LATEST ANNOUNCEMENTS -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="eyebrow">OFFICIAL DIRECTIVES</span>
                        <h2>Latest Announcements &amp; Protocol Alerts</h2>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:12px; padding:8px 0;">
                    <?php if (empty($announcements)): ?>
                        <p style="color:#64748b; font-size:13px;">No announcements published yet.</p>
                    <?php else: ?>
                        <?php foreach (array_slice($announcements, 0, 3) as $an): ?>
                            <div style="padding:14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                    <strong style="color:#0f172a; font-size:14px;"><?= e($an['title']) ?></strong>
                                    <small style="color:#64748b;"><?= date('d M Y, h:i A', strtotime($an['created_at'])) ?></small>
                                </div>
                                <p style="margin:0 0 8px; font-size:13px; color:#334155; line-height:1.5;"><?= nl2br(e($an['message'])) ?></p>
                                <small style="color:#64748b; font-size:11px;">Published by: <strong><?= e($an['publisher_name'] ?? 'BUP Command') ?></strong> (<?= e($an['publisher_role'] ?? 'Authority') ?>)</small>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

        <?php elseif ($activeTab === 'events'): ?>
            <!-- EVENTS VIEW -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="eyebrow">EVENT INFORMATION (VIEW ONLY)</span>
                        <h2>Scheduled BUP Protocol Events</h2>
                        <p style="margin:4px 0 0; font-size:13px; color:#64748b;">View schedules, locations, and descriptions. Direct edits or registrations are managed by authorized coordinators.</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Event Title</th>
                                <th>Type</th>
                                <th>Venue</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $ev): ?>
                            <tr>
                                <td>
                                    <strong><?= e($ev['title']) ?></strong>
                                    <?php if (!empty($ev['description'])): ?>
                                        <span class="table-subtext" style="color:#64748b; font-size:12px;"><?= e(mb_strimwidth($ev['description'], 0, 80, '...')) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge badge-subtle"><?= e($ev['event_type']) ?></span></td>
                                <td><strong><?= e($ev['venue']) ?></strong></td>
                                <td><?= date('d M Y', strtotime($ev['event_date'])) ?></td>
                                <td><?= date('h:i A', strtotime($ev['event_time'])) ?></td>
                                <td><span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;"><?= e($ev['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($activeTab === 'venue'): ?>
            <!-- CURRENT VENUE STATUS -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="eyebrow">CAMPUS FACILITIES</span>
                        <h2>Current Venue &amp; Facility Status</h2>
                        <p style="margin:4px 0 0; font-size:13px; color:#64748b;">Live overview of venues allocated for upcoming protocol functions.</p>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px;">
                    <div style="padding:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;">
                        <h3 style="margin:0 0 6px; font-size:16px; color:#082d21;">🏛️ BUP Grand Auditorium</h3>
                        <p style="margin:0 0 12px; font-size:12px; color:#64748b;">Main Campus Complex &bull; Capacity: 1,200 seats</p>
                        <div style="display:flex; justify-content:space-between; font-size:12px; padding:6px 0; border-top:1px solid #e2e8f0;">
                            <span>Setup Status:</span>
                            <strong style="color:#059669;">Ready / Inspected</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:12px; padding:6px 0; border-top:1px solid #e2e8f0;">
                            <span>Audio/Visual Check:</span>
                            <strong style="color:#059669;">PASSED</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:12px; padding:6px 0; border-top:1px solid #e2e8f0;">
                            <span>Active Allocations:</span>
                            <strong>Convocation &amp; Gala</strong>
                        </div>
                    </div>

                    <div style="padding:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;">
                        <h3 style="margin:0 0 6px; font-size:16px; color:#082d21;">🏢 Multi-Purpose Hall (MPH)</h3>
                        <p style="margin:0 0 12px; font-size:12px; color:#64748b;">Academic Building &bull; Capacity: 600 seats</p>
                        <div style="display:flex; justify-content:space-between; font-size:12px; padding:6px 0; border-top:1px solid #e2e8f0;">
                            <span>Setup Status:</span>
                            <strong style="color:#2563eb;">Configuring Stages</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:12px; padding:6px 0; border-top:1px solid #e2e8f0;">
                            <span>Air Conditioning:</span>
                            <strong style="color:#059669;">Operational</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:12px; padding:6px 0; border-top:1px solid #e2e8f0;">
                            <span>Active Allocations:</span>
                            <strong>Exhibition / Protocol</strong>
                        </div>
                    </div>

                    <div style="padding:18px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;">
                        <h3 style="margin:0 0 6px; font-size:16px; color:#082d21;">🌿 Parade Ground &amp; Open Plaza</h3>
                        <p style="margin:0 0 12px; font-size:12px; color:#64748b;">Outdoor Ceremonial Grounds &bull; Capacity: 3,000</p>
                        <div style="display:flex; justify-content:space-between; font-size:12px; padding:6px 0; border-top:1px solid #e2e8f0;">
                            <span>Cordon &amp; Barricades:</span>
                            <strong style="color:#059669;">Secured by Guard</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:12px; padding:6px 0; border-top:1px solid #e2e8f0;">
                            <span>Emergency Corridor:</span>
                            <strong style="color:#059669;">Open Gate 2</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:12px; padding:6px 0; border-top:1px solid #e2e8f0;">
                            <span>Active Allocations:</span>
                            <strong>Guard of Honour Drill</strong>
                        </div>
                    </div>
                </div>
            </section>

        <?php elseif ($activeTab === 'seating'): ?>
            <!-- CURRENT SEATING STATUS -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="eyebrow">SEATING TIERS (VIEW ONLY)</span>
                        <h2>Current Seating Configuration</h2>
                        <p style="margin:4px 0 0; font-size:13px; color:#64748b;">Visual overview of VIP, Executive, Faculty, and Student gallery seating zones.</p>
                    </div>
                </div>
                
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:16px; margin-bottom:20px;">
                    <div style="padding:14px; background:#fef3c7; border:1px solid #f59e0b; border-radius:8px;">
                        <strong style="color:#92400e; display:block;">Tier 1: VIP Stalls (Row A - B)</strong>
                        <span style="font-size:12px; color:#78350f;">Reserved for Chief Guest, VC, Pro-VC, and Dignitaries.</span>
                        <div style="margin-top:8px; font-size:12px; font-weight:700; color:#b45309;">Access: STRICT PROTOCOL BADGE</div>
                    </div>
                    <div style="padding:14px; background:#dbeafe; border:1px solid #3b82f6; border-radius:8px;">
                        <strong style="color:#1e40af; display:block;">Tier 2: Executive Stalls (Row C - E)</strong>
                        <span style="font-size:12px; color:#1e3a8a;">Reserved for Deans, Senior Military Officers, Invited Guests.</span>
                        <div style="margin-top:8px; font-size:12px; font-weight:700; color:#2563eb;">Access: SPECIAL INVITATION</div>
                    </div>
                    <div style="padding:14px; background:#d1fae5; border:1px solid #10b981; border-radius:8px;">
                        <strong style="color:#065f46; display:block;">Tier 3: Student &amp; Delegate Gallery (Row F - J)</strong>
                        <span style="font-size:12px; color:#064e3b;">Allocated for graduating students and registered attendees.</span>
                        <div style="margin-top:8px; font-size:12px; font-weight:700; color:#059669;">Access: BUP STUDENT ID PASS</div>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Venue</th>
                                <th>Allocated Seats</th>
                                <th>Gallery Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($seatingSummary as $ss): ?>
                            <tr>
                                <td><strong><?= e($ss['event_title']) ?></strong></td>
                                <td><?= e($ss['venue']) ?></td>
                                <td><?= (int)$ss['assigned_seats'] ?> Guests / Seats Assigned</td>
                                <td><span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;">Zone Allocated</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php elseif ($activeTab === 'announcements'): ?>
            <!-- ANNOUNCEMENTS VIEW -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="eyebrow">CAMPUS BULLETINS</span>
                        <h2>Announcements &amp; Official Notifications</h2>
                    </div>
                </div>
                <div style="display:flex; flex-direction:column; gap:16px;">
                    <?php foreach ($announcements as $an): ?>
                    <div style="padding:18px; background:#ffffff; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <strong style="font-size:16px; color:#082d21;"><?= e($an['title']) ?></strong>
                            <span style="font-size:12px; color:#64748b;"><?= date('d M Y, h:i A', strtotime($an['created_at'])) ?></span>
                        </div>
                        <p style="margin:0 0 10px; font-size:14px; color:#334155; line-height:1.6;"><?= nl2br(e($an['message'])) ?></p>
                        <div style="display:flex; gap:8px; align-items:center; font-size:12px; color:#64748b; border-top:1px solid #f1f5f9; padding-top:8px;">
                            <span>Officer: <strong><?= e($an['publisher_name'] ?? 'BUP Command') ?></strong></span>
                            <span>&bull;</span>
                            <span>Role: <strong><?= e($an['publisher_role'] ?? 'Authority') ?></strong></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>

        <?php elseif ($activeTab === 'emergency'): ?>
            <!-- EMERGENCY CONTACTS VIEW -->
            <section class="panel">
                <div class="panel-head">
                    <div>
                        <span class="eyebrow">IMMEDIATE ASSISTANCE</span>
                        <h2>BUP Event Emergency Contacts</h2>
                        <p style="margin:4px 0 0; font-size:13px; color:#64748b;">24/7 on-duty hotlines, medical responders, and security leads during official events.</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="user-table">
                        <thead>
                            <tr>
                                <th>Contact Person</th>
                                <th>Department</th>
                                <th>Designation</th>
                                <th>Direct Helpline</th>
                                <th>Duty Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emergencyContacts as $ec): ?>
                            <tr>
                                <td><strong><?= e($ec['name']) ?></strong></td>
                                <td><span class="badge badge-subtle"><?= e($ec['department']) ?></span></td>
                                <td><?= e($ec['designation']) ?></td>
                                <td>
                                    <a href="tel:<?= e($ec['phone']) ?>" style="font-weight:700; color:#0369a1; text-decoration:none;">
                                        📞 <?= e($ec['phone']) ?>
                                    </a>
                                </td>
                                <td><small style="color:#64748b;"><?= e($ec['notes'] ?? 'On-site duty') ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
