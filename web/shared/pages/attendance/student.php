<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

require_role('STUDENT');

$pageTitle = 'My Attendance';
$student = current_student_profile();
$moduleId = positive_int($_GET['module_id'] ?? null);
$filters = attendance_report_sanitize_filters(
    ['module_id' => $moduleId],
    null,
    $student !== null ? (int) $student['student_id'] : 0
);
$records = ($student !== null && (int) $filters['student_id'] > 0)
    ? list_attendance_report_rows($filters)
    : [];
$summary = ($student !== null && (int) $filters['student_id'] > 0)
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
$modules = [];
if ($student !== null) {
    $modules = list_attendance_report_rows(['student_id' => (int) $student['student_id']]);
    $unique = [];
    foreach ($modules as $row) {
        $unique[(int) $row['module_id']] = [
            'module_id' => (int) $row['module_id'],
            'module_code' => $row['module_code'],
            'module_name' => $row['module_name'],
        ];
    }
    $modules = array_values($unique);
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h1 class="h4 mb-3">My Attendance</h1>
        <?php if ($student === null): ?>
            <div class="alert alert-warning mb-0">No student profile is linked to this login.</div>
        <?php else: ?>
            <p class="text-muted">Final results for lectures you were eligible to attend. Other students’ records are not shown.</p>
            <form method="get" class="row g-3 mb-0">
                <div class="col-md-4">
                    <label for="module_id" class="form-label">Module</label>
                    <select class="form-select" id="module_id" name="module_id">
                        <option value="">All modules</option>
                        <?php foreach ($modules as $module): ?>
                            <option value="<?= e((string) $module['module_id']) ?>" <?= $moduleId === (int) $module['module_id'] ? 'selected' : '' ?>>
                                <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="app-filter-actions">
                        <button type="submit" class="btn btn-outline-primary">Filter</button>
                        <a href="<?= e(app_url('student/attendance.php')) ?>" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($student !== null): ?>
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="metric-card">
                <span class="metric-card__label">Overall Attendance</span>
                <span class="metric-card__value"><?= $summary['records'] > 0 ? e(number_format((float) $summary['average_percent'], 2)) . '%' : '—' ?></span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="metric-card">
                <span class="metric-card__label">Present</span>
                <span class="metric-card__value"><?= e((string) $summary['present']) ?></span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="metric-card">
                <span class="metric-card__label">Late</span>
                <span class="metric-card__value"><?= e((string) $summary['late']) ?></span>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="metric-card">
                <span class="metric-card__label">Absent</span>
                <span class="metric-card__value"><?= e((string) $summary['absent']) ?></span>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Module</th>
                        <th>Date</th>
                        <th>Scheduled Time</th>
                        <th>Attended / Teaching</th>
                        <th>Attendance %</th>
                        <th>Status</th>
                        <th>Left Early</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($records === []): ?>
                        <tr>
                            <td colspan="7" class="p-0">
                                <div class="app-empty-state">
                                    <p class="app-empty-state__title mb-0">No finalized attendance yet.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?= e($record['module_code'] . ' · ' . $record['module_name']) ?></td>
                                <td><?= e((string) $record['session_date']) ?></td>
                                <td><?= e(format_time_display($record['scheduled_start']) . '–' . format_time_display($record['scheduled_end'])) ?></td>
                                <td><?= e((string) $record['total_present_minutes']) ?> / <?= e((string) $record['teaching_minutes']) ?> min</td>
                                <td><?= e(number_format((float) $record['attendance_percent'], 2)) ?>%</td>
                                <td><span class="badge <?= e(status_badge_class((string) $record['status'])) ?>"><?= e((string) $record['status']) ?></span></td>
                                <td><?= (int) $record['left_early'] === 1 ? 'Yes' : 'No' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
