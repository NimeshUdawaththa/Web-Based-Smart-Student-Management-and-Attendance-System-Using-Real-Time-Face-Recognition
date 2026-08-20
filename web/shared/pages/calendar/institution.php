<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$pageTitle = 'Lecture Calendar';
$today = app_today();
$view = (string) ($_GET['view'] ?? 'month');
$anchorDate = validate_date_ymd((string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : $today;

$filterLecturerId = positive_int($_GET['lecturer_id'] ?? null);
$filterCourseId = positive_int($_GET['course_id'] ?? null);
$filterBatchId = positive_int($_GET['batch_id'] ?? null);
$filterModuleId = positive_int($_GET['module_id'] ?? null);
$filterStatus = (string) ($_GET['status'] ?? '');
if (!in_array($filterStatus, lecture_session_statuses(), true)) {
    $filterStatus = '';
}

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
$calendarPath = $academicRoutePrefix . '/calendar/index.php';
$showLecturer = true;

$filterQuery = array_filter([
    'lecturer_id' => $filterLecturerId,
    'course_id' => $filterCourseId,
    'batch_id' => $filterBatchId,
    'module_id' => $filterModuleId,
    'status' => $filterStatus !== '' ? $filterStatus : null,
], static fn ($value): bool => $value !== null && $value !== '');

$sessions = list_lecture_sessions(array_merge($filterQuery, [
    'from' => $rangeFrom,
    'to' => $rangeTo,
]));

$sessionsByDate = [];
foreach ($sessions as $session) {
    $sessionsByDate[(string) $session['session_date']][] = $session;
}

$lecturers = list_lecturers(['status' => 'ACTIVE']);
$courses = list_courses(['status' => 'ACTIVE']);
$batches = list_batches($filterCourseId, true);
$modules = list_modules(['status' => 'ACTIVE']);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-3">
    Institution-wide dated lecture sessions. Create a session to place it on this calendar and the assigned lecturer’s My Calendar.
</p>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= e(app_url($academicRoutePrefix . '/sessions/create.php')) ?>" class="btn btn-primary btn-sm">Create Session</a>
        <a href="<?= e(app_url($academicRoutePrefix . '/sessions/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Lecture Sessions</a>
    </div>
</div>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body row g-3 align-items-end">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <input type="hidden" name="date" value="<?= e($anchorDate) ?>">
        <div class="col-md-2">
            <label for="lecturer_id" class="form-label">Lecturer</label>
            <select class="form-select" id="lecturer_id" name="lecturer_id">
                <option value="">All Lecturers</option>
                <?php foreach ($lecturers as $lecturer): ?>
                    <?php
                    $lecturerLabel = trim((string) $lecturer['first_name'] . ' ' . (string) $lecturer['last_name']);
                    if ($lecturerLabel === '') {
                        $lecturerLabel = (string) ($lecturer['staff_no'] ?? 'Lecturer');
                    }
                    ?>
                    <option value="<?= e((string) $lecturer['lecturer_id']) ?>" <?= $filterLecturerId === (int) $lecturer['lecturer_id'] ? 'selected' : '' ?>>
                        <?= e($lecturerLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="course_id" class="form-label">Course</label>
            <select class="form-select" id="course_id" name="course_id">
                <option value="">All Courses</option>
                <?php foreach ($courses as $course): ?>
                    <option value="<?= e((string) $course['course_id']) ?>" <?= $filterCourseId === (int) $course['course_id'] ? 'selected' : '' ?>>
                        <?= e((string) $course['course_code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="batch_id" class="form-label">Batch</label>
            <select class="form-select" id="batch_id" name="batch_id">
                <option value="">All Batches</option>
                <?php foreach ($batches as $batch): ?>
                    <option value="<?= e((string) $batch['batch_id']) ?>" <?= $filterBatchId === (int) $batch['batch_id'] ? 'selected' : '' ?>>
                        <?= e((string) $batch['batch_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="module_id" class="form-label">Module</label>
            <select class="form-select" id="module_id" name="module_id">
                <option value="">All Modules</option>
                <?php foreach ($modules as $module): ?>
                    <option value="<?= e((string) $module['module_id']) ?>" <?= $filterModuleId === (int) $module['module_id'] ? 'selected' : '' ?>>
                        <?= e((string) $module['module_code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="status" class="form-label">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach (lecture_session_statuses() as $sessionStatus): ?>
                    <option value="<?= e($sessionStatus) ?>" <?= $filterStatus === $sessionStatus ? 'selected' : '' ?>><?= e($sessionStatus) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary">Filter</button>
            <a href="<?= e(app_url($calendarPath . '?view=' . rawurlencode($view) . '&date=' . rawurlencode($anchorDate))) ?>" class="btn btn-outline-secondary">Clear</a>
        </div>
    </div>
</form>

<?php
$filterQueryForNav = $filterQuery;
require WEB_PATH . '/shared/pages/calendar/_render.php';
