<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('ACADEMIC_STAFF');

$pageTitle = 'Academic Staff Dashboard';
$user = current_user();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="row g-3">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Student Management</h2>
                <p class="text-muted">Register and maintain student records.</p>
                <a href="<?= e(app_url('academic-staff/students/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Lecturers</h2>
                <p class="text-muted">View lecturer directory information.</p>
                <a href="<?= e(app_url('academic-staff/lecturers/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
</div>

<p class="text-muted mt-4 mb-0">Signed in as <strong><?= e($user['username']) ?></strong>.</p>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
