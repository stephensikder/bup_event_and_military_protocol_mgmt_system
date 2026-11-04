<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('APP_NAME', 'BUP Event & Military Protocol Management System');
define('APP_TIMEZONE', 'Asia/Dhaka');
date_default_timezone_set(APP_TIMEZONE);

// Auto-detect or environment-configured BASE_URL
if (!defined('BASE_URL')) {
    $envBase = getenv('BASE_URL') ?: ($_ENV['BASE_URL'] ?? ($_SERVER['BASE_URL'] ?? null));
    if ($envBase !== null && $envBase !== false && trim((string)$envBase) !== '') {
        define('BASE_URL', rtrim((string)$envBase, '/'));
    } else {
        // Dynamic detection supporting localhost subdirectories (/bup-event-protocol or /bup-event) and Render root (/)
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptDir = preg_replace('#/(api|includes|config)$#', '', $scriptDir);
        define('BASE_URL', ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') ? '' : rtrim($scriptDir, '/'));
    }
}

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

if (!function_exists('get_mail_config')) {
    function get_mail_config(): array {
        // 1. Environment variables (for Render production)
        $smtpHost = getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? ($_SERVER['SMTP_HOST'] ?? null));
        if ($smtpHost && trim($smtpHost) !== '') {
            return [
                'host'       => trim($smtpHost),
                'port'       => (int)(getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? 587)),
                'encryption' => getenv('SMTP_ENCRYPTION') ?: ($_ENV['SMTP_ENCRYPTION'] ?? 'tls'),
                'username'   => getenv('SMTP_USERNAME') ?: ($_ENV['SMTP_USERNAME'] ?? ''),
                'password'   => getenv('SMTP_PASSWORD') ?: ($_ENV['SMTP_PASSWORD'] ?? ''),
                'from_email' => getenv('SMTP_FROM_EMAIL') ?: ($_ENV['SMTP_FROM_EMAIL'] ?? 'stephensikder@gmail.com'),
                'from_name'  => getenv('SMTP_FROM_NAME') ?: ($_ENV['SMTP_FROM_NAME'] ?? 'BUP Event & Military Protocol Management System'),
            ];
        }

        // 2. Local mail.php configuration file (for XAMPP localhost)
        $mailFile = __DIR__ . '/mail.php';
        if (file_exists($mailFile)) {
            return require $mailFile;
        }

        // 3. Fallback template configuration
        $exampleFile = __DIR__ . '/mail.example.php';
        if (file_exists($exampleFile)) {
            return require $exampleFile;
        }

        return [
            'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
            'username' => '', 'password' => '',
            'from_email' => 'noreply@bup.edu.bd', 'from_name' => 'BUP Event Protocol'
        ];
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/mongo.php';
require_once __DIR__ . '/notifications.php';
