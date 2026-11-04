<?php
declare(strict_types=1);

session_start();

define('APP_NAME', 'BUP Event & Military Protocol Management System');
define('BASE_URL', '/bup-event-protocol');
define('APP_TIMEZONE', 'Asia/Dhaka');

date_default_timezone_set(APP_TIMEZONE);

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3307');
define('DB_NAME', 'bup_event_protocol');
define('DB_USER', 'root');
define('DB_PASS', '');

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

require_once __DIR__ . '/mongo.php';
require_once __DIR__ . '/notifications.php';
