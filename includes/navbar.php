<?php
$user_id = (int)current_user()['id'];
$unread_count = get_unread_notification_count($user_id);
$notifications = get_user_notifications($user_id, 8);

$isStudent = is_student();
$isSupervisor = is_supervisor();

$lvl = current_level();
$isMil = is_military_protocol();

$milLvlBadges = [
    'L1' => ['bg' => '#06281e', 'color' => '#d4af37', 'label' => 'L1 &bull; HIGHER COMMAND'],
    'L2' => ['bg' => '#0e4a37', 'color' => '#fcf4db', 'label' => 'L2 &bull; DIVISION'],
    'L3' => ['bg' => '#146347', 'color' => '#ffffff', 'label' => 'L3 &bull; BRIGADE'],
    'L4' => ['bg' => '#1c4436', 'color' => '#bbf7d0', 'label' => 'L4 &bull; BATTALION'],
    'L5' => ['bg' => '#92610e', 'color' => '#fef3c7', 'label' => 'L5 &bull; COMPANY'],
    'L6' => ['bg' => '#78350f', 'color' => '#fde68a', 'label' => 'L6 &bull; PLATOON'],
    'L7' => ['bg' => '#0f5132', 'color' => '#d1e7dd', 'label' => 'L7 &bull; SECTION'],
    'L8' => ['bg' => '#134e4a', 'color' => '#a7f3d0', 'label' => 'L8 &bull; INDIVIDUAL SOLDIER'],
];

$civilLvlBadges = [
    'L1' => ['bg' => '#06281e', 'color' => '#d4af37', 'label' => 'L1 &bull; COMMAND'],
    'L2' => ['bg' => '#0e4a37', 'color' => '#fcf4db', 'label' => 'L2 &bull; COORDINATOR'],
    'L3' => ['bg' => '#146347', 'color' => '#ffffff', 'label' => 'L3 &bull; DEPT LEAD'],
    'L4' => ['bg' => '#92610e', 'color' => '#fef3c7', 'label' => 'L4 &bull; SUPERVISOR'],
    'L5' => ['bg' => '#0f5132', 'color' => '#d1e7dd', 'label' => 'L5 &bull; STAFF / VOLUNTEER'],
];

if ($isStudent) {
    $b = ['bg' => '#06281e', 'color' => '#d4af37', 'label' => 'VERIFIED STUDENT'];
} elseif ($isMil) {
    $b = $milLvlBadges[$lvl] ?? ['bg' => '#06281e', 'color' => '#d4af37', 'label' => $lvl . ' &bull; MILITARY'];
} elseif ($isSupervisor) {
    $b = ['bg' => '#92610e', 'color' => '#fef3c7', 'label' => 'L4 &bull; SUPERVISOR'];
} else {
    $b = $civilLvlBadges[$lvl] ?? ['bg' => '#06281e', 'color' => '#d4af37', 'label' => $lvl];
}

$userAvatar = current_user()['avatar'] ?? '';
$hasAvatar = !empty($userAvatar) && file_exists(__DIR__ . '/../' . $userAvatar);
?>

