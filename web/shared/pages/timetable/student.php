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

$studentId = (int) $student['student_id'];
$pageTitle = 'My Timetable';
$today = app_today();
$view = (string) ($_GET['view'] ?? 'month');
$anchorDate = validate_date_ymd((string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : $today;
$resolved = calendar_resolve_view_range($view, $anchorDate, $today);
$view = $resolved['view'];
$anchorDate = $resolved['anchor_date'];
$rangeFrom = $resolved['range_from'];
$rangeTo = $resolved['range_to'];
$heading = $resolved['heading'];
$prevAnchor = $resolved['prev_anchor'];
$nextAnchor = $resolved['next_anchor'];
$gridDays = $resolved['grid_days'];
/** @var DateTimeImmutable $anchor */
$anchor = $resolved['anchor'];
$tz = new DateTimeZone(APP_TIMEZONE);
$academicRoutePrefix = 'student';
$calendarPath = 'student/timetable.php';
$showLecturer = true;
$filterQueryForNav = [];
$calendarAllowSessionOpen = false;

$todaySessions = list_visible_sessions_for_student($studentId, [
    'from' => $today,
    'to' => $today,
]);
$upcomingSessions = list_visible_sessions_for_student($studentId, [
    'upcoming' => true,
]);

$rangeSessions = list_visible_sessions_for_student($studentId, [
    'from' => $rangeFrom,
    'to' => $rangeTo,
]);

$calendarEvents = [];
foreach ($rangeSessions as $session) {
    $normalized = calendar_normalize_lecture_event($session, '');
    if ($normalized !== null) {
        $calendarEvents[] = $normalized;
    }
}

foreach (list_calendar_coursework_for_student($studentId, $rangeFrom, $rangeTo) as $activity) {
    $normalized = calendar_normalize_coursework_event(
        $activity,
        app_url('student/assignments/view.php?id=' . (int) $activity['assignment_id'])
    );
    if ($normalized !== null) {
        $calendarEvents[] = $normalized;
    }
}

$calendarEvents = calendar_sort_events($calendarEvents);
$eventsByDate = calendar_group_events_by_date($calendarEvents);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">
    Showing lectures and scheduled assessments for <strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong>
    (<?= e($student['registration_no']) ?>) based on your module enrolments.
    Presentations, Exams and Practicals appear on the calendar; Assignments remain under Coursework &amp; Assessments.
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

<h2 class="h5 mb-3">Calendar</h2>
<p class="text-muted small mb-3">
    Lectures and scheduled Presentations, Exams and Practicals for modules you are enrolled in.
</p>

<?php require WEB_PATH . '/shared/pages/calendar/_render.php';
