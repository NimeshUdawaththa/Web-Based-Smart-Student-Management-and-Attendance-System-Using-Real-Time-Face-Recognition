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

$attendanceId = positive_int($_GET['id'] ?? null);
if ($attendanceId === null) {
    set_flash('error', 'Attendance record not found.');
    redirect($academicRoutePrefix . '/attendance/reports.php');
}

$record = get_attendance_report_record($attendanceId, $restrictLecturerId);
if ($record === null) {
    set_flash('error', 'You do not have permission to view that attendance record.');
    redirect($academicRoutePrefix . '/attendance/reports.php');
}

$pageTitle = 'Attendance Details';
$events = list_attendance_events_for_student_session((int) $record['student_id'], (int) $record['session_id']);
$dash = static function (?string $value): string {
    $text = trim((string) $value);

    return $text === '' ? '—' : $text;
};

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/attendance/reports.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Reports</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h2 class="h5 mb-0">Session summary</h2>
    </div>
    <div class="card-body">
        <dl class="app-session-summary-grid mb-0">
            <div>
                <dt>Module</dt>
                <dd><?= e($record['module_code'] . ' – ' . $record['module_name']) ?></dd>
            </div>
            <div>
                <dt>Date</dt>
                <dd><?= e((string) $record['session_date']) ?></dd>
            </div>
            <div>
                <dt>Scheduled time</dt>
                <dd><?= e(format_time_display($record['scheduled_start']) . '–' . format_time_display($record['scheduled_end'])) ?></dd>
            </div>
            <div>
                <dt>Batch</dt>
                <dd><?= e((string) $record['batch_name']) ?></dd>
            </div>
            <div>
                <dt>Lecturer</dt>
                <dd><?= e($record['lecturer_first_name'] . ' ' . $record['lecturer_last_name']) ?></dd>
            </div>
        </dl>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h2 class="h5 mb-0">Student final attendance</h2>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Student</dt>
            <dd class="col-sm-9"><?= e($record['registration_no'] . ' · ' . $record['first_name'] . ' ' . $record['last_name']) ?></dd>
            <dt class="col-sm-3">First In</dt>
            <dd class="col-sm-9"><?= e($dash(isset($record['first_entry']) ? (string) $record['first_entry'] : null)) ?></dd>
            <dt class="col-sm-3">Last Out</dt>
            <dd class="col-sm-9"><?= e($dash(isset($record['last_exit']) ? (string) $record['last_exit'] : null)) ?></dd>
            <dt class="col-sm-3">Attended / Teaching</dt>
            <dd class="col-sm-9"><?= e((string) $record['total_present_minutes']) ?> / <?= e((string) $record['teaching_minutes']) ?> min</dd>
            <dt class="col-sm-3">Attendance %</dt>
            <dd class="col-sm-9"><?= e(number_format((float) $record['attendance_percent'], 2)) ?>%</dd>
            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9"><span class="badge <?= e(status_badge_class((string) $record['status'])) ?>"><?= e((string) $record['status']) ?></span></dd>
            <dt class="col-sm-3">Left Early</dt>
            <dd class="col-sm-9"><?= (int) $record['left_early'] === 1 ? 'Yes' : 'No' ?></dd>
        </dl>
        <p class="app-attendance-note">
            Attendance duration is counted within teaching time. Last Out is the physical exit and may be later than the session end.
        </p>
    </div>
</div>

<div class="card shadow-sm app-table-secondary">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Raw IN/OUT timeline</h2>
        <span class="badge text-bg-secondary"><?= count($events) ?></span>
    </div>
    <div class="card-body py-2">
        <p class="small text-muted mb-0">Diagnostic audit only. Face images and embeddings are not stored here.</p>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Time</th>
                    <th>Event</th>
                    <th>Camera</th>
                    <th>Confidence</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($events === []): ?>
                    <tr>
                        <td colspan="4" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No IN/OUT events for this student in this session.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= e((string) $event['recognized_at']) ?></td>
                            <td><span class="badge <?= e($event['event_type'] === 'IN' ? 'text-bg-success' : 'text-bg-secondary') ?>"><?= e((string) $event['event_type']) ?></span></td>
                            <td><?= e((string) ($event['camera_id'] ?: '—')) ?></td>
                            <td><?= e((string) $event['confidence']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
