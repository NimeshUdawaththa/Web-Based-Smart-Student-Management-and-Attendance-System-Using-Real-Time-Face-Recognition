<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? attendance_report_forced_lecturer_id();

if ($restrictLecturerId === 0) {
    set_flash('error', 'No lecturer profile is linked to this account.');
    redirect($academicRoutePrefix . '/dashboard.php');
}

if (!user_can_view_staff_attendance_reports()) {
    deny_access();
}

$isLecturer = current_user()['role'] === 'LECTURER';
$filters = attendance_report_sanitize_filters($_GET, $restrictLecturerId);
$sessionNotice = attendance_report_session_notice(
    isset($filters['session_id']) ? (int) $filters['session_id'] : null,
    $restrictLecturerId
);

if ($sessionNotice === 'You can only view attendance for your own lecture sessions.') {
    set_flash('error', $sessionNotice);
    redirect($academicRoutePrefix . '/attendance/reports.php');
}

$export = (string) ($_GET['export'] ?? '');
if ($export === 'csv' && $sessionNotice === null) {
    $csvRows = list_attendance_report_rows($filters);
    attendance_report_output_csv($csvRows, 'attendance-report-' . app_today() . '.csv');
    exit;
}

$pageTitle = $isLecturer ? 'Attendance Reports' : 'Attendance Reports';
$rows = $sessionNotice === null ? list_attendance_report_rows($filters) : [];
$summary = $sessionNotice === null
    ? summarize_attendance_report($filters)
    : [
        'records' => 0,
        'students' => 0,
        'sessions' => 0,
        'present' => 0,
        'late' => 0,
        'absent' => 0,
        'left_early' => 0,
        'average_percent' => 0.0,
    ];

$modules = list_attendance_report_modules($restrictLecturerId);
$sessions = list_attendance_report_sessions(
    $restrictLecturerId,
    isset($filters['module_id']) ? (int) $filters['module_id'] : null
);
$batches = $isLecturer ? [] : list_attendance_report_batches($restrictLecturerId);
$lecturers = $isLecturer ? [] : list_attendance_report_lecturers();
$selectedSession = !empty($filters['session_id']) ? get_lecture_session((int) $filters['session_id']) : null;

$queryBase = array_filter([
    'module_id' => $filters['module_id'] ?? null,
    'batch_id' => $filters['batch_id'] ?? null,
    'session_id' => $filters['session_id'] ?? null,
    'lecturer_id' => $isLecturer ? null : ($filters['lecturer_id'] ?? null),
    'from' => $filters['from'] ?? null,
    'to' => $filters['to'] ?? null,
    'status' => $filters['status'] ?? null,
    'left_early' => isset($filters['left_early'])
        ? ((int) $filters['left_early'] === 1 ? 'yes' : 'no')
        : null,
    'search' => $filters['search'] ?? null,
], static fn ($value) => $value !== null && $value !== '');

$exportUrl = app_url($academicRoutePrefix . '/attendance/reports.php?' . http_build_query($queryBase + ['export' => 'csv']));
$dash = static function (?string $value): string {
    $text = trim((string) $value);

    return $text === '' ? '—' : $text;
};

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">
    Finalized attendance only. Active or scheduled lectures are not shown as historical results.
    <?= $isLecturer ? 'You can only see sessions assigned to you.' : '' ?>
</p>

