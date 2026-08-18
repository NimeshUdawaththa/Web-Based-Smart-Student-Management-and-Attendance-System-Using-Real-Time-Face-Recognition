<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('LECTURER');

$pageTitle = 'Lecturer Dashboard';
$user = current_user();
$lecturer = current_lecturer_profile();
$today = app_today();
$todaySessions = [];
$upcomingSessions = [];
$activeSessions = [];

if ($lecturer !== null) {
    $todaySessions = list_lecture_sessions([
        'lecturer_id' => (int) $lecturer['lecturer_id'],
        'from' => $today,
        'to' => $today,
    ]);
    $upcomingSessions = list_lecture_sessions([
        'lecturer_id' => (int) $lecturer['lecturer_id'],
        'upcoming' => true,
    ]);
    $activeSessions = get_in_progress_sessions((int) $lecturer['lecturer_id']);
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<?php if ($lecturer === null): ?>
    <div class="alert alert-warning">
        This login does not have a lecturer profile. Ask an administrator to create a lecturer record for this account.
    </div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">My Timetable</h2>
                    <p class="text-muted">Weekly slots assigned to you.</p>
                    <a href="<?= e(app_url('lecturer/schedules/index.php')) ?>" class="btn btn-primary btn-sm">Open</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Today's Lectures</h2>
                    <p class="text-muted mb-2"><?= count($todaySessions) ?> lecture<?= count($todaySessions) === 1 ? '' : 's' ?> today.</p>
                    <a href="<?= e(app_url('lecturer/sessions/index.php?view=today')) ?>" class="btn btn-outline-primary btn-sm">View today</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Upcoming Lectures</h2>
                    <p class="text-muted mb-2"><?= count($upcomingSessions) ?> upcoming session<?= count($upcomingSessions) === 1 ? '' : 's' ?>.</p>
                    <a href="<?= e(app_url('lecturer/sessions/index.php?view=upcoming')) ?>" class="btn btn-outline-primary btn-sm">View upcoming</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Start Session</h2>
                    <p class="text-muted">Open a scheduled lecture and start it when class begins.</p>
                    <a href="<?= e(app_url('lecturer/sessions/index.php?view=today')) ?>" class="btn btn-success btn-sm">Open sessions</a>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Active Session</h2>
                    <?php if ($activeSessions === []): ?>
                        <p class="text-muted mb-0">No session is in progress.</p>
                    <?php else: ?>
                        <?php foreach ($activeSessions as $session): ?>
                            <p class="mb-2"><?= e($session['module_code'] . ' · ' . $session['batch_name']) ?></p>
                            <a href="<?= e(app_url('lecturer/sessions/view.php?id=' . $session['session_id'])) ?>" class="btn btn-primary btn-sm">Manage active session</a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5">Student Directory</h2>
                    <p class="text-muted">View student information.</p>
                    <a href="<?= e(app_url('lecturer/students/index.php')) ?>" class="btn btn-outline-primary btn-sm">View students</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<p class="text-muted mb-0">Signed in as <strong><?= e($user['username']) ?></strong>.</p>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
