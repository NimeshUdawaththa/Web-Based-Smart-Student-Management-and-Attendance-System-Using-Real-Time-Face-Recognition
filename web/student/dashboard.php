<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('STUDENT');

$pageTitle = 'Student Dashboard';
$user = current_user();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Welcome</h2>
        <p class="text-muted mb-0">Your student portal features will be added in later development stages.</p>
    </div>
</div>

<p class="text-muted mt-4 mb-0">Signed in as <strong><?= e($user['username']) ?></strong>.</p>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