<form method="get" class="card app-filter-card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label for="module_id" class="form-label">Module</label>
                <select class="form-select" id="module_id" name="module_id">
                    <option value="">All modules</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= e((string) $module['module_id']) ?>" <?= (int) ($filters['module_id'] ?? 0) === (int) $module['module_id'] ? 'selected' : '' ?>>
                            <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!$isLecturer): ?>
                <div class="col-md-3">
                    <label for="lecturer_id" class="form-label">Lecturer</label>
                    <select class="form-select" id="lecturer_id" name="lecturer_id">
                        <option value="">All lecturers</option>
                        <?php foreach ($lecturers as $lecturer): ?>
                            <option value="<?= e((string) $lecturer['lecturer_id']) ?>" <?= (int) ($filters['lecturer_id'] ?? 0) === (int) $lecturer['lecturer_id'] ? 'selected' : '' ?>>
                                <?= e($lecturer['first_name'] . ' ' . $lecturer['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="batch_id" class="form-label">Batch</label>
                    <select class="form-select" id="batch_id" name="batch_id">
                        <option value="">All batches</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?= e((string) $batch['batch_id']) ?>" <?= (int) ($filters['batch_id'] ?? 0) === (int) $batch['batch_id'] ? 'selected' : '' ?>>
                                <?= e($batch['course_code'] . ' – ' . $batch['batch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="search" class="form-label">Student search</label>
                    <input type="text" class="form-control" id="search" name="search" value="<?= e((string) ($filters['search'] ?? '')) ?>" placeholder="Registration no or name">
                </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label for="from" class="form-label">From</label>
                <input type="date" class="form-control" id="from" name="from" value="<?= e((string) ($filters['from'] ?? '')) ?>">
            </div>
            <div class="col-md-3">
                <label for="to" class="form-label">To</label>
                <input type="date" class="form-control" id="to" name="to" value="<?= e((string) ($filters['to'] ?? '')) ?>">
            </div>
            <div class="col-md-3">
                <label for="session_id" class="form-label">Session</label>
                <select class="form-select" id="session_id" name="session_id">
                    <option value="">All completed sessions</option>
                    <?php foreach ($sessions as $item): ?>
                        <option value="<?= e((string) $item['session_id']) ?>" <?= (int) ($filters['session_id'] ?? 0) === (int) $item['session_id'] ? 'selected' : '' ?>>
                            <?= e($item['module_code'] . ' · ' . $item['session_date'] . ' · ' . format_time_display($item['scheduled_start'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (['PRESENT', 'LATE', 'ABSENT'] as $statusOption): ?>
                        <option value="<?= e($statusOption) ?>" <?= ($filters['status'] ?? '') === $statusOption ? 'selected' : '' ?>><?= e($statusOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="left_early" class="form-label">Left Early</label>
                <select class="form-select" id="left_early" name="left_early">
                    <option value="">All</option>
                    <option value="yes" <?= (isset($filters['left_early']) && (int) $filters['left_early'] === 1) ? 'selected' : '' ?>>Yes</option>
                    <option value="no" <?= (isset($filters['left_early']) && (int) $filters['left_early'] === 0) ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="app-filter-actions">
                    <button type="submit" class="btn btn-outline-primary">Filter</button>
                    <a href="<?= e(app_url($academicRoutePrefix . '/attendance/reports.php')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php if ($sessionNotice !== null): ?>
    <div class="alert alert-info"><?= e($sessionNotice) ?></div>
<?php endif; ?>

<?php if (is_array($selectedSession) && ($selectedSession['status'] ?? '') === 'COMPLETED' && $sessionNotice === null): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5 mb-1"><?= e($selectedSession['module_code']) ?></h2>
            <p class="text-muted mb-0">
                <?= e((string) $selectedSession['session_date']) ?>
                · <?= e(format_time_display($selectedSession['scheduled_start']) . '–' . format_time_display($selectedSession['scheduled_end'])) ?>
                · <?= e($selectedSession['batch_name']) ?>
            </p>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small"><?= $isLecturer ? 'Eligible Students' : 'Students' ?></div>
            <div class="fs-4"><?= e((string) $summary['students']) ?></div>
        </div></div>
    </div>
    <?php if (!$isLecturer): ?>
        <div class="col-6 col-lg-2">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small">Sessions</div>
                <div class="fs-4"><?= e((string) $summary['sessions']) ?></div>
            </div></div>
        </div>
    <?php endif; ?>
    <div class="col-6 col-lg-2">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Present</div>
            <div class="fs-4"><?= e((string) $summary['present']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Late</div>
            <div class="fs-4"><?= e((string) $summary['late']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-2">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Absent</div>
            <div class="fs-4"><?= e((string) $summary['absent']) ?></div>
        </div></div>
    </div>
    <?php if ($isLecturer): ?>
        <div class="col-6 col-lg-2">
            <div class="card shadow-sm h-100"><div class="card-body">
                <div class="text-muted small">Left Early</div>
                <div class="fs-4"><?= e((string) $summary['left_early']) ?></div>
            </div></div>
        </div>
    <?php endif; ?>
    <div class="col-6 col-lg-2">
        <div class="card shadow-sm h-100"><div class="card-body">
            <div class="text-muted small">Average Attendance</div>
            <div class="fs-4"><?= $summary['records'] > 0 ? e(number_format((float) $summary['average_percent'], 2)) . '%' : '—' ?></div>
        </div></div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <p class="text-muted mb-0"><?= e((string) $summary['records']) ?> finalized record<?= $summary['records'] === 1 ? '' : 's' ?>.</p>
    <?php if ($sessionNotice === null): ?>
        <a href="<?= e($exportUrl) ?>" class="btn btn-outline-primary btn-sm">Export CSV</a>
    <?php endif; ?>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Registration No</th>
                    <th>Student</th>
                    <th>Module</th>
                    <th>Date</th>
                    <th>Scheduled Time</th>
                    <th>First In</th>
                    <th>Last Out</th>
                    <th>Attended</th>
                    <th>Teaching</th>
                    <th>Attendance %</th>
                    <th>Status</th>
                    <th>Left Early</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="13" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0"><?= $sessionNotice !== null ? e($sessionNotice) : 'No finalized attendance matches these filters.' ?></p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e((string) $row['registration_no']) ?></td>
                            <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td><?= e((string) $row['module_code']) ?></td>
                            <td><?= e((string) $row['session_date']) ?></td>
                            <td><?= e(format_time_display($row['scheduled_start']) . '–' . format_time_display($row['scheduled_end'])) ?></td>
                            <td><?= e($dash(isset($row['first_entry']) ? (string) $row['first_entry'] : null)) ?></td>
                            <td><?= e($dash(isset($row['last_exit']) ? (string) $row['last_exit'] : null)) ?></td>
                            <td><?= e((string) $row['total_present_minutes']) ?> min</td>
                            <td><?= e((string) $row['teaching_minutes']) ?> min</td>
                            <td><?= e(number_format((float) $row['attendance_percent'], 2)) ?>%</td>
                            <td><span class="badge <?= e(status_badge_class((string) $row['status'])) ?>"><?= e((string) $row['status']) ?></span></td>
                            <td><?= (int) $row['left_early'] === 1 ? 'Yes' : 'No' ?></td>
                            <td class="text-end">
                                <a href="<?= e(app_url($academicRoutePrefix . '/attendance/detail.php?id=' . $row['attendance_id'])) ?>" class="btn btn-outline-primary btn-sm">View Details / Audit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
