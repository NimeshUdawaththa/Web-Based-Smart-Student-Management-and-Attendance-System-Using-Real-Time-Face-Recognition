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

$sessions = list_lecture_sessions([
    'lecturer_id' => $restrictLecturerId,
    'from' => $rangeFrom,
    'to' => $rangeTo,
]);

$sessionsByDate = [];
foreach ($sessions as $session) {
    $sessionsByDate[(string) $session['session_date']][] = $session;
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">
    Dated lecture sessions assigned to you. Statuses update when this page is opened.
</p>

<?php require WEB_PATH . '/shared/pages/calendar/_render.php';