<header class="topbar">
  <a class="brand" href="<?= BASE_URL ?>/dashboard.php">
    <span class="brand-mark">B</span>
    <span>
      <strong>BUP Event &amp; Military Protocol</strong>
      <small>MANAGEMENT SYSTEM</small>
    </span>
  </a>

  <div class="topbar-actions" style="position:relative;">
    <!-- LEVEL BADGE -->
    <span class="badge" style="background:<?= $b['bg'] ?>; color:<?= $b['color'] ?>; font-size:11px; font-weight:800; letter-spacing:1.2px; font-family:monospace; padding:6px 12px; border-radius:6px; border:1px solid rgba(212,175,55,0.25);">
      <?= $b['label'] ?>
    </span>

    <?php 
    $proto_lvl = current_user()['protocol_level'] ?? '';
    $is_higher_cmd = ($proto_lvl === 'Higher Command' || (has_role('Admin') && !empty($proto_lvl)));
    $is_mil = is_military_protocol();
    ?>

    <?php if ($is_higher_cmd): ?>
      <a href="<?= BASE_URL ?>/manage_division.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:800; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
        <span>🎖️ Manage Division</span>
      </a>
      <a href="<?= BASE_URL ?>/calendar.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:700; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
        <span>📅 Event Calendar</span>
      </a>
      <a href="<?= BASE_URL ?>/rulebook_management.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:800; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
        <span>📖 Rulebook Management</span>
      </a>
    <?php elseif ($is_mil): ?>
      <?php if ($proto_lvl === 'Division'): ?>
        <a href="<?= BASE_URL ?>/manage_subordinates.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:800; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
          <span>🎖️ Brigade Accounts &amp; Duties</span>
        </a>
      <?php elseif ($proto_lvl === 'Brigade'): ?>
        <a href="<?= BASE_URL ?>/manage_subordinates.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:800; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
          <span>🎖️ Battalion Accounts &amp; Duties</span>
        </a>
      <?php elseif ($proto_lvl === 'Battalion'): ?>
        <a href="<?= BASE_URL ?>/manage_subordinates.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:800; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
          <span>🎖️ Company Accounts &amp; Civil Integration</span>
        </a>
      <?php elseif ($proto_lvl === 'Company'): ?>
        <a href="<?= BASE_URL ?>/manage_subordinates.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:800; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
          <span>🎖️ Platoon Accounts &amp; Duties</span>
        </a>
      <?php elseif ($proto_lvl === 'Platoon'): ?>
        <a href="<?= BASE_URL ?>/manage_subordinates.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:800; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
          <span>🎖️ Section Accounts &amp; Duties</span>
        </a>
      <?php elseif ($proto_lvl === 'Section'): ?>
        <a href="<?= BASE_URL ?>/manage_subordinates.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:800; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
          <span>🎖️ Assigned Soldier Accounts</span>
        </a>
      <?php elseif ($proto_lvl === 'Individual Soldier'): ?>
        <a href="<?= BASE_URL ?>/manage_subordinates.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:800; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
          <span>🎖️ Soldier Duty Pass &amp; Report</span>
        </a>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/calendar.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:700; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
        <span>📅 Event Calendar</span>
      </a>
      <a href="<?= BASE_URL ?>/rulebook_management.php" class="btn btn-sm" style="background:#082d21; color:#f5d98d; font-weight:700; border:1px solid #d4af37; display:flex; align-items:center; gap:6px; padding:6px 12px; text-decoration:none; border-radius:6px; font-size:12px;">
        <span>📖 Military SOP</span>
      </a>
    <?php elseif (is_event_director() || has_role('Admin')): ?>
      <a href="<?= BASE_URL ?>/protocol.php" class="btn btn-sm" style="background:linear-gradient(135deg, #d4af37 0%, #b89122 100%); color:#06281e; font-weight:800; border:none; display:flex; align-items:center; gap:6px; padding:6px 14px; text-decoration:none; border-radius:6px; box-shadow:0 2px 8px rgba(212,175,55,0.25);">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span>Military Protocol</span>
      </a>
    <?php endif; ?>

    <!-- INTERACTIVE NOTIFICATION BELL BUTTON -->
    <div style="position:relative;">
      <button 
        type="button" 
        id="notifToggleBtn" 
        title="Notifications &amp; Activity Alerts" 
        style="position:relative; width:38px; height:38px; border-radius:10px; background:#eaf3ee; border:1px solid #c2ded0; color:#082d21; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s;"
        onmouseover="this.style.background='#dcefe6'" 
        onmouseout="this.style.background='#eaf3ee'"
      >
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        <span 
          id="notifBadge" 
          style="position:absolute; top:-4px; right:-4px; background:#dc2626; color:#fff; font-size:10px; font-weight:800; border-radius:10px; min-width:18px; height:18px; display:<?= $unread_count > 0 ? 'inline-flex' : 'none' ?>; align-items:center; justify-content:center; padding:0 4px; border:2px solid #fff;"
        >
          <?= $unread_count ?>
        </span>
      </button>

      <!-- NOTIFICATIONS DROPDOWN MENU -->
      <div 
        id="notifDropdown" 
        style="display:none; position:absolute; right:0; top:48px; width:360px; max-width:92vw; background:#ffffff; border-radius:14px; border:1px solid #dce6e1; box-shadow:0 18px 40px rgba(8,45,33,0.18); z-index:9999; overflow:hidden;"
      >
        <div style="padding:14px 18px; background:linear-gradient(135deg, #051d14 0%, #082d21 100%); color:#ffffff; display:flex; justify-content:space-between; align-items:center;">
          <div>
            <strong style="font-size:14px; color:#ffffff;">Notifications &amp; Alerts</strong>
            <small id="notifSubtext" style="display:block; color:#f5d98d; font-size:10px; margin-top:1px;">
              <?= $unread_count ?> unread update<?= $unread_count === 1 ? '' : 's' ?>
            </small>
          </div>
          <button 
            type="button" 
            onclick="markAllNotificationsRead()" 
            style="background:rgba(212,175,55,0.18); border:1px solid rgba(212,175,55,0.3); color:#fef3c7; font-size:10px; font-weight:700; padding:4px 8px; border-radius:6px; cursor:pointer;"
          >
            Mark all read
          </button>
        </div>

        <div style="max-height:360px; overflow-y:auto; padding:6px 0;" id="notifListContainer">
          <?php if (empty($notifications)): ?>
            <div style="padding:26px 18px; text-align:center; color:#8da498; font-size:12px;">
              No notifications at this moment.
            </div>
          <?php else: ?>
            <?php foreach ($notifications as $n): ?>
              <?php
              $isRead = (int)$n['is_read'] === 1;
              $typeIcons = [
                  'duty_assigned' => '📋',
                  'duty_allocated' => '⚡',
                  'announcement' => '📢',
                  'login_logout' => '🛡️',
                  'security_alert' => '⚠️',
                  'system' => '🔔',
              ];
              $icon = $typeIcons[$n['type']] ?? '🔔';
              ?>
              <div 
                class="notif-item" 
                id="notif-row-<?= $n['id'] ?>" 
                style="padding:12px 16px; border-bottom:1px solid #edf2ef; display:flex; gap:12px; align-items:flex-start; background:<?= $isRead ? '#ffffff' : '#f4fbf7' ?>; transition:background 0.15s;"
              >
                <span style="font-size:16px; flex-shrink:0; margin-top:2px;"><?= $icon ?></span>
                <div style="flex:1; min-width:0;">
                  <div style="display:flex; justify-content:space-between; align-items:baseline; gap:6px;">
                    <strong style="font-size:12px; color:#082d21; line-height:1.3; font-weight:<?= $isRead ? '600' : '800' ?>;">
                      <?= e($n['title']) ?>
                    </strong>
                    <small style="color:#8da498; font-size:10px; white-space:nowrap;">
                      <?= e(date('h:i A', strtotime($n['created_at']))) ?>
                    </small>
                  </div>
                  <p style="margin:3px 0 6px; font-size:11px; color:#5e776a; line-height:1.4;">
                    <?= e($n['message']) ?>
                  </p>
                  <div style="display:flex; gap:8px; align-items:center;">
                    <?php if (!empty($n['link'])): ?>
                      <a href="<?= e($n['link']) ?>" style="font-size:11px; font-weight:700; color:#082d21; text-decoration:underline;">
                        View &rarr;
                      </a>
                    <?php endif; ?>
                    <button 
                      type="button" 
                      onclick="toggleNotificationRead(<?= $n['id'] ?>)" 
                      style="background:none; border:none; padding:0; color:#6b8577; font-size:10px; cursor:pointer; text-decoration:none;"
                    >
                      <?= $isRead ? 'Mark unread' : 'Mark read' ?>
                    </button>
                  </div>
                </div>
                <?php if (!$isRead): ?>
                  <span id="notif-dot-<?= $n['id'] ?>" style="width:8px; height:8px; border-radius:50%; background:#10b981; flex-shrink:0; margin-top:5px;"></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div style="padding:10px 16px; background:#f8fafc; border-top:1px solid #dce6e1; text-align:center;">
          <a href="<?= BASE_URL ?>/notifications.php" style="font-size:11px; font-weight:800; color:#082d21; text-decoration:none;">
            View All Announcements &amp; Circulars &rarr;
          </a>
        </div>
      </div>
    </div>

    <!-- USER CHIP WITH PROFILE PICTURE -->
    <a href="<?= BASE_URL ?>/profile.php" class="user-chip" style="text-decoration:none; cursor:pointer;">
      <?php if ($hasAvatar): ?>
        <img 
          src="<?= BASE_URL ?>/<?= e($userAvatar) ?>?v=<?= time() ?>" 
          alt="<?= e(current_user()['name']) ?>" 
          class="avatar" 
          style="width:34px; height:34px; border-radius:50%; object-fit:cover; border:1.5px solid #d4af37; box-shadow:0 2px 6px rgba(8,45,33,0.15);"
        >
      <?php else: ?>
        <span class="avatar"><?= e(strtoupper(substr(current_user()['name'],0,1))) ?></span>
      <?php endif; ?>

      <span>
        <strong><?= e($isSupervisor ? 'Supervisor' : current_user()['name']) ?></strong>
        <?php if ($isStudent): ?>
          <small>Student (<?= e(current_department()) ?>)</small>
        <?php elseif ($isSupervisor): ?>
          <small>Supervisor (Ground Supervision)</small>
        <?php else: ?>
          <small><?= e(current_user()['role']) ?> (<?= e(current_department()) ?>)</small>
        <?php endif; ?>
      </span>
    </a>

    <a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/logout.php" style="background:#eaf3ee; color:#06281e; font-weight:700; border:1px solid #d2e3db;">
      Logout
    </a>
  </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const btn = document.getElementById('notifToggleBtn');
  const dropdown = document.getElementById('notifDropdown');

  if (btn && dropdown) {
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.style.display = (dropdown.style.display === 'block') ? 'none' : 'block';
    });

    document.addEventListener('click', function (e) {
      if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
        dropdown.style.display = 'none';
      }
    });
  }
});

