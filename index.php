<?php
require_once __DIR__ . '/config/config.php';
$page_title = 'BUP Protocol | Event & Military Protocol Management System';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="BUP Event & Military Protocol Management System — centralized event coordination, military protocol, duties, attendance, and reporting.">
    <!-- BUP Event & Military Protocol -->
    <title><?= e($page_title) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= time() ?>">
</head>

<body class="landing">

<!-- =========================================================
     NAVBAR (STANDARD BUP THEME)
========================================================= -->
<header class="landing-nav">
    <a class="brand brand-dark" href="<?= BASE_URL ?>/index.php">
        <span class="brand-mark">
            <span>B</span>
        </span>
        <span class="brand-text">
            <strong>BUP Event &amp; Military Protocol</strong>
            <small>MANAGEMENT SYSTEM</small>
        </span>
    </a>

    <nav class="landing-menu">
        <a href="#home">Home</a>
        <a href="#features">Platform Features</a>
        <a href="<?= BASE_URL ?>/protocol.php">Military Protocol</a>

        <a class="nav-login" style="background:#0d4a37; border:1.5px solid #d4af37; color:#fdf6e2 !important; margin-right:6px;" href="<?= BASE_URL ?>/register.php">
            Student Portal
            <span>🎓</span>
        </a>

        <a class="nav-login" href="<?= BASE_URL ?>/login.php">
            Secure Login
            <span>&rarr;</span>
        </a>
    </nav>
</header>

<main>

<!-- =========================================================
     HERO SECTION (BUP CAMPUS PHOTO BACKGROUND - CENTERED)
========================================================= -->
<section id="home" class="hero hero-modern" style="display:flex; align-items:center; justify-content:center; text-align:center;">
    <div class="hero-content">
        <div class="hero-kicker">
            <span class="kicker-line"></span>
            “EXCELLENCE THROUGH KNOWLEDGE” &bull; BANGLADESH UNIVERSITY OF PROFESSIONALS
            <span class="kicker-line"></span>
        </div>

        <h1>
            BUP EVENT AND MILITARY PROTOCOL
            <span>MANAGEMENT SYSTEM</span>
        </h1>

        <p class="hero-description">
            “Precision in Protocol &bull; Flawless Execution &bull; Supreme Ceremonial Standards”
            <br><br>
            A centralized institutional command platform for academic convocations,
            official state delegations, ceremonial military parades, operational duty alignment,
            and verified campus security.
        </p>

        <div class="hero-actions">
            <a class="btn btn-primary btn-lg" href="<?= BASE_URL ?>/login.php">
                Access Command Center
                <span class="btn-arrow">&rarr;</span>
            </a>

            <a class="nav-login" style="background:linear-gradient(135deg, #0a4030 0%, #125e46 100%); border:1.5px solid #d4af37; padding:12px 24px; font-size:14px; font-weight:700; border-radius:8px; display:inline-flex; align-items:center; gap:8px; color:#fdf6e2 !important; box-shadow:0 4px 16px rgba(0,0,0,0.2);" href="<?= BASE_URL ?>/register.php">
                Student Portal
                <span>🎓</span>
            </a>

            <a class="hero-secondary-btn" href="<?= BASE_URL ?>/protocol.php">
                Military Protocol SOPs
                <span>📖</span>
            </a>
        </div>

        <div class="hero-trust">
            <div class="trust-item">
                <strong>8 Levels</strong>
                <span>Military Command Hierarchy</span>
            </div>
            <div class="trust-divider"></div>
            <div class="trust-item">
                <strong>14 Columns</strong>
                <span>Cross-Department Alignment</span>
            </div>
            <div class="trust-divider"></div>
            <div class="trust-item">
                <strong>Strict RBAC</strong>
                <span>Role-Based Security Bounds</span>
            </div>
        </div>
    </div>
</section>

<!-- =========================================================
     CORE OPERATIONAL PLATFORM CAPABILITIES
