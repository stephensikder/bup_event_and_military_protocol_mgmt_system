<?php
require_once __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    redirect(get_role_redirect_url());
}

$pdo = db();
$error = null;
$success = null;

$name = trim($_POST['name'] ?? '');
$student_id = trim($_POST['student_id'] ?? '');
$email = trim($_POST['email'] ?? '');
$department = trim($_POST['department'] ?? 'Faculty of Science & Technology (FST)');
$phone = trim($_POST['phone'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    if ($name === '' || $student_id === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match. Please verify.';
    } else {
        // Check if email already registered
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $checkStmt->execute([$email]);
        if ($checkStmt->fetch()) {
            $error = 'An account with this email address already exists. Please sign in or use another email.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users 
                    (name, email, password_hash, role, level, department, designation, student_id, phone, status, failed_attempts)
                    VALUES (?, ?, ?, 'Student', 'L5', ?, ?, ?, ?, 'Pending', 0)
                ");
                $designation = 'BUP Student (' . $student_id . ')';
                $stmt->execute([
                    $name,
                    $email,
                    $hash,
                    $department,
                    $designation,
                    $student_id,
                    $phone
                ]);
                $newUserId = (int)$pdo->lastInsertId();

                // Notify admin via announcement / audit log
                $adminStmt = $pdo->prepare("
                    INSERT INTO announcements (title, message, created_by)
                    VALUES (?, ?, ?)
                ");
                $adminStmt->execute([
                    'New Student Registration: ' . $name . ' (' . $student_id . ')',
                    'A new student account registration was submitted by ' . $name . ' (' . $email . ', Dept: ' . $department . ', ID: ' . $student_id . '). Status: PENDING APPROVAL.',
                    1 // Admin user ID
                ]);

                // Audit log
                $audit = $pdo->prepare("
                    INSERT INTO audit_logs (user_id, action, description)
                    VALUES (?, 'Student Registration', ?)
                ");
                $audit->execute([
                    $newUserId,
                    'Student submitted registration for ID: ' . $student_id . ' (' . $email . '). Awaiting Admin approval.'
                ]);

                // Sync with MongoDB
                mongo_sync_table('users');
                mongo_sync_table('announcements');
                mongo_sync_table('audit_logs');

                flash('success', 'Registration submitted successfully! Your account is currently PENDING Admin approval. Once approved, you can sign in with your credentials.');
                redirect(BASE_URL . '/login.php');
            } catch (Exception $e) {
                $error = 'Registration failed: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'Student Account Registration | BUP Protocol';
require __DIR__ . '/includes/header.php';
?>

<div class="login-wrapper">

    <!-- FLOATING BRAND LOGO -->
    <a href="<?= BASE_URL ?>/index.php" class="login-header-brand">
        <div class="brand-crest">B</div>
        <div class="brand-details">
            <strong>BUP Event &amp; Military Protocol</strong>
            <small>STUDENT REGISTRATION PORTAL</small>
        </div>
    </a>

    <!-- MODERN REGISTRATION CARD -->
    <div class="modern-login-card" style="width:min(100%, 560px);">

        <div class="card-intro">
            <span class="secure-badge">
                <span class="pulse-dot"></span>
                STUDENT ACCESS VERIFICATION
            </span>
            <h1>Create Student Account</h1>
            <p>Register for authenticated view-only access to official BUP event schedules, venue statuses, and seating plans.</p>
        </div>

        <!-- ERROR NOTICES -->
        <?php if ($error): ?>
            <div class="modern-alert alert-error">
                <span class="alert-icon">!</span>
                <div>
                    <strong>Registration Error</strong>
                    <p><?= e($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- REGISTRATION FORM -->
        <form method="post" class="modern-form">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div class="form-group">
                    <label class="form-label">Full Name *</label>
                    <div class="input-container">
                        <input
                            type="text"
                            name="name"
                            value="<?= e($name) ?>"
                            required
                            placeholder="e.g. Tanvir Ahmed"
                            class="form-control"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Student ID *</label>
                    <div class="input-container">
                        <input
                            type="text"
                            name="student_id"
                            value="<?= e($student_id) ?>"
                            required
                            placeholder="e.g. 2105140"
                            class="form-control"
                        >
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Institutional / Personal Email *</label>
                <div class="input-container">
                    <input
                        type="email"
                        name="email"
                        value="<?= e($email) ?>"
                        required
                        placeholder="student@bup.edu.bd"
                        class="form-control"
                    >
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div class="form-group">
                    <label class="form-label">Faculty / Department *</label>
                    <div class="input-container">
                        <select name="department" class="form-control" style="cursor:pointer;">
                            <option value="Faculty of Science & Technology (FST)" <?= $department === 'Faculty of Science & Technology (FST)' ? 'selected' : '' ?>>FST (Science & Tech)</option>
                            <option value="Faculty of Business Studies (FBS)" <?= $department === 'Faculty of Business Studies (FBS)' ? 'selected' : '' ?>>FBS (Business Studies)</option>
                            <option value="Faculty of Arts & Social Sciences (FASS)" <?= $department === 'Faculty of Arts & Social Sciences (FASS)' ? 'selected' : '' ?>>FASS (Arts & Social)</option>
                            <option value="Faculty of Security & Strategic Studies (FSSS)" <?= $department === 'Faculty of Security & Strategic Studies (FSSS)' ? 'selected' : '' ?>>FSSS (Security & Strategic)</option>
                            <option value="General Student Body" <?= $department === 'General Student Body' ? 'selected' : '' ?>>General Student Body</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Mobile Number</label>
                    <div class="input-container">
                        <input
                            type="text"
                            name="phone"
                            value="<?= e($phone) ?>"
                            placeholder="017XXXXXXXX"
                            class="form-control"
                        >
                    </div>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div class="form-group">
                    <label class="form-label">Password *</label>
                    <div class="input-container">
                        <input
                            type="password"
                            name="password"
                            required
                            minlength="6"
                            placeholder="Min. 6 chars"
                            class="form-control"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Confirm Password *</label>
                    <div class="input-container">
                        <input
                            type="password"
                            name="password_confirm"
                            required
                            minlength="6"
                            placeholder="Repeat password"
                            class="form-control"
                        >
                    </div>
                </div>
            </div>

            <div style="background:#eaf8f2; border:1px solid #b6e4cc; border-radius:10px; padding:12px 16px; margin:6px 0 16px; font-size:12px; color:#0e4a37; line-height:1.5;">
                <strong style="color:#082d21;">Approval Policy:</strong> All student accounts are created with <strong>PENDING</strong> status and require verification and approval by the BUP System Administrator before access is enabled.
            </div>

            <button type="submit" class="btn-submit-login">
                <span>Submit Student Registration</span>
                <span class="btn-arrow">&rarr;</span>
            </button>
        </form>

        <!-- ALREADY REGISTERED PROMPT -->
        <div class="student-prompt-box">
            <span>Already have an approved account?</span>
            <a href="<?= BASE_URL ?>/login.php" class="student-register-link">
                Sign In to Command Center &rarr;
            </a>
        </div>

        <!-- SECURITY FOOTPRINT -->
        <div class="security-footprint">
            <span class="lock-shield">🛡️</span>
            <span>256-bit encrypted session &bull; Verified student credentials</span>
        </div>

    </div>

    <!-- BACK TO HOME NAVIGATION -->
    <a class="nav-back-home" href="<?= BASE_URL ?>/index.php">
        &larr; Return to Official Portal Home
    </a>

    <!-- SYSTEM FOOTER -->
    <div class="login-site-footer">
        Bangladesh University of Professionals &bull; Mirpur Cantonment, Dhaka
    </div>

</div>

<style>
body {
    background: #f1f5f9;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #0f172a;
    margin: 0;
}

.login-wrapper {
    min-height: calc(100vh - 40px);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    background: radial-gradient(circle at 50% 10%, rgba(212, 175, 55, 0.12) 0%, transparent 60%),
                radial-gradient(circle at 90% 90%, rgba(10, 54, 39, 0.10) 0%, transparent 50%),
                #f4f8f6;
}

.login-header-brand {
    display: flex;
    align-items: center;
    gap: 14px;
    text-decoration: none;
    margin-bottom: 26px;
}

.brand-crest {
    width: 46px;
    height: 46px;
    background: linear-gradient(135deg, #062b1f 0%, #105940 100%);
    border: 1.5px solid #d4af37;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #d4af37;
    font-size: 24px;
    font-weight: 900;
    box-shadow: 0 6px 18px rgba(8, 45, 33, 0.2);
}

.brand-details strong {
    display: block;
    font-size: 18px;
    font-weight: 900;
    color: #082d21;
    letter-spacing: -0.3px;
    line-height: 1.2;
}

.brand-details small {
    display: block;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.5px;
    color: #5e776a;
}

.modern-login-card {
    background: #ffffff;
    border-radius: 20px;
    padding: 38px 36px;
    border: 1px solid #dce6e1;
    box-shadow: 0 20px 45px -12px rgba(8, 45, 33, 0.12);
}

.card-intro {
    text-align: left;
    margin-bottom: 24px;
}

.secure-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #eaf8f2;
    border: 1px solid #bbf7d0;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 1.5px;
    color: #0d4a37;
    margin-bottom: 12px;
}

.pulse-dot {
    width: 6px;
    height: 6px;
    background: #10b981;
    border-radius: 50%;
    box-shadow: 0 0 8px #10b981;
}

.card-intro h1 {
    font-size: 26px;
    font-weight: 900;
    color: #082d21;
    margin: 0 0 6px;
    letter-spacing: -0.5px;
}

.card-intro p {
    font-size: 14px;
    color: #5e776a;
    margin: 0;
    line-height: 1.5;
}

.modern-alert {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 14px 16px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.45;
}

.alert-error {
    background: #fef2f2;
    border: 1px solid #ef4444;
    color: #991b1b;
}

.alert-icon {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 12px;
    flex-shrink: 0;
    margin-top: 1px;
}

.alert-error .alert-icon {
    background: #fee2e2;
    color: #b91c1c;
}

.form-group {
    margin-bottom: 16px;
}

.form-label {
    display: block;
    font-size: 13px;
    font-weight: 700;
    color: #082d21;
    margin-bottom: 6px;
}

.input-container {
    position: relative;
    display: flex;
    align-items: center;
}

.form-control {
    width: 100%;
    height: 48px;
    padding: 0 16px;
    font-size: 14px;
    color: #0f172a;
    background: #fdfdfd;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    outline: none;
    box-sizing: border-box;
    transition: all 0.2s ease;
}

.form-control:focus {
    border-color: #d4af37;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.2);
}

.btn-submit-login {
    width: 100%;
    height: 50px;
    margin-top: 10px;
    background: linear-gradient(135deg, #082d21 0%, #13533e 100%);
    color: #ffffff;
    border: 1px solid #16654a;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(8, 45, 33, 0.2);
    transition: all 0.2s ease;
}

.btn-submit-login:hover {
    background: linear-gradient(135deg, #0b3d2d 0%, #17684d 100%);
    border-color: #d4af37;
    box-shadow: 0 10px 24px rgba(8, 45, 33, 0.28);
    transform: translateY(-1px);
}

.btn-arrow {
    font-size: 18px;
    transition: transform 0.2s;
}

.btn-submit-login:hover .btn-arrow {
    transform: translateX(4px);
}

.student-prompt-box {
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13px;
    color: #5e776a;
}

.student-register-link {
    color: #0b5e3f;
    font-weight: 800;
    text-decoration: none;
    transition: color 0.15s;
}

.student-register-link:hover {
    color: #d4af37;
    text-decoration: underline;
}

.security-footprint {
    margin-top: 20px;
    padding: 10px;
    background: #f4f8f6;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 11px;
    color: #5e776a;
}

.nav-back-home {
    margin-top: 24px;
    font-size: 13px;
    font-weight: 700;
    color: #5e776a;
    text-decoration: none;
    transition: color 0.2s;
}

.nav-back-home:hover {
    color: #082d21;
}

.login-site-footer {
    margin-top: 12px;
    font-size: 11px;
    color: #8da498;
}

@media (max-width: 600px) {
    .modern-login-card {
        padding: 26px 20px;
    }
    .modern-form div[style*="grid-template-columns"] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
