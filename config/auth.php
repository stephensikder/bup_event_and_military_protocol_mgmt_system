<?php

require_once __DIR__ . '/database.php';


/*
|--------------------------------------------------------------------------
| CURRENT USER
|--------------------------------------------------------------------------
*/

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}


/*
|--------------------------------------------------------------------------
| LOGIN STATUS
|--------------------------------------------------------------------------
*/

function is_logged_in(): bool
{
    return current_user() !== null;
}


/*
|--------------------------------------------------------------------------
| REQUIRE LOGIN
|--------------------------------------------------------------------------
*/

function require_login(): void
{
    if (!is_logged_in()) {

        redirect(
            BASE_URL . '/login.php'
        );
    }
}


/*
|--------------------------------------------------------------------------
| LOGIN USER
|--------------------------------------------------------------------------
|
| Security rules:
| - Account must be Active
| - Maximum 5 failed attempts
| - Account becomes Inactive after 5 failed attempts
| - Successful login resets failed attempts
| - Session ID regenerated after login
|
*/

function login_user(
    string $email,
    string $password
): bool {

    $pdo = db();


    /*
    |--------------------------------------------------------------------------
    | FIND USER
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT *
        FROM users
        WHERE email = ?
        LIMIT 1
    ");

    $stmt->execute([
        $email
    ]);

    $user = $stmt->fetch();


    /*
    |--------------------------------------------------------------------------
    | INVALID ACCOUNT
    |--------------------------------------------------------------------------
    */

    if (!$user) {
        $_SESSION['login_error'] = 'Invalid email or password.';
        return false;
    }

    $userId = (int)$user['id'];
    $failedAttempts = (int)($user['failed_attempts'] ?? 0);

    /*
    |--------------------------------------------------------------------------
    | ACCOUNT PENDING / REJECTED / INACTIVE / LOCKED
    |--------------------------------------------------------------------------
    */
    if (($user['status'] ?? '') === 'Pending') {
        $_SESSION['login_error'] = 'Your account registration is PENDING approval by an Administrator. Access will be granted once approved.';
        return false;
    }

    if (($user['status'] ?? '') === 'Rejected') {
        $_SESSION['login_error'] = 'Your registration request was rejected by the Administrator. Please contact support.';
        return false;
    }

    if (($user['status'] ?? '') !== 'Active') {
        $_SESSION['login_error'] = 'This account is currently inactive. Please contact the administrator.';
        return false;
    }

    if ($failedAttempts >= 5) {
        $_SESSION['login_error'] = 'Account locked due to 5 consecutive failed login attempts. Contact administrator.';
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY PASSWORD
    |--------------------------------------------------------------------------
    */

    if (
        !password_verify(
            $password,
            $user['password_hash']
        )
    ) {


        /*
        |--------------------------------------------------------------------------
        | INCREMENT FAILED ATTEMPTS
        |--------------------------------------------------------------------------
        */

        $newFailedAttempts =
            $failedAttempts + 1;


        /*
        |--------------------------------------------------------------------------
        | LOCK AFTER 5 FAILED ATTEMPTS
        |--------------------------------------------------------------------------
        */

        if ($newFailedAttempts >= 5) {

            $stmt = $pdo->prepare("
                UPDATE users
                SET
                    failed_attempts = ?,
                    status = 'Inactive'
                WHERE id = ?
            ");

            $stmt->execute([
                $newFailedAttempts,
                $userId
            ]);


            /*
            |--------------------------------------------------------------------------
            | AUDIT LOG
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    description
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                'Account Locked',
                'Account was locked after 5 failed login attempts.'
            ]);

        } else {

            $stmt = $pdo->prepare("
                UPDATE users
                SET failed_attempts = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $newFailedAttempts,
                $userId
            ]);


            /*
            |--------------------------------------------------------------------------
            | AUDIT FAILED LOGIN
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                (
                    user_id,
                    action,
                    description
                )
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                'Login Failed',
                'Invalid password. Failed attempt #' .
                $newFailedAttempts .
                '.'
            ]);
        }

        $_SESSION['login_error'] = 'Invalid email or password.';
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | SUCCESSFUL LOGIN
    |--------------------------------------------------------------------------
    */

    if (!headers_sent()) {
        session_regenerate_id(true);
    }


    /*
    |--------------------------------------------------------------------------
    | RESET FAILED ATTEMPTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        UPDATE users
        SET
            failed_attempts = 0,
            last_login = NOW()
        WHERE id = ?
    ");

    $stmt->execute([
        $userId
    ]);


    /*
    |--------------------------------------------------------------------------
    | SESSION USER
    |--------------------------------------------------------------------------
    */

    $_SESSION['user'] = [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'level' => $user['level'] ?? 'L1',
        'department' => $user['department'] ?? 'General',
        'designation' => $user['designation'] ?? '',
        'student_id' => $user['student_id'] ?? null,
        'phone' => $user['phone'] ?? null,
        'reports_to_id' => $user['reports_to_id'] ?? null,
        'backup_person_id' => $user['backup_person_id'] ?? null,
        'protocol_level' => $user['protocol_level'] ?? 'None',
        'command_code' => $user['command_code'] ?? null,
        'avatar' => $user['avatar'] ?? null,
    ];

    /*
    |--------------------------------------------------------------------------
    | AUDIT LOG
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        INSERT INTO audit_logs
        (
            user_id,
            action,
            description
        )
        VALUES (?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        'Login Successful',
        'User successfully signed into the BUP Protocol system.'
    ]);

    return true;
}


/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/

function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}


/*
|--------------------------------------------------------------------------
| ROLE & PERMISSION HELPERS
|--------------------------------------------------------------------------
*/

function current_role(): string
{
    return current_user()['role'] ?? '';
}

function has_role(array|string $roles): bool
{
    $roles = (array)$roles;
    return is_logged_in() && in_array(current_role(), $roles, true);
}

function current_level(): string
{
    return current_user()['level'] ?? 'L1';
}

function current_department(): string
{
    return current_user()['department'] ?? 'General';
}

function current_department_lead_type(): string
{
    if (!is_team_lead()) {
        return '';
    }
    $dept = strtolower(current_department());
    if (strpos($dept, 'venue') !== false) return 'VENUE';
    if (strpos($dept, 'sec') !== false) return 'SECURITY';
    if (strpos($dept, 'log') !== false) return 'LOGISTICS';
    if (strpos($dept, 'proto') !== false) return 'PROTOCOL';
    if (strpos($dept, 'comm') !== false || strpos($dept, 'media') !== false) return 'COMMUNICATION';
    return strtoupper(current_department());
}

function is_venue_lead(): bool
{
    return is_team_lead() && current_department_lead_type() === 'VENUE';
}

function is_security_lead(): bool
{
    return is_team_lead() && current_department_lead_type() === 'SECURITY';
}

function is_logistics_lead(): bool
{
    return is_team_lead() && current_department_lead_type() === 'LOGISTICS';
}

function is_protocol_lead(): bool
{
    return is_team_lead() && current_department_lead_type() === 'PROTOCOL';
}

function is_communication_lead(): bool
{
    return is_team_lead() && current_department_lead_type() === 'COMMUNICATION';
}

function is_level(array|string $levels): bool
{
    $levels = (array)$levels;
    return is_logged_in() && in_array(current_level(), $levels, true);
}

function get_login_error(): ?string
{
    $err = $_SESSION['login_error'] ?? null;
    unset($_SESSION['login_error']);
    return $err;
}

function is_student(): bool
{
    if (!is_logged_in()) return false;
    $u = current_user();
    return ($u['role'] ?? '') === 'Student';
}

function is_event_director(): bool
{
    if (!is_logged_in()) return false;
    $u = current_user();
    return $u['role'] === 'Event Director' || ($u['role'] === 'Admin' && ($u['level'] ?? '') === 'L1');
}

function is_event_coordinator(): bool
{
    if (!is_logged_in()) return false;
    $u = current_user();
    return $u['role'] === 'Event Coordinator' || ($u['level'] ?? '') === 'L2';
}

function is_team_lead(): bool
{
    if (!is_logged_in()) return false;
    $u = current_user();
    return $u['role'] === 'Department Lead' || ($u['level'] ?? '') === 'L3';
}

function is_supervisor(): bool
{
    if (!is_logged_in()) return false;
    if (is_military_protocol()) return false;
    $u = current_user();
    return $u['role'] === 'Supervisor' || ($u['level'] ?? '') === 'L4';
}

function is_staff(): bool
{
    if (!is_logged_in()) return false;
    if (is_military_protocol()) return false;
    $u = current_user();
    return $u['role'] === 'Staff / Volunteer' || ($u['level'] ?? '') === 'L5';
}

function is_military_protocol(): bool
{
    if (!is_logged_in()) return false;
    $u = current_user();
    return $u['role'] === 'Military Command' || (!empty($u['protocol_level']) && $u['protocol_level'] !== 'None');
}

/*
| Authority checks
*/
function can_create_events(): bool
{
    return is_event_director() || has_role('Admin');
}

function can_approve_events(): bool
{
    // Event Coordinator explicitly has NO final approval authority
    return is_event_director() || has_role('Admin');
}

function can_manage_budget(): bool
{
    return is_event_director() || has_role('Admin');
}

function can_propose_budget(): bool
{
    return is_event_director() || is_event_coordinator() || has_role('Admin');
}

function can_assign_duties(): bool
{
    return is_event_director() || is_event_coordinator() || is_team_lead() || is_supervisor() || has_role('Admin');
}

function can_manage_guests(): bool
{
    return is_event_director() || is_event_coordinator() || is_team_lead() || has_role('Admin');
}

function can_manage_seating(): bool
{
    return is_event_director() || is_event_coordinator() || is_team_lead() || has_role('Admin');
}

function can_create_accounts(): bool
{
    return is_event_director() || is_event_coordinator() || is_team_lead() || has_role('Admin');
}

/*
| Login-based redirection target
*/
function get_role_redirect_url(): string
{
    if (!is_logged_in()) {
        return BASE_URL . '/login.php';
    }

    $u = current_user();
    $role = $u['role'] ?? '';
    $protocol = $u['protocol_level'] ?? 'None';

    if (($protocol !== 'None' && $protocol !== '') || $role === 'Military Command' || $role === 'Military Protocol') {
        return BASE_URL . '/protocol.php';
    }

    if ($role === 'Student') {
        return BASE_URL . '/student_dashboard.php';
    }

    if (is_staff()) {
        return BASE_URL . '/staff_dashboard.php';
    }

    return BASE_URL . '/dashboard.php';
}