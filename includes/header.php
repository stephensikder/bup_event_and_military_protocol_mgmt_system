<?php
require_once __DIR__ . '/../config/config.php';
$flash = $flash ?? get_flash();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= time() ?>">
</head>
<body>
<?php if (is_logged_in()): ?>
<?php require __DIR__ . '/navbar.php'; ?>
<div class="app-shell">
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
<?php if ($flash): ?>
<div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php else: ?>
<div class="public-shell">
<?php if ($flash && empty($hide_header_flash)): ?>
<div class="alert <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
<?php endif; ?>
