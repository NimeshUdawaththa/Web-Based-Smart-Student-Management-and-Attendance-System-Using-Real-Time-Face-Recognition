<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('STUDENT');

$pageTitle = 'Student Dashboard';
$user = current_user();
$student = current_student_profile();
$today = app_today();
$todaySessions = [];
$upcomingSessions = [];

if ($student !== null) {
    $todaySessions = list_visible_sessions_for_student((int) $student['student_id'], [
        'from' => $today,
        'to' => $today,
    ]);
    $upcomingSessions = list_visible_sessions_for_student((int) $student['student_id'], [
        'upcoming' => true,
    ]);
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<?php if ($student === null): ?>
    <div class="alert alert-warning">No student profile is linked to this login.</div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">My Timetable</h2>
                    <p class="text-muted">Weekly slots for modules you are enrolled in.</p>
                    <a href="<?= e(app_url('student/timetable.php')) ?>" class="btn btn-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Today's Lectures</h2>
                    <p class="text-muted mb-2"><?= count($todaySessions) ?> lecture<?= count($todaySessions) === 1 ? '' : 's' ?> today.</p>
                    <a href="<?= e(app_url('student/timetable.php')) ?>" class="btn btn-outline-primary btn-sm">View</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Upcoming Lectures</h2>
                    <p class="text-muted mb-2"><?= count($upcomingSessions) ?> upcoming lecture<?= count($upcomingSessions) === 1 ? '' : 's' ?>.</p>
                    <a href="<?= e(app_url('student/timetable.php')) ?>" class="btn btn-outline-primary btn-sm">View</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">My Attendance</h2>
                    <p class="text-muted">Final attendance for lectures you were eligible to attend.</p>
                    <a href="<?= e(app_url('student/attendance.php')) ?>" class="btn btn-outline-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">My Assignments</h2>
                    <p class="text-muted">View instructions and submit work for modules you are enrolled in.</p>
                    <a href="<?= e(app_url('student/assignments/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<p class="text-muted mb-0">Signed in as <strong><?= e($user['username']) ?></strong>.</p>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
