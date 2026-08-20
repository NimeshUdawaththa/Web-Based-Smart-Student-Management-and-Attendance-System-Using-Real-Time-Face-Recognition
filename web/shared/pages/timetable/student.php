<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$student = current_student_profile();
if ($student === null) {
    $pageTitle = 'My Timetable';
    require INCLUDES_PATH . '/dashboard-layout-start.php';
    echo '<div class="alert alert-warning">No student profile is linked to this login, so a timetable cannot be shown.</div>';
    require INCLUDES_PATH . '/dashboard-layout-end.php';
    return;
}

$pageTitle = 'My Timetable';
$today = app_today();
$todaySessions = list_visible_sessions_for_student((int) $student['student_id'], [
    'from' => $today,
    'to' => $today,
]);
$upcomingSessions = list_visible_sessions_for_student((int) $student['student_id'], [
    'upcoming' => true,
]);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">
    Showing lectures for <strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong>
    (<?= e($student['registration_no']) ?>) based on your batch and module enrolments.
</p>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Today's Lectures</h2></div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Time</th>
                    <th>Module</th>
                    <th>Lecturer</th>
                    <th>Room</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($todaySessions === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">No lectures today.</td></tr>
                <?php else: ?>
                    <?php foreach ($todaySessions as $session): ?>
                        <tr>
                            <td>
                                <?= e(format_time_display($session['scheduled_start']) . ' – ' . format_time_display($session['scheduled_end'])) ?>
                                <div class="small text-muted">Break: <?= e(format_break_display($session['break_start'] ?? null, $session['break_end'] ?? null)) ?></div>
                            </td>
                            <td><?= e($session['module_code'] . ' – ' . $session['module_name']) ?></td>
                            <td><?= e($session['lecturer_first_name'] . ' ' . $session['lecturer_last_name']) ?></td>
                            <td><?= e($session['room'] ?: '-') ?></td>
                            <td>
                                <span class="badge <?= e(status_badge_class($session['status'])) ?>"><?= e($session['status']) ?></span>
                                <?php $hint = session_lifecycle_hint($session); ?>
                                <?php if ($hint !== ''): ?>
                                    <div class="small text-muted"><?= e($hint) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Upcoming Lectures</h2></div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Module</th>
                    <th>Lecturer</th>
                    <th>Room</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($upcomingSessions === []): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">No upcoming lectures.</td></tr>
                <?php else: ?>
                    <?php foreach ($upcomingSessions as $session): ?>
                        <tr>
                            <td><?= e($session['session_date']) ?></td>
                            <td>
                                <?= e(format_time_display($session['scheduled_start']) . ' – ' . format_time_display($session['scheduled_end'])) ?>
                                <div class="small text-muted">Break: <?= e(format_break_display($session['break_start'] ?? null, $session['break_end'] ?? null)) ?></div>
                            </td>
                            <td><?= e($session['module_code']) ?></td>
                            <td><?= e($session['lecturer_first_name'] . ' ' . $session['lecturer_last_name']) ?></td>
                            <td><?= e($session['room'] ?: '-') ?></td>
                            <td>
                                <span class="badge <?= e(status_badge_class($session['status'])) ?>"><?= e($session['status']) ?></span>
                                <?php $hint = session_lifecycle_hint($session); ?>
                                <?php if ($hint !== ''): ?>
                                    <div class="small text-muted"><?= e($hint) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
