<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('LECTURER');

$pageTitle = 'Lecturer Dashboard';
$user = current_user();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Student Directory</h2>
        <p class="text-muted mb-3">View student information relevant to future academic functions.</p>
        <a href="<?= e(app_url('lecturer/students/index.php')) ?>" class="btn btn-primary btn-sm">View Students</a>
    </div>
</div>

<p class="text-muted mt-4 mb-0">Signed in as <strong><?= e($user['username']) ?></strong>.</p>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
