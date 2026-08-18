<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$scheduleId = positive_int($_GET['id'] ?? $_POST['schedule_id'] ?? null);
$isEdit = $scheduleId !== null;
$schedule = $isEdit ? get_schedule($scheduleId) : null;

if ($isEdit && $schedule === null) {
    set_flash('error', 'Timetable entry not found.');
    redirect($academicRoutePrefix . '/schedules/index.php');
}

$pageTitle = $isEdit ? 'Edit Timetable Entry' : 'Add Timetable Entry';
$modules = list_modules(['status' => 'ACTIVE']);
$batches = list_batches(null, true);
$lecturersByModule = lecturers_grouped_by_module();
$errors = [];
$form = [
    'schedule_id' => $isEdit ? (string) $scheduleId : '',
    'module_id' => $isEdit ? (string) $schedule['module_id'] : '',
    'lecturer_id' => $isEdit ? (string) $schedule['lecturer_id'] : '',
    'batch_id' => $isEdit ? (string) $schedule['batch_id'] : '',
    'day_of_week' => $isEdit ? (string) $schedule['day_of_week'] : '',
    'start_time' => $isEdit ? format_time_display((string) $schedule['start_time']) : '',
    'end_time' => $isEdit ? format_time_display((string) $schedule['end_time']) : '',
    'room' => $isEdit ? (string) ($schedule['room'] ?? '') : '',
    'status' => $isEdit ? (string) $schedule['status'] : 'ACTIVE',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/schedules/' . ($isEdit ? 'edit.php?id=' . $scheduleId : 'create.php'));
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $payload = [
        'module_id' => positive_int($form['module_id']),
        'lecturer_id' => positive_int($form['lecturer_id']),
        'batch_id' => positive_int($form['batch_id']),
        'day_of_week' => $form['day_of_week'],
        'start_time' => $form['start_time'],
        'end_time' => $form['end_time'],
        'room' => $form['room'],
        'status' => $form['status'],
    ];
    $errors = validate_schedule_payload($payload, $scheduleId);

    if ($errors === []) {
        try {
            if ($isEdit) {
                update_schedule($scheduleId, $payload);
                set_flash('success', 'Timetable entry updated.');
            } else {
                create_schedule($payload);
                set_flash('success', 'Timetable entry created.');
            }
            redirect($academicRoutePrefix . '/schedules/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$batchesByCourse = [];
foreach ($batches as $batch) {
    $batchesByCourse[(int) $batch['course_id']][] = [
        'batch_id' => (int) $batch['batch_id'],
        'batch_name' => $batch['batch_name'],
        'intake_year' => $batch['intake_year'],
    ];
}

$modulesById = [];
foreach ($modules as $module) {
    $modulesById[(int) $module['module_id']] = (int) $module['course_id'];
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/schedules/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Timetable</a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" class="card shadow-sm" id="schedule-form">
    <div class="card-body">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="schedule_id" value="<?= e((string) $scheduleId) ?>">
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label for="module_id" class="form-label">Module</label>
                <select class="form-select" id="module_id" name="module_id" required>
                    <option value="">Select module</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= e((string) $module['module_id']) ?>" <?= $form['module_id'] === (string) $module['module_id'] ? 'selected' : '' ?>>
                            <?= e($module['module_code'] . ' – ' . $module['module_name'] . ' (' . $module['course_code'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="lecturer_id" class="form-label">Lecturer (assigned to module)</label>
                <select class="form-select" id="lecturer_id" name="lecturer_id" required>
                    <option value="">Select lecturer</option>
                </select>
            </div>
            <div class="col-md-6">
                <label for="batch_id" class="form-label">Batch (same course as module)</label>
                <select class="form-select" id="batch_id" name="batch_id" required>
                    <option value="">Select batch</option>
                </select>
            </div>
            <div class="col-md-6">
                <label for="day_of_week" class="form-label">Day</label>
                <select class="form-select" id="day_of_week" name="day_of_week" required>
                    <option value="">Select day</option>
                    <?php foreach (weekdays() as $weekday): ?>
                        <option value="<?= e($weekday) ?>" <?= $form['day_of_week'] === $weekday ? 'selected' : '' ?>><?= e(ucfirst(strtolower($weekday))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="start_time" class="form-label">Start Time</label>
                <input type="time" class="form-control" id="start_time" name="start_time" value="<?= e($form['start_time']) ?>" required>
            </div>
            <div class="col-md-3">
                <label for="end_time" class="form-label">End Time</label>
                <input type="time" class="form-control" id="end_time" name="end_time" value="<?= e($form['end_time']) ?>" required>
            </div>
            <div class="col-md-3">
                <label for="room" class="form-label">Room</label>
                <input type="text" class="form-control" id="room" name="room" value="<?= e($form['room']) ?>">
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <?php foreach (schedule_statuses() as $scheduleStatus): ?>
                        <option value="<?= e($scheduleStatus) ?>" <?= $form['status'] === $scheduleStatus ? 'selected' : '' ?>><?= e($scheduleStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Timetable Entry' ?></button>
        </div>
    </div>
</form>

<script type="application/json" id="schedule-options-data"><?= json_encode([
    'modules' => $modulesById,
    'batches' => $batchesByCourse,
    'lecturers' => $lecturersByModule,
    'selectedLecturer' => $form['lecturer_id'],
    'selectedBatch' => $form['batch_id'],
], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= e(asset_url('js/schedule-form.js')) ?>"></script>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
