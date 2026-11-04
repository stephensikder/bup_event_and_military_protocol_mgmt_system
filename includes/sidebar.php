
<?php
$current_page = basename($_SERVER['PHP_SELF'] ?? '');

if (!function_exists('sidebar_link')) {
    function sidebar_link(string $file, string $label, string $current_page): void
    {
        $active = ($current_page === $file || strpos($current_page, $file) === 0);

        echo '<a href="' . e(BASE_URL . '/' . $file) . '"';
        echo $active ? ' class="active" aria-current="page"' : '';
        echo '>';
        echo '<span>' . e($label) . '</span>';
        echo '</a>';
    }
}
?>

<aside class="sidebar">

    <div style="margin: 0 0 20px; padding: 12px; background: rgba(255,255,255,0.06); border: 1px solid rgba(212,175,55,0.25); border-radius: 10px;">
        <span style="display:block; font-size:10px; font-weight:800; letter-spacing:1.5px; color:#d4af37; margin-bottom:4px;">CHAIN OF COMMAND</span>
        <?php if (is_student()): ?>
            <strong style="display:block; font-size:13px; color:#ffffff; line-height:1.2;">Verified Student</strong>
            <small style="display:block; color:#b7cbbf; font-size:11px; margin-top:2px;">BUP Student Account &bull; <?= e(current_department()) ?></small>
        <?php elseif (is_supervisor()): ?>
            <strong style="display:block; font-size:13px; color:#ffffff; line-height:1.2;">Supervisor</strong>
            <small style="display:block; color:#b7cbbf; font-size:11px; margin-top:2px;">Tier: <span style="color:#d4af37; font-weight:700;">L4</span> &bull; Ground Supervision</small>
        <?php elseif (is_staff()): ?>
            <strong style="display:block; font-size:13px; color:#ffffff; line-height:1.2;">Staff / Volunteer</strong>
            <small style="display:block; color:#b7cbbf; font-size:11px; margin-top:2px;">Tier: <span style="color:#d4af37; font-weight:700;">L5</span> &bull; Duty Execution</small>
        <?php elseif (is_military_protocol()): ?>
            <?php $mpl = current_user()['protocol_level'] ?? 'Military Command'; ?>
            <strong style="display:block; font-size:13px; color:#ffffff; line-height:1.2;"><?= e($mpl) ?></strong>
            <small style="display:block; color:#b7cbbf; font-size:11px; margin-top:2px;">Military Protocol &bull; <?= e(current_department() ?: 'Armed Forces') ?></small>
        <?php else: ?>
            <strong style="display:block; font-size:13px; color:#ffffff; line-height:1.2;"><?= e(current_user()['role']) ?></strong>
            <small style="display:block; color:#b7cbbf; font-size:11px; margin-top:2px;">Tier: <span style="color:#d4af37; font-weight:700;"><?= e(current_level()) ?></span> &bull; <?= e(current_department()) ?></small>
        <?php endif; ?>
    </div>

    <?php if (is_student()): ?>
        <!-- ==============================================
             STUDENT PORTAL SIDEBAR (VIEW-ONLY)
        =============================================== -->
        <div class="sidebar-section">
            <span class="sidebar-label">STUDENT PORTAL</span>
            <?php sidebar_link('student_dashboard.php?tab=overview', 'Dashboard Overview', $current_page); ?>
            <?php sidebar_link('student_dashboard.php?tab=events', 'Relevant Events', $current_page); ?>
            <?php sidebar_link('student_dashboard.php?tab=venue', 'Venue Status', $current_page); ?>
            <?php sidebar_link('student_dashboard.php?tab=seating', 'Seating Status', $current_page); ?>
            <?php sidebar_link('student_dashboard.php?tab=announcements', 'Announcements', $current_page); ?>
            <?php sidebar_link('student_dashboard.php?tab=emergency', 'Emergency Contacts', $current_page); ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>
        </div>

    <?php elseif (is_staff()): ?>
        <!-- ==============================================
             L5 STAFF / VOLUNTEER SIDEBAR
        =============================================== -->
        <div class="sidebar-section">
            <span class="sidebar-label">MY WORKSPACE</span>
            <?php sidebar_link('staff_dashboard.php', 'Staff Dashboard', $current_page); ?>
            <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
            <?php sidebar_link('events.php', 'Events', $current_page); ?>
            <?php sidebar_link('duties.php', 'My Assigned Duties', $current_page); ?>
            <?php sidebar_link('notifications.php', 'Announcements', $current_page); ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>
        </div>

    <?php elseif (is_supervisor()): ?>
        <!-- ==============================================
             L4 SUPERVISOR SIDEBAR (SPECIFIED)
             Events, Calendar, Duties, Reports, Profile
        =============================================== -->
        <div class="sidebar-section">
            <span class="sidebar-label">SUPERVISION</span>
            <?php sidebar_link('dashboard.php', 'Dashboard', $current_page); ?>
            <?php sidebar_link('events.php', 'Events', $current_page); ?>
            <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
            <?php sidebar_link('duties.php', 'Duties & Status', $current_page); ?>
            <?php sidebar_link('reports.php', 'Reports', $current_page); ?>
            <?php sidebar_link('notifications.php', 'Announcements', $current_page); ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>
        </div>

    <?php elseif (is_military_protocol()): ?>
        <!-- ==============================================
             MILITARY PROTOCOL SIDEBAR (8 DEDICATED TIERS)
        =============================================== -->
        <?php $pLevel = current_user()['protocol_level'] ?? ''; ?>
        <div class="sidebar-section">
            <span class="sidebar-label">MILITARY PROTOCOL &bull; <?= strtoupper(e($pLevel ?: 'COMMAND')) ?></span>
            <?php if ($pLevel === 'Higher Command'): ?>
                <?php sidebar_link('protocol.php', 'Command Hierarchy', $current_page); ?>
                <?php sidebar_link('manage_division.php', 'Manage Division Accounts', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=strategic_planning', 'Strategic Duty Planning', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=critical_issues', 'Critical Issue Review', $current_page); ?>
                <?php sidebar_link('rulebook_management.php', 'SOP / Rulebook Management', $current_page); ?>
                <?php sidebar_link('reports.php?tab=protocol', 'Protocol Reports & Analytics', $current_page); ?>
                <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
                <?php sidebar_link('notifications.php', 'Audit Logs & Alerts', $current_page); ?>
            <?php elseif ($pLevel === 'Division'): ?>
                <?php sidebar_link('protocol.php?unit_id=2', 'Division Dashboard', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php', 'Brigade Accounts & Duties', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=inter_brigade', 'Inter-Brigade Coordination', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=brigade_reports', 'Pending Brigade Reports', $current_page); ?>
                <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
                <?php sidebar_link('reports.php?tab=protocol', 'Division Protocol Report', $current_page); ?>
                <?php sidebar_link('rulebook_management.php', 'Military SOP Access', $current_page); ?>
                <?php sidebar_link('notifications.php', 'Notifications', $current_page); ?>
            <?php elseif ($pLevel === 'Brigade'): ?>
                <?php sidebar_link('protocol.php?unit_id=3', 'Brigade Dashboard', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php', 'Battalion Accounts & Duties', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=ceremonial_prep', 'Ceremonial Prep Tracker', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=battalion_coordination', 'Battalion Coordination', $current_page); ?>
                <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
                <?php sidebar_link('reports.php?tab=protocol', 'Brigade Protocol Report', $current_page); ?>
                <?php sidebar_link('rulebook_management.php', 'Military SOP Access', $current_page); ?>
                <?php sidebar_link('notifications.php', 'Notifications', $current_page); ?>
            <?php elseif ($pLevel === 'Battalion'): ?>
                <?php sidebar_link('protocol.php?unit_id=4', 'Battalion Dashboard', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php', 'Company Accounts & Civil Integration', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=company_progress', 'Company Progress Monitoring', $current_page); ?>
                <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
                <?php sidebar_link('reports.php?tab=protocol', 'Battalion Protocol Report', $current_page); ?>
                <?php sidebar_link('rulebook_management.php', 'Military SOP Access', $current_page); ?>
                <?php sidebar_link('notifications.php', 'Notifications', $current_page); ?>
            <?php elseif ($pLevel === 'Company'): ?>
                <?php sidebar_link('protocol.php?unit_id=5', 'Company Dashboard', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php', 'Platoon Accounts & Duties', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=platoon_progress', 'Platoon Progress Tracking', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=resource_allocation', 'Resource Allocation', $current_page); ?>
                <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
                <?php sidebar_link('reports.php?tab=protocol', 'Company Protocol Report', $current_page); ?>
                <?php sidebar_link('rulebook_management.php', 'Military SOP Access', $current_page); ?>
                <?php sidebar_link('notifications.php', 'Notifications', $current_page); ?>
            <?php elseif ($pLevel === 'Platoon'): ?>
                <?php sidebar_link('protocol.php?unit_id=6', 'Platoon Dashboard', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php', 'Section Accounts & Duties', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=section_progress', 'Section Progress Tracking', $current_page); ?>
                <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
                <?php sidebar_link('reports.php?tab=protocol', 'Platoon Protocol Report', $current_page); ?>
                <?php sidebar_link('rulebook_management.php', 'Military SOP Access', $current_page); ?>
                <?php sidebar_link('notifications.php', 'Notifications', $current_page); ?>
            <?php elseif ($pLevel === 'Section'): ?>
                <?php sidebar_link('protocol.php?unit_id=7', 'Section Dashboard', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php', 'Assigned Soldier Accounts', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php?tab=soldier_evidence', 'Soldier Evidence & Sign-Off', $current_page); ?>
                <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
                <?php sidebar_link('reports.php?tab=protocol', 'Section Protocol Report', $current_page); ?>
                <?php sidebar_link('rulebook_management.php', 'Military SOP Access', $current_page); ?>
                <?php sidebar_link('notifications.php', 'Notifications', $current_page); ?>
            <?php else: /* Individual Soldier */ ?>
                <?php sidebar_link('protocol.php?unit_id=8', 'Soldier Personal Desk', $current_page); ?>
                <?php sidebar_link('manage_subordinates.php', 'Soldier Duty Pass & Evidence', $current_page); ?>
                <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
                <?php sidebar_link('reports.php?tab=protocol', 'Soldier Duty Pass / Report', $current_page); ?>
                <?php sidebar_link('rulebook_management.php', 'Approved SOP Instructions', $current_page); ?>
                <?php sidebar_link('notifications.php', 'Notifications', $current_page); ?>
            <?php endif; ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>
        </div>

    <?php elseif (is_venue_lead()): ?>
        <!-- ==============================================
             L3 VENUE LEAD SIDEBAR
        =============================================== -->
        <div class="sidebar-section">
            <span class="sidebar-label">VENUE LEAD</span>
            <?php sidebar_link('dashboard.php', 'Dashboard', $current_page); ?>
            <?php sidebar_link('events.php', 'Events', $current_page); ?>
            <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
            <?php sidebar_link('seating.php', 'Seating', $current_page); ?>
            <?php sidebar_link('duties.php', 'Duties', $current_page); ?>
            <?php sidebar_link('reports.php', 'Reports', $current_page); ?>
            <?php sidebar_link('notifications.php', 'Announcements', $current_page); ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>
        </div>

    <?php elseif (is_security_lead()): ?>
        <!-- ==============================================
             L3 SECURITY LEAD SIDEBAR
             (Attendance removed, Seating removed, Incident merged into Reports)
        =============================================== -->
        <div class="sidebar-section">
            <span class="sidebar-label">SECURITY LEAD</span>
            <?php sidebar_link('dashboard.php', 'Dashboard', $current_page); ?>
            <?php sidebar_link('events.php', 'Events', $current_page); ?>
            <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
            <?php sidebar_link('duties.php', 'Security Duties', $current_page); ?>
            <?php sidebar_link('reports.php', 'Reports', $current_page); ?>
            <?php sidebar_link('notifications.php', 'Announcements', $current_page); ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>
        </div>

    <?php elseif (is_logistics_lead()): ?>
        <!-- ==============================================
             L3 LOGISTICS LEAD SIDEBAR
             (Dedicated supply_tracking.php, Seating removed)
        =============================================== -->
        <div class="sidebar-section">
            <span class="sidebar-label">LOGISTICS LEAD</span>
            <?php sidebar_link('dashboard.php', 'Dashboard', $current_page); ?>
            <?php sidebar_link('events.php', 'Events', $current_page); ?>
            <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
            <?php sidebar_link('duties.php', 'Duties', $current_page); ?>
            <?php sidebar_link('supply_tracking.php', 'Supply Tracking', $current_page); ?>
            <?php sidebar_link('transport.php', 'Transport', $current_page); ?>
            <?php sidebar_link('reports.php', 'Reports', $current_page); ?>
            <?php sidebar_link('notifications.php', 'Announcements', $current_page); ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>
        </div>

    <?php elseif (is_protocol_lead()): ?>
        <!-- ==============================================
             L3 PROTOCOL LEAD SIDEBAR
             (Guest/VIP merged into Seating, Event Calendar)
        =============================================== -->
        <div class="sidebar-section">
            <span class="sidebar-label">PROTOCOL LEAD</span>
            <?php sidebar_link('dashboard.php', 'Dashboard', $current_page); ?>
            <?php sidebar_link('events.php', 'Events', $current_page); ?>
            <?php sidebar_link('protocol.php', 'Protocol', $current_page); ?>
            <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
            <?php sidebar_link('seating.php', 'Seating', $current_page); ?>
            <?php sidebar_link('duties.php', 'Duties', $current_page); ?>
            <?php sidebar_link('reports.php', 'Reports', $current_page); ?>
            <?php sidebar_link('notifications.php', 'Announcements', $current_page); ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>
        </div>

    <?php elseif (is_communication_lead()): ?>
        <!-- ==============================================
             L3 COMMUNICATION LEAD SIDEBAR
             (Dedicated media_docs.php, Seating removed, Transport active)
        =============================================== -->
        <div class="sidebar-section">
            <span class="sidebar-label">COMMUNICATION LEAD</span>
            <?php sidebar_link('dashboard.php', 'Dashboard', $current_page); ?>
            <?php sidebar_link('events.php', 'Events', $current_page); ?>
            <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
            <?php sidebar_link('duties.php', 'Duties', $current_page); ?>
            <?php sidebar_link('media_docs.php', 'Media / Documentation', $current_page); ?>
            <?php sidebar_link('transport.php', 'Transport', $current_page); ?>
            <?php sidebar_link('notifications.php', 'Announcements', $current_page); ?>
            <?php sidebar_link('reports.php', 'Reports', $current_page); ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>
        </div>

    <?php else: ?>
        <!-- ==============================================
             DIRECTOR, COORDINATOR, ADMIN
        =============================================== -->
        <div class="sidebar-section">
            <span class="sidebar-label">OVERVIEW</span>
            <?php sidebar_link('dashboard.php', 'Dashboard', $current_page); ?>
            <?php sidebar_link('events.php', 'Events', $current_page); ?>
            <?php sidebar_link('calendar.php', 'Event Calendar', $current_page); ?>
        </div>

        <div class="sidebar-section">
            <span class="sidebar-label">OPERATIONS</span>
            <?php sidebar_link('guests.php', 'Guests & VIP', $current_page); ?>
            <?php sidebar_link('seating.php', 'Venue & Seating', $current_page); ?>
            <?php sidebar_link('duties.php', 'Duties', $current_page); ?>
            <?php sidebar_link('protocol.php', 'Military Protocol', $current_page); ?>
            <?php sidebar_link('attendance.php', 'Attendance / QR', $current_page); ?>
            <?php if (can_approve_events()): ?>
                <!-- Event Coordinator removed from approvals; only Event Director & Admin -->
                <?php sidebar_link('approvals.php', 'Approvals', $current_page); ?>
            <?php endif; ?>
        </div>

        <div class="sidebar-section">
            <span class="sidebar-label">COMMUNICATION</span>
            <?php sidebar_link('notifications.php', 'Announcements', $current_page); ?>
            <?php sidebar_link('invitations.php', 'Invitations', $current_page); ?>
            <?php sidebar_link('emergency.php', 'Emergency Contacts', $current_page); ?>
        </div>

        <div class="sidebar-section">
            <span class="sidebar-label">REPORTING & ACCOUNT</span>
            <?php sidebar_link('reports.php', 'Reports & Analytics', $current_page); ?>
            <?php sidebar_link('profile.php', 'My Profile', $current_page); ?>

            <?php if (can_create_accounts()): ?>
                <?php sidebar_link('users.php', 'Account Management', $current_page); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</aside>

<style>
/* Sidebar visual improvements */
body:has(.app-shell) .sidebar {
    background: linear-gradient(180deg, #051d14 0%, #082d21 40%, #0d3829 100%);
    color: #e3f2ea;
    padding: 24px 14px;
    box-sizing: border-box;
    border-right: 1px solid rgba(212, 175, 55, 0.2);
}

body:has(.app-shell) .sidebar-section {
    margin: 0 0 28px;
}

body:has(.app-shell) .sidebar-label {
    display: block;
    padding: 0 12px 10px;
    color: #d4af37;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.5px;
    line-height: 1.6;
}

body:has(.app-shell) .sidebar a {
    display: flex;
    align-items: center;
    min-height: 44px;
    margin: 4px 0;
    padding: 10px 13px;
    border: 1px solid transparent;
    border-radius: 9px;
    color: #c7ded2;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.5;
    text-decoration: none;
    transition: background .2s ease, color .2s ease, border-color .2s ease;
}

body:has(.app-shell) .sidebar a span {
    min-width: 0;
    overflow-wrap: anywhere;
}

body:has(.app-shell) .sidebar a:hover {
    background: rgba(255, 255, 255, .08);
    color: #ffffff;
    border-color: rgba(212, 175, 55, 0.15);
}

body:has(.app-shell) .sidebar a.active {
    color: #ffffff;
    font-weight: 700;
    background: rgba(212, 175, 55, .16);
    border-color: rgba(212, 175, 55, .3);
    box-shadow: inset 3px 0 0 #d4af37;
}

body:has(.app-shell) .sidebar a:focus-visible {
    outline: 2px solid #d4af37;
    outline-offset: 2px;
}

@media (max-width: 1000px) {
    body:has(.app-shell) .sidebar {
        padding: 20px 10px;
    }

    body:has(.app-shell) .sidebar a {
        padding: 10px 11px;
        font-size: 13px;
    }
}
</style>

<script>
(function () {
    const sidebar = document.querySelector('.sidebar');

    if (!sidebar) return;

    // Restore sidebar position after page navigation
    const storageKey = 'bup_sidebar_scroll';

    const savedPosition = sessionStorage.getItem(storageKey);

    if (savedPosition !== null) {
        sidebar.scrollTop = Number(savedPosition);
    }

    // Save position before navigating to another page
    sidebar.querySelectorAll('a[href]').forEach(function (link) {
        link.addEventListener('click', function () {
            sessionStorage.setItem(
                storageKey,
                String(sidebar.scrollTop)
            );
        });
    });

    // Keep the position updated while scrolling
    sidebar.addEventListener('scroll', function () {
        sessionStorage.setItem(
            storageKey,
            String(sidebar.scrollTop)
        );
    }, { passive: true });
})();
</script>
