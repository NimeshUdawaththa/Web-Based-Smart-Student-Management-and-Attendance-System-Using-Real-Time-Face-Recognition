<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

send_auth_headers();

if (is_authenticated()) {
    $user = current_user();
    if ($user !== null) {
        redirect(role_dashboard_path($user['role']));
    }
}

$pageTitle = 'Home';
$authPage = true;
$dbCheck = APP_DEBUG ? check_database_connection() : null;

require INCLUDES_PATH . '/header.php';
?>

<div class="app-auth app-auth--entry">
    <div class="app-auth__panel app-auth__panel--brand">
        <div class="app-auth__brand">
            <div class="app-auth__mark" aria-hidden="true">
                <i class="bi bi-mortarboard-fill"></i>
            </div>
            <p class="app-auth__eyebrow"><?= e(APP_NAME) ?></p>
            <h1 class="app-auth__title">Smart Student Management &amp; Attendance System</h1>
            <p class="app-auth__lead">
                Manage students, teaching and attendance from one secure workspace.
            </p>
        </div>
    </div>

    <div class="app-auth__panel app-auth__panel--form">
        <div class="app-auth__card card border-0">
            <div class="card-body">
                <h2 class="app-auth__card-title mb-2">Sign in to continue</h2>
                <p class="text-muted mb-4">
                    Use your institution account to open your role dashboard.
                </p>
                <a href="<?= e(app_url('login.php')) ?>" class="btn btn-primary btn-lg w-100">Sign in</a>

                <?php if ($dbCheck !== null): ?>
                    <div class="app-auth__db-status mt-4" role="status">
                        <span class="app-auth__db-label">Database</span>
                        <span class="badge <?= $dbCheck['ok'] ? 'text-bg-success' : 'text-bg-danger' ?>">
                            <?= e($dbCheck['label']) ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require INCLUDES_PATH . '/footer.php';
