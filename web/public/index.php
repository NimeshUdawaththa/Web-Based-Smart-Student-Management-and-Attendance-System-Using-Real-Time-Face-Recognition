<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

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
                    Web-based student management and attendance foundation.
                    Authentication, dashboards, and face recognition are not enabled yet.
                </p>

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
