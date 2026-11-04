<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/auth.php';

if (is_logged_in()) {
    redirect(get_role_redirect_url());
}

$error = null;
$email = trim($_POST['email'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both your institutional email address and password.';
    } elseif (login_user($email, $password)) {
        redirect(get_role_redirect_url());
    } else {
        $error = get_login_error() ?: 'Invalid email or password, or your account is not yet active.';
    }
}

$flash = get_flash();
$hide_header_flash = true;
$page_title = 'Secure Command Login | BUP Protocol';
require __DIR__ . '/includes/header.php';
?>

<div class="login-wrapper">

    <!-- FLOATING BRAND LOGO -->
    <a href="<?= BASE_URL ?>/index.php" class="login-header-brand">
        <div class="brand-crest">B</div>
        <div class="brand-details">
            <strong>BUP Event &amp; Military Protocol</strong>
            <small>INSTITUTIONAL COMMAND PORTAL</small>
        </div>
    </a>

    <!-- MODERN LOGIN CARD -->
    <div class="modern-login-card">

        <div class="card-intro">
            <span class="secure-badge">
                <span class="pulse-dot"></span>
                AUTHENTICATED ACCESS
            </span>
            <h1>Welcome Back</h1>
            <p>Enter your credentials to access your authorized protocol and duty desk.</p>
        </div>

        <!-- FLASH NOTICES -->
        <?php if (!empty($flash)): ?>
            <div class="modern-alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error' ?>">
                <span class="alert-icon"><?= $flash['type'] === 'success' ? '✓' : '!' ?></span>
                <div>
                    <strong><?= $flash['type'] === 'success' ? 'Notice' : 'Notice' ?></strong>
                    <p><?= e($flash['message']) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- LOGIN ERRORS -->
        <?php if ($error): ?>
            <div class="modern-alert alert-error">
                <span class="alert-icon">!</span>
                <div>
                    <strong>Authentication Error</strong>
                    <p><?= e($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- CREDENTIAL FORM -->
        <form method="post" class="modern-form">
            <div class="form-group">
                <label for="loginEmail" class="form-label">Institutional Email</label>
                <div class="input-container">
                    <span class="field-icon">✉</span>
                    <input
                        type="email"
                        id="loginEmail"
                        name="email"
                        value="<?= e($email) ?>"
                        required
                        autocomplete="username"
                        placeholder="officer@bup.local"
                        class="form-control"
                    >
                </div>
            </div>

            <div class="form-group">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label for="loginPassword" class="form-label" style="margin-bottom:0;">Password</label>
                    <button type="button" class="btn-toggle-pass" id="togglePassword">Show</button>
                </div>
                <div class="input-container">
                    <span class="field-icon">🔒</span>
                    <input
                        id="loginPassword"
                        type="password"
                        name="password"
                        required
                        autocomplete="current-password"
                        placeholder="••••••••••••"
                        class="form-control"
                    >
                </div>
            </div>

            <button type="submit" class="btn-submit-login">
                <span>Sign In to Command Center</span>
                <span class="btn-arrow">&rarr;</span>
            </button>
        </form>

        <!-- STUDENT REGISTRATION LINK -->
        <div class="student-prompt-box">
            <span>Are you a BUP Student?</span>
            <a href="<?= BASE_URL ?>/register.php" class="student-register-link">
                Register Student Account &rarr;
            </a>
        </div>

        <!-- SECURITY FOOTPRINT -->
        <div class="security-footprint">
            <span class="lock-shield">🛡️</span>
            <span>256-bit encrypted session &bull; Role-scoped command privileges</span>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const passwordInput = document.getElementById('loginPassword');
    const toggleButton = document.getElementById('togglePassword');

    if (passwordInput && toggleButton) {
        toggleButton.addEventListener('click', function () {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            toggleButton.textContent = isPassword ? 'Hide' : 'Show';
        });
    }
});
</script>

<style>
/* =========================================================
   MODERN EXECUTIVE LOGIN PORTAL STYLING
========================================================= */

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
    width: min(100%, 450px);
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

.alert-success {
    background: #ecfdf5;
    border: 1px solid #10b981;
    color: #065f46;
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

.alert-success .alert-icon {
    background: #d1fae5;
    color: #047857;
}

.alert-error .alert-icon {
    background: #fee2e2;
    color: #b91c1c;
}

.modern-form {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-label {
    font-size: 13px;
    font-weight: 700;
    color: #1a382b;
    margin-bottom: 6px;
}

.input-container {
    position: relative;
    display: flex;
    align-items: center;
}

.field-icon {
    position: absolute;
    left: 14px;
    color: #8da498;
    font-size: 14px;
    pointer-events: none;
}

.form-control {
    width: 100%;
    height: 48px;
    padding: 0 16px 0 42px;
    border: 1.5px solid #d4dfd8;
    border-radius: 10px;
    font-size: 14px;
    color: #0d1e17;
    background: #fcfdfd;
    box-sizing: border-box;
    transition: all 0.2s ease;
    outline: none;
}

.form-control:focus {
    background: #ffffff;
    border-color: #16654a;
    box-shadow: 0 0 0 3px rgba(22, 101, 74, 0.12);
}

.btn-toggle-pass {
    background: none;
    border: none;
    color: #107553;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    padding: 0;
}

.btn-toggle-pass:hover {
    color: #082d21;
    text-decoration: underline;
}

.btn-submit-login {
    height: 50px;
    background: linear-gradient(135deg, #082d21 0%, #13533e 100%);
    color: #ffffff;
    border: 1.5px solid #16654a;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(8, 45, 33, 0.2);
    transition: all 0.2s ease;
    margin-top: 6px;
}

.btn-submit-login:hover {
    background: linear-gradient(135deg, #0b3d2d 0%, #17684d 100%);
    border-color: #d4af37;
    transform: translateY(-1px);
    box-shadow: 0 8px 22px rgba(8, 45, 33, 0.28);
}

.btn-submit-login .btn-arrow {
    transition: transform 0.2s ease;
}

.btn-submit-login:hover .btn-arrow {
    transform: translateX(4px);
}

.student-prompt-box {
    margin-top: 22px;
    padding: 14px 16px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 13px;
}

.student-prompt-box span {
    color: #166534;
    font-weight: 600;
}

.student-register-link {
    color: #15803d;
    font-weight: 800;
    text-decoration: none;
    transition: color 0.2s;
}

.student-register-link:hover {
    color: #0d4a37;
    text-decoration: underline;
}

.security-footprint {
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 11px;
    color: #7e9589;
    text-align: center;
}

.nav-back-home {
    margin-top: 22px;
    color: #5e776a;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s;
}

.nav-back-home:hover {
    color: #082d21;
}

.login-site-footer {
    margin-top: 18px;
    font-size: 11px;
    color: #7e9589;
    text-align: center;
}

@media (max-width: 480px) {
    .modern-login-card {
        padding: 26px 20px;
    }
    .student-prompt-box {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
