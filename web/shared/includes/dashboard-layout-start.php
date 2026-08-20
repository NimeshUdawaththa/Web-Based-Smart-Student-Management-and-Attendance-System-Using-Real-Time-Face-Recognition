<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = $pageTitle ?? APP_NAME;
$user = current_user();
$flash = get_flash();
$navItems = management_nav_items($user['role'] ?? '');
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('css/app.css')) ?>" rel="stylesheet">
</head>
<body class="dashboard-body">
<div class="dashboard-shell d-flex min-vh-100">
    <aside class="dashboard-sidebar bg-primary text-white p-3">
        <div class="mb-4">
            <a class="navbar-brand text-white text-decoration-none" href="<?= e(app_url(role_dashboard_path($user['role'] ?? 'STUDENT'))) ?>">
                <?= e(APP_NAME) ?>
            </a>
            <div class="small text-white-50 mt-2">
                <?= e($user['username'] ?? '') ?><br>
                <?= e(role_label($user['role'] ?? '')) ?>
            </div>
        </div>
        <nav class="nav flex-column gap-1">
            <?php foreach ($navItems as $item): ?>
                <?php
                $itemPath = parse_url($item['href'], PHP_URL_PATH) ?: $item['href'];
                $isActive = str_ends_with(rtrim($currentPath, '/'), rtrim($itemPath, '/'));
                ?>
                <a
                    class="nav-link text-white <?= $isActive ? 'active-sidebar-link' : 'text-white-50' ?>"
                    href="<?= e($item['href']) ?>"
                ><?= e($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($user !== null): ?>
            <a
                class="nav-link text-white <?= str_ends_with(rtrim($currentPath, '/'), '/profile.php') ? 'active-sidebar-link' : 'text-white-50' ?> mt-3"
                href="<?= e(app_url(role_profile_path((string) $user['role']))) ?>"
            >My Profile</a>
        <?php endif; ?>
        <form method="post" action="<?= e(app_url('logout.php')) ?>" class="mt-3">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline-light btn-sm w-100">Logout</button>
        </form>
    </aside>
    <div class="dashboard-content flex-grow-1">
        <header class="dashboard-topbar border-bottom bg-white px-4 py-3">
            <h1 class="h4 mb-0 app-page-title"><?= e($pageTitle) ?></h1>
        </header>
        <main class="p-4 app-main">
            <?php if ($flash !== null): ?>
                <?php
                $flashClass = match ($flash['type']) {
                    'success' => 'alert-success',
                    'error' => 'alert-danger',
                    default => 'alert-info',
                };
                ?>
                <div class="alert <?= e($flashClass) ?>" role="alert">
                    <?= e($flash['message']) ?>
                </div>
            <?php endif; ?>
