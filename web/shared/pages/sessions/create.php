<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$pageTitle = 'Create Lecture Session';
$modules = list_modules(['status' => 'ACTIVE']);
$batches = list_batches(null, true);
$lecturersByModule = lecturers_grouped_by_module();
$errors = [];
$form = [
    'module_id' => '',
    'lecturer_id' => '',
    'batch_id' => '',
    'session_date' => app_today(),
    'scheduled_start' => '09:00',
    'scheduled_end' => '11:00',
    'room' => '',
    'late_after_minutes' => '15',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/sessions/create.php');
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    try {
        create_lecture_session([
            'module_id' => positive_int($form['module_id']),
            'lecturer_id' => positive_int($form['lecturer_id']),
            'batch_id' => positive_int($form['batch_id']),
            'session_date' => $form['session_date'],
            'scheduled_start' => $form['scheduled_start'],
            'scheduled_end' => $form['scheduled_end'],
            'room' => $form['room'],
            'late_after_minutes' => (int) $form['late_after_minutes'],
        ]);
        set_flash('success', 'Lecture session created.');
        redirect($academicRoutePrefix . '/sessions/index.php');
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
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
    <a href="<?= e(app_url($academicRoutePrefix . '/sessions/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Sessions</a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post" class="card shadow-sm">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label for="module_id" class="form-label">Module</label>
                <select class="form-select" id="module_id" name="module_id" required>
                    <option value="">Select module</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= e((string) $module['module_id']) ?>" <?= $form['module_id'] === (string) $module['module_id'] ? 'selected' : '' ?>>
                            <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label for="lecturer_id" class="form-label">Lecturer</label>
                <select class="form-select" id="lecturer_id" name="lecturer_id" required></select>
            </div>
            <div class="col-md-6">
                <label for="batch_id" class="form-label">Batch</label>
                <select class="form-select" id="batch_id" name="batch_id" required></select>
            </div>
            <div class="col-md-6">
                <label for="session_date" class="form-label">Date</label>
                <input type="date" class="form-control" id="session_date" name="session_date" value="<?= e($form['session_date']) ?>" required>
            </div>
            <div class="col-md-3">
                <label for="scheduled_start" class="form-label">Start Time</label>
                <input type="time" class="form-control" id="scheduled_start" name="scheduled_start" value="<?= e($form['scheduled_start']) ?>" required>
            </div>
            <div class="col-md-3">
                <label for="scheduled_end" class="form-label">End Time</label>
                <input type="time" class="form-control" id="scheduled_end" name="scheduled_end" value="<?= e($form['scheduled_end']) ?>" required>
            </div>
            <div class="col-md-3">
                <label for="room" class="form-label">Room</label>
                <input type="text" class="form-control" id="room" name="room" value="<?= e($form['room']) ?>">
            </div>
            <div class="col-md-3">
                <label for="late_after_minutes" class="form-label">Late after (minutes)</label>
                <input type="number" min="0" max="180" class="form-control" id="late_after_minutes" name="late_after_minutes" value="<?= e($form['late_after_minutes']) ?>" required>
                <div class="form-text">Used later for Late attendance. Not calculated yet.</div>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary">Create Session</button>
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
