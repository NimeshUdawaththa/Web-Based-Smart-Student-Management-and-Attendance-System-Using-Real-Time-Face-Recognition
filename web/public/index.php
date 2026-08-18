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
$dbCheck = APP_DEBUG ? check_database_connection() : null;

require INCLUDES_PATH . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h3 mb-3"><?= e(APP_NAME) ?></h1>
                <p class="text-muted mb-4">
                    Web-based student management and attendance system.
                    Sign in to access your role dashboard.
                </p>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a href="<?= e(app_url('login.php')) ?>" class="btn btn-primary">Sign in</a>
                </div>

                <?php if ($dbCheck !== null): ?>
                    <div class="alert <?= $dbCheck['ok'] ? 'alert-success' : 'alert-danger' ?> mb-0" role="status">
                        Database:
                        <strong><?= e($dbCheck['label']) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
require INCLUDES_PATH . '/footer.php';
