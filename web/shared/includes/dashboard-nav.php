<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $navRenderMode sidebar|offcanvas */
$navRenderMode = $navRenderMode ?? 'sidebar';
$dismissAttr = $navRenderMode === 'offcanvas' ? ' data-bs-dismiss="offcanvas"' : '';
$showGroupLabels = ($user['role'] ?? '') !== 'STUDENT';
?>
<div class="dashboard-brand mb-3">
    <a class="dashboard-brand__link text-decoration-none" href="<?= e(app_url(role_dashboard_path($user['role'] ?? 'STUDENT'))) ?>">
        <span class="dashboard-brand__icon" aria-hidden="true"><i class="bi bi-mortarboard-fill"></i></span>
        <span class="dashboard-brand__text">
            <span class="dashboard-brand__name"><?= e(APP_NAME) ?></span>
            <span class="dashboard-brand__tagline">Smart Student Management</span>
        </span>
    </a>
</div>

<nav class="dashboard-nav" aria-label="Primary">
    <?php foreach ($navGroups as $group): ?>
        <div class="dashboard-nav__group">
            <?php if ($showGroupLabels && ($group['label'] ?? '') !== 'MENU'): ?>
                <div class="dashboard-nav__heading"><?= e((string) $group['label']) ?></div>
            <?php endif; ?>
            <div class="nav flex-column gap-1">
                <?php foreach ($group['items'] as $item): ?>
                    <?php
                    $isActive = $activeNavHref !== null && $item['href'] === $activeNavHref;
                    $icon = (string) ($item['icon'] ?? 'circle');
                    ?>
                    <a
                        class="nav-link dashboard-nav__link <?= $isActive ? 'active-sidebar-link' : '' ?>"
                        href="<?= e($item['href']) ?>"
                        <?= $isActive ? 'aria-current="page"' : '' ?>
                        <?= $dismissAttr ?>
                    >
                        <i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i>
                        <span><?= e($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($user !== null): ?>
        <div class="dashboard-nav__group dashboard-nav__group--account">
            <?php if ($showGroupLabels): ?>
                <div class="dashboard-nav__heading">ACCOUNT</div>
            <?php endif; ?>
            <div class="nav flex-column gap-1">
                <a
                    class="nav-link dashboard-nav__link <?= $profileIsActive ? 'active-sidebar-link' : '' ?>"
                    href="<?= e($profileHref) ?>"
                    <?= $profileIsActive ? 'aria-current="page"' : '' ?>
                    <?= $dismissAttr ?>
                >
                    <i class="bi bi-person-circle" aria-hidden="true"></i>
                    <span>My Profile</span>
                </a>
            </div>
        </div>
    <?php endif; ?>
</nav>

<form method="post" action="<?= e(app_url('logout.php')) ?>" class="dashboard-logout mt-auto pt-3">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-outline-light btn-sm w-100">
        <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
        Logout
    </button>
</form>
