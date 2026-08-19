<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('ADMIN');

$pageTitle = 'Admin Dashboard';
$user = current_user();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="row g-3">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">User Management</h2>
                <p class="text-muted">Create, edit, and activate system login accounts.</p>
                <a href="<?= e(app_url('admin/users/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Student Management</h2>
                <p class="text-muted">Register students and manage academic records.</p>
                <a href="<?= e(app_url('admin/students/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Lecturer Management</h2>
                <p class="text-muted">Maintain lecturer profiles and linked accounts.</p>
                <a href="<?= e(app_url('admin/lecturers/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Academic Staff</h2>
                <p class="text-muted">Manage academic staff records and account status.</p>
                <a href="<?= e(app_url('admin/academic-staff/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Timetable</h2>
                <p class="text-muted">System-wide lecture timetable.</p>
                <a href="<?= e(app_url('admin/schedules/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Lecture Sessions</h2>
                <p class="text-muted">Oversee dated lecture occurrences.</p>
                <a href="<?= e(app_url('admin/sessions/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Camera Management</h2>
                <p class="text-muted">Start or stop the USB attendance camera.</p>
                <a href="<?= e(app_url('admin/camera.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
</div>

<p class="text-muted mt-4 mb-0">Signed in as <strong><?= e($user['username']) ?></strong>.</p>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