========================================================= -->
<section id="features" class="section landing-section" style="padding: 80px 7vw; background: #f8fafc;">
    <div class="section-heading centered" style="text-align: center; max-width: 800px; margin: 0 auto 50px;">
        <span class="eyebrow" style="color: #d97706; font-size: 11px; font-weight: 800; letter-spacing: 2px;">
            SYSTEM CAPABILITIES
        </span>
        <h2 style="font-size: 32px; font-weight: 900; color: #0f172a; margin: 12px 0 16px; letter-spacing: -0.5px;">
            Everything your event command team needs in one place.
        </h2>
        <p style="font-size: 15px; color: #64748b; line-height: 1.6; margin: 0;">
            Engineered as a secure command and coordination ecosystem for events, protocol,
            guest logistics, duty scheduling, and institutional governance.
        </p>
    </div>

    <div class="feature-grid-modern">
        <!-- 01: Military Protocol -->
        <article class="feature-card-modern">
            <span class="feature-number">TIER 1 &bull; COMMAND CHAIN</span>
            <div class="feature-symbol">🎖️</div>
            <h3>Military Protocol &amp; SOPs</h3>
            <p>
                Interactive 8-level military hierarchy from Higher Command down to Individual Soldier.
                Features downward duty dispatch, upward incident escalation, and approved Military SOP Rulebooks.
            </p>
            <a href="<?= BASE_URL ?>/protocol.php">Explore Protocol Hierarchy &rarr;</a>
        </article>

        <!-- 02: Duty Alignment Table -->
        <article class="feature-card-modern">
            <span class="feature-number">TIER 2 &bull; COORDINATION DECK</span>
            <div class="feature-symbol">📋</div>
            <h3>Cross-Department Duty Alignment</h3>
            <p>
                14-column synchronization grid for the Event Coordinator. Displays departments, leads, assigned supervisors,
                scheduled hours, priority tiers, and automatic schedule conflict alerts.
            </p>
            <a href="<?= BASE_URL ?>/login.php">Open Duty Deck &rarr;</a>
        </article>

        <!-- 03: Student Portal -->
        <article class="feature-card-modern">
            <span class="feature-number">STUDENT SERVICES</span>
            <div class="feature-symbol">🎓</div>
            <h3>Student Portal &amp; Registration</h3>
            <p>
                Self-service student registration workflow with Administrator verification. View-only access
                to official event schedules, current venue status, seating plans, and emergency contacts.
            </p>
            <a href="<?= BASE_URL ?>/register.php">Student Portal Access &rarr;</a>
        </article>

        <!-- 04: Supply Tracking -->
        <article class="feature-card-modern">
            <span class="feature-number">TIER 3 &bull; LOGISTICS CELL</span>
            <div class="feature-symbol">📦</div>
            <h3>Supply &amp; Inventory Tracking</h3>
            <p>
                Comprehensive 15-field supply management for Logistics Lead. Track real quantities, batch lots,
                serial codes, storage bay allocations, stock issuing, and shortage warnings.
            </p>
            <a href="<?= BASE_URL ?>/login.php">View Logistics Center &rarr;</a>
        </article>

        <!-- 05: Media & Documentation -->
        <article class="feature-card-modern">
            <span class="feature-number">TIER 3 &bull; COMMUNICATION</span>
            <div class="feature-symbol">📡</div>
            <h3>Media &amp; Documentation Center</h3>
            <p>
                Centralized press kit distribution, high-resolution ceremony photography archives,
                broadcast coverage tasks, and official documentation approval workflows.
            </p>
            <a href="<?= BASE_URL ?>/login.php">Access Media Cell &rarr;</a>
        </article>

        <!-- 06: Access Security -->
        <article class="feature-card-modern">
            <span class="feature-number">GOVERNANCE &amp; SECURITY</span>
            <div class="feature-symbol">🛡️</div>
            <h3>Role-Based Access Control</h3>
            <p>
                Strict authorization boundaries at both UI and server level. Supervisors assign staff only,
                restricted leads are blocked from unrelated data, and all actions are recorded in immutable audit logs.
            </p>
            <a href="<?= BASE_URL ?>/login.php">Authenticate Account &rarr;</a>
        </article>
    </div>
</section>

<!-- =========================================================
     CONTROLLED ACCESS & COMMAND LEVELS
========================================================= -->
<section class="security-section">
    <div class="security-content">
        <div class="security-icon">B</div>
        <div>
            <span class="eyebrow" style="color:#d4af37; font-weight:800; font-size:11px; letter-spacing:1.5px;">
                CONTROLLED ACCESS &bull; CHAIN OF COMMAND
            </span>
            <h2>The right information. For the right personnel.</h2>
            <p>
                Strict role-based boundaries ensure operational confidentiality and structure
                across every level of command at Bangladesh University of Professionals.
            </p>
        </div>
    </div>

    <div class="role-list">
        <span>⭐ Event Director (L1)</span>
        <span>🎯 Event Coordinator (L2)</span>
        <span>🎖️ Department Leads (L3)</span>
        <span>📋 Ground Supervisors (L4)</span>
        <span>⚡ Duty Staff (L5)</span>
        <span>⚔️ Military Protocol (8 Levels)</span>
        <span>🎓 Verified Students</span>
    </div>
</section>

<!-- =========================================================
     FINAL CALL TO ACTION
========================================================= -->
<section class="final-cta">
    <div>
        <span class="eyebrow">BUP EVENT OPERATIONS</span>
        <h2>One system. Complete institutional event control.</h2>
        <p>Coordinate ceremonies, distinguished guests, duties, protocol checklists, and reporting from one secure command center.</p>
    </div>

    <a class="btn btn-gold btn-lg" href="<?= BASE_URL ?>/login.php">
        Access Command Center
        <span>&rarr;</span>
    </a>
</section>

</main>

<!-- =========================================================
     FOOTER
========================================================= -->
<footer class="landing-footer landing-footer-modern">
    <div class="footer-main">
        <a class="brand brand-dark" href="<?= BASE_URL ?>/index.php">
            <span class="brand-mark">
                <span>B</span>
            </span>
            <span class="brand-text">
                <strong>BUP Protocol</strong>
                <small>EVENT &amp; MILITARY MANAGEMENT</small>
            </span>
        </a>
        <p>
            Centralized ceremonial protocol, duty roster alignment, and high-level event management ecosystem for Bangladesh University of Professionals.
        </p>
    </div>

    <div class="footer-bottom">
        <span>
            &copy; <?= date('Y') ?> Bangladesh University of Professionals (BUP).
        </span>
        <span>
            Mirpur Cantonment, Dhaka-1216 &bull; Precision &bull; Integrity &bull; Ceremonial Excellence
        </span>
    </div>
</footer>

<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>
