<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'lecturer';
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;

if ($restrictLecturerId === null) {
    set_flash('error', 'No lecturer profile is linked to this account.');
    redirect($academicRoutePrefix . '/dashboard.php');
}

$pageTitle = 'My Calendar';
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
$calendarPath = $academicRoutePrefix . '/schedules/index.php';
$showLecturer = false;
$filterQueryForNav = [];
$calendarAllowSessionOpen = true;

$sessions = list_lecture_sessions([
    'lecturer_id' => $restrictLecturerId,
    'from' => $rangeFrom,
    'to' => $rangeTo,
]);

$calendarEvents = [];
foreach ($sessions as $session) {
    $normalized = calendar_normalize_lecture_event(
        $session,
        app_url($academicRoutePrefix . '/sessions/view.php?id=' . (int) $session['session_id'])
    );
    if ($normalized !== null) {
        $calendarEvents[] = $normalized;
    }
}

foreach (list_calendar_coursework_for_lecturer($restrictLecturerId, $rangeFrom, $rangeTo) as $activity) {
    $normalized = calendar_normalize_coursework_event(
        $activity,
        app_url($academicRoutePrefix . '/assignments/view.php?id=' . (int) $activity['assignment_id'])
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
    Your lecture sessions plus scheduled Presentations, Exams and Practicals for modules you teach.
    Assignments with due dates appear under Coursework &amp; Assessments (not on this calendar).
    Coursework events do not create attendance sessions.
</p>

<?php require WEB_PATH . '/shared/pages/calendar/_render.php';
