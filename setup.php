<?php
/**
 * System Health Check & MongoDB Atlas Diagnostics
 * Replaces legacy MySQL setup.php.
 * Requires authorized Administrator session or CLI execution.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mongo.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (!is_logged_in() || !has_role('Admin') && !is_event_director()) {
        http_response_code(403);
        die('<div style="font-family:sans-serif; padding:40px; text-align:center; color:#991b1b;">
            <h2>403 Forbidden: Administrator Access Required</h2>
            <p>Database diagnostics and system health verification are restricted to authorized administrators.</p>
            <p><a href="' . BASE_URL . '/login.php">Log In as Administrator</a></p>
        </div>');
    }
}

$status = [];
$allHealthy = true;

// 1. Check MongoDB Atlas Connection Ping
try {
    $db = mdb();
    $pingRes = $db->command(['ping' => 1]);
    $status['mongodb_atlas_connection'] = ['status' => 'OK', 'details' => 'Cluster responded to ping successfully.'];
} catch (Throwable $e) {
    $status['mongodb_atlas_connection'] = ['status' => 'FAILED', 'details' => $e->getMessage()];
    $allHealthy = false;
}

// 2. Check Core Collections
$requiredCollections = [
    'users', 'events', 'guests', 'duties', 'protocol_items',
    'attendance', 'invitations', 'seating_assignments', 'transport',
    'supplies', 'media_documents', 'emergency_contacts', 'announcements',
    'audit_logs', 'military_sops', 'counters'
];

$existingColls = iterator_to_array($db->listCollectionNames());
$missingColls = array_diff($requiredCollections, $existingColls);

if (empty($missingColls)) {
    $status['collections_structure'] = ['status' => 'OK', 'details' => count($existingColls) . ' collections verified.'];
} else {
    $status['collections_structure'] = ['status' => 'WARNING', 'details' => 'Missing collections: ' . implode(', ', $missingColls)];
    $allHealthy = false;
}

// 3. Check User Accounts Count
$userCount = mcoll('users')->countDocuments();
if ($userCount >= 34) {
    $status['user_accounts'] = ['status' => 'OK', 'details' => "{$userCount} registered user accounts active."];
} else {
    $status['user_accounts'] = ['status' => 'WARNING', 'details' => "Found {$userCount} accounts (expected 34)."];
}

// 4. Check Sequence Counters
$countersCount = mcoll('counters')->countDocuments();
$status['sequence_counters'] = ['status' => 'OK', 'details' => "{$countersCount} collection sequences initialized."];

if ($isCli) {
    echo "=== SYSTEM HEALTH & MONGODB ATLAS DIAGNOSTICS ===\n\n";
    foreach ($status as $component => $info) {
        echo "  [{$info['status']}] {$component}: {$info['details']}\n";
    }
    echo "\nOVERALL STATUS: " . ($allHealthy ? "HEALTHY" : "NEEDS ATTENTION") . "\n";
    exit($allHealthy ? 0 : 1);
}

// Web presentation for administrator
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Health &amp; Diagnostics | BUP Protocol</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 40px 20px; }
        .card { max-width: 680px; margin: 0 auto; background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 12px rgba(0,0,0,0.05); padding: 32px; }
        h1 { color: #082d21; margin: 0 0 8px; font-size: 22px; }
        p { color: #64748b; font-size: 14px; margin-top: 0; }
        .row { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .badge { padding: 4px 10px; border-radius: 6px; font-weight: 700; font-size: 12px; }
        .badge.ok { background: #dcfce7; color: #166534; }
        .badge.warning { background: #fef3c7; color: #92400e; }
        .badge.failed { background: #fee2e2; color: #991b1b; }
        .btn { display: inline-block; margin-top: 24px; padding: 10px 18px; background: #082d21; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>MongoDB Atlas System Diagnostics</h1>
        <p>Operational health check of BUP Event &amp; Military Protocol Management System.</p>

        <?php foreach ($status as $component => $info): ?>
            <div class="row">
                <div>
                    <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $component))) ?></strong>
                    <div style="font-size:12px; color:#64748b;"><?= htmlspecialchars($info['details']) ?></div>
                </div>
                <span class="badge <?= strtolower($info['status']) ?>"><?= htmlspecialchars($info['status']) ?></span>
            </div>
        <?php endforeach; ?>

        <a class="btn" href="<?= BASE_URL ?>/dashboard.php">&larr; Return to Dashboard</a>
    </div>
</body>
</html>
