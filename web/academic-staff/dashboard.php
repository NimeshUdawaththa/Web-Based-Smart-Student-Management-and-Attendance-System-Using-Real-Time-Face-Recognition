<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('ACADEMIC_STAFF');

$pageTitle = 'Academic Staff Dashboard';
$user = current_user();
$today = app_today();
$todaySessions = list_lecture_sessions(['from' => $today, 'to' => $today]);
$upcomingSessions = list_lecture_sessions(['upcoming' => true]);
$activeSessions = get_in_progress_sessions();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Timetable</h2>
                <p class="text-muted">Create and maintain recurring lecture slots.</p>
                <a href="<?= e(app_url('academic-staff/schedules/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Lecture Sessions</h2>
                <p class="text-muted">Sessions follow the timetable automatically. Start, stop, or cancel remain available as overrides.</p>
                <a href="<?= e(app_url('academic-staff/sessions/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Student Module Enrollment</h2>
                <p class="text-muted">Enrol students so they become eligible for a lecture.</p>
                <a href="<?= e(app_url('academic-staff/enrollments/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Lecturer Module Assignment</h2>
                <p class="text-muted">Assign lecturers to the modules they teach.</p>
                <a href="<?= e(app_url('academic-staff/module-assignments/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Today's Sessions</h2>
                <p class="text-muted mb-2"><?= count($todaySessions) ?> session<?= count($todaySessions) === 1 ? '' : 's' ?> today.</p>
                <a href="<?= e(app_url('academic-staff/sessions/index.php?view=today')) ?>" class="btn btn-outline-primary btn-sm">View today</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <h2 class="h5">Upcoming Sessions</h2>
                <p class="text-muted mb-2"><?= count($upcomingSessions) ?> upcoming scheduled/in-progress session<?= count($upcomingSessions) === 1 ? '' : 's' ?>.</p>
                <?php if ($activeSessions !== []): ?>
                    <p class="small text-success mb-2"><?= count($activeSessions) ?> currently in progress.</p>
                <?php endif; ?>
                <a href="<?= e(app_url('academic-staff/sessions/index.php?view=upcoming')) ?>" class="btn btn-outline-primary btn-sm">View upcoming</a>
            </div>
        </div>
    </div>
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
                <h2 class="h5">Modules</h2>
                <p class="text-muted">Maintain course modules used by the timetable.</p>
                <a href="<?= e(app_url('academic-staff/modules/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
            </div>
        </div>
    </div>
</div>

<p class="text-muted mb-0">Signed in as <strong><?= e($user['username']) ?></strong>.</p>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