function toggleNotificationRead(id) {
  fetch('<?= BASE_URL ?>/api_notification_toggle.php?action=toggle_read&id=' + id, { method: 'POST' })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const badge = document.getElementById('notifBadge');
        const subtext = document.getElementById('notifSubtext');
        if (badge) {
          badge.textContent = data.unread;
          badge.style.display = data.unread > 0 ? 'inline-flex' : 'none';
        }
        if (subtext) {
          subtext.textContent = data.unread + ' unread update' + (data.unread === 1 ? '' : 's');
        }
        const row = document.getElementById('notif-row-' + id);
        const dot = document.getElementById('notif-dot-' + id);
        if (row) {
          if (row.style.background.includes('255, 255, 255') || row.style.background === 'rgb(255, 255, 255)') {
            row.style.background = '#f4fbf7';
            if (!dot) {
              const newDot = document.createElement('span');
              newDot.id = 'notif-dot-' + id;
              newDot.style.cssText = 'width:8px; height:8px; border-radius:50%; background:#10b981; flex-shrink:0; margin-top:5px;';
              row.appendChild(newDot);
            }
          } else {
            row.style.background = '#ffffff';
            if (dot) dot.remove();
          }
        }
      }
    });
}

function markAllNotificationsRead() {
  fetch('<?= BASE_URL ?>/api_notification_toggle.php?action=mark_all_read', { method: 'POST' })
    .then(res => res.json())
    .then(data => {
      if (data.success) {
        const badge = document.getElementById('notifBadge');
        const subtext = document.getElementById('notifSubtext');
        if (badge) badge.style.display = 'none';
        if (subtext) subtext.textContent = '0 unread updates';

        const rows = document.querySelectorAll('.notif-item');
        rows.forEach(r => {
          r.style.background = '#ffffff';
        });
        const dots = document.querySelectorAll('[id^="notif-dot-"]');
        dots.forEach(d => d.remove());
      }
    });
}
</script>
