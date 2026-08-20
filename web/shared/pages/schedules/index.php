<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';
/** @var bool $readOnly */
$readOnly = $readOnly ?? false;
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;

$pageTitle = $readOnly ? 'My Timetable' : 'Timetable Management';
$moduleId = positive_int($_GET['module_id'] ?? null);
$batchId = positive_int($_GET['batch_id'] ?? null);
$day = (string) ($_GET['day_of_week'] ?? '');
$status = (string) ($_GET['status'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$readOnly) {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/schedules/index.php');
    }

    $scheduleId = positive_int($_POST['schedule_id'] ?? null);
    $newStatus = (string) ($_POST['status'] ?? '');
    try {
        if ($scheduleId === null) {
            throw new InvalidArgumentException('Timetable entry not found.');
        }
        set_schedule_status($scheduleId, $newStatus);
        set_flash('success', $newStatus === 'ACTIVE' ? 'Timetable entry activated.' : 'Timetable entry deactivated.');
        redirect($academicRoutePrefix . '/schedules/index.php');
    } catch (InvalidArgumentException $exception) {
        set_flash('error', $exception->getMessage());
        redirect($academicRoutePrefix . '/schedules/index.php');
    }
}

$schedules = list_schedules(array_filter([
    'module_id' => $moduleId,
    'batch_id' => $batchId,
    'lecturer_id' => $restrictLecturerId,
    'day_of_week' => in_array($day, weekdays(), true) ? $day : null,
    'status' => in_array($status, schedule_statuses(), true) ? $status : null,
]));

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">A timetable entry is a recurring weekly slot. Lecture sessions are generated from these entries.</p>
    <?php if (!$readOnly): ?>
        <a href="<?= e(app_url($academicRoutePrefix . '/schedules/create.php')) ?>" class="btn btn-primary">Add Timetable Entry</a>
    <?php endif; ?>
</div>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body row g-3">
        <div class="col-md-3">
            <label for="day_of_week" class="form-label">Day</label>
            <select class="form-select" id="day_of_week" name="day_of_week">
                <option value="">All days</option>
                <?php foreach (weekdays() as $weekday): ?>
                    <option value="<?= e($weekday) ?>" <?= $day === $weekday ? 'selected' : '' ?>><?= e(ucfirst(strtolower($weekday))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="status" class="form-label">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach (schedule_statuses() as $scheduleStatus): ?>
                    <option value="<?= e($scheduleStatus) ?>" <?= $status === $scheduleStatus ? 'selected' : '' ?>><?= e($scheduleStatus) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end gap-2">
            <button type="submit" class="btn btn-outline-primary">Filter</button>
            <a href="<?= e(app_url($academicRoutePrefix . '/schedules/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Break</th>
                    <th>Module</th>
                    <th>Batch</th>
                    <th>Lecturer</th>
                    <th>Room</th>
                    <th>Status</th>
                    <?php if (!$readOnly): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($schedules === []): ?>
                    <tr><td colspan="<?= $readOnly ? 8 : 9 ?>" class="text-center text-muted py-4">No timetable entries found.</td></tr>
                <?php else: ?>
                    <?php foreach ($schedules as $schedule): ?>
                        <tr>
                            <td><?= e(ucfirst(strtolower($schedule['day_of_week']))) ?></td>
                            <td><?= e(format_time_display($schedule['start_time']) . ' – ' . format_time_display($schedule['end_time'])) ?></td>
                            <td><?= e(format_break_display($schedule['break_start'] ?? null, $schedule['break_end'] ?? null)) ?></td>
                            <td><?= e($schedule['module_code']) ?></td>
                            <td><?= e($schedule['batch_name']) ?></td>
                            <td><?= e($schedule['lecturer_first_name'] . ' ' . $schedule['lecturer_last_name']) ?></td>
                            <td><?= e($schedule['room'] ?: '-') ?></td>
                            <td><span class="badge <?= e(status_badge_class($schedule['status'])) ?>"><?= e($schedule['status']) ?></span></td>
                            <?php if (!$readOnly): ?>
                                <td class="text-end">
                                    <a href="<?= e(app_url($academicRoutePrefix . '/schedules/edit.php?id=' . $schedule['schedule_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="schedule_id" value="<?= e((string) $schedule['schedule_id']) ?>">
                                        <input type="hidden" name="status" value="<?= e($schedule['status'] === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE') ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                                            <?= $schedule['status'] === 'ACTIVE' ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
