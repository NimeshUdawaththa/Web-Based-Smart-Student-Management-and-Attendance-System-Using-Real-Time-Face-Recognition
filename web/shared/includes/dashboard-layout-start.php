<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = $pageTitle ?? APP_NAME;
$hideTopbarTitle = $hideTopbarTitle ?? false;
$user = current_user();
$flash = get_flash();
$role = (string) ($user['role'] ?? '');
$navItems = management_nav_items($role);
$navGroups = management_nav_groups($role);
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$profileHref = $user !== null ? app_url(role_profile_path($role)) : '';
$activeNavHref = resolve_active_nav_href($currentPath, $navItems, $profileHref !== '' ? $profileHref : null);
$profileIsActive = $activeNavHref !== null && $profileHref !== '' && $activeNavHref === $profileHref;
$displayName = current_user_display_name();
$roleLabel = role_label($role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('css/app.css')) ?>" rel="stylesheet">
</head>
<body class="dashboard-body">
<div class="dashboard-shell d-flex min-vh-100">
    <aside class="dashboard-sidebar d-none d-lg-flex flex-column text-white p-3" aria-label="Sidebar">
        <?php
        $navRenderMode = 'sidebar';
        require INCLUDES_PATH . '/dashboard-nav.php';
        ?>
    </aside>

    <div
        class="offcanvas offcanvas-start text-bg-primary dashboard-offcanvas"
        tabindex="-1"
        id="dashboardNavOffcanvas"
        aria-labelledby="dashboardNavOffcanvasLabel"
    >
        <div class="offcanvas-header border-bottom border-light border-opacity-25">
            <h2 class="offcanvas-title h5 mb-0" id="dashboardNavOffcanvasLabel">Menu</h2>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column p-3">
            <?php
            $navRenderMode = 'offcanvas';
            require INCLUDES_PATH . '/dashboard-nav.php';
            ?>
        </div>
    </div>

    <div class="dashboard-content flex-grow-1 min-w-0">
        <header class="dashboard-topbar border-bottom bg-white px-3 px-lg-4 py-3">
            <div class="d-flex align-items-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <button
                        class="btn btn-outline-primary d-lg-none flex-shrink-0"
                        type="button"
                        data-bs-toggle="offcanvas"
                        data-bs-target="#dashboardNavOffcanvas"
                        aria-controls="dashboardNavOffcanvas"
                        aria-label="Open navigation menu"
                    >
                        <i class="bi bi-list" aria-hidden="true"></i>
                        <span class="ms-1">Menu</span>
                    </button>
                    <?php if (!$hideTopbarTitle): ?>
                        <h1 class="h4 mb-0 app-page-title text-truncate"><?= e($pageTitle) ?></h1>
                    <?php else: ?>
                        <span class="visually-hidden"><?= e($pageTitle) ?></span>
                    <?php endif; ?>
                </div>
                <div class="dashboard-topbar__user d-flex align-items-center gap-2 flex-shrink-0">
                    <div class="text-end d-none d-sm-block">
                        <div class="fw-semibold small text-truncate" style="max-width: 12rem;"><?= e($displayName) ?></div>
                        <span class="badge text-bg-primary dashboard-role-badge"><?= e($roleLabel) ?></span>
                    </div>
                    <span class="badge text-bg-primary dashboard-role-badge d-sm-none"><?= e($roleLabel) ?></span>
                    <?php if ($user !== null): ?>
                        <a
                            class="btn btn-outline-secondary btn-sm"
                            href="<?= e($profileHref) ?>"
                            title="My Profile"
                        >
                            <i class="bi bi-person-circle" aria-hidden="true"></i>
                            <span class="d-none d-md-inline ms-1">Profile</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        <main class="p-3 p-lg-4 app-main">
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
