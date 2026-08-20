<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$batchId = positive_int($_GET['id'] ?? $_POST['batch_id'] ?? null);
$isEdit = $batchId !== null;
$batch = $isEdit ? get_batch($batchId) : null;

if ($isEdit && $batch === null) {
    set_flash('error', 'Batch not found.');
    redirect($academicRoutePrefix . '/batches/index.php');
}

$pageTitle = $isEdit ? 'Edit Batch' : 'Add Batch';
$activeCourses = list_active_courses();
$errors = [];
$form = [
    'batch_id' => $isEdit ? (string) $batchId : '',
    'course_id' => $isEdit ? (string) $batch['course_id'] : '',
    'batch_name' => $isEdit ? (string) $batch['batch_name'] : '',
    'intake_year' => $isEdit ? (string) $batch['intake_year'] : (string) (int) date('Y'),
    'start_date' => $isEdit ? (string) $batch['start_date'] : '',
    'end_date' => $isEdit ? (string) ($batch['end_date'] ?? '') : '',
    'status' => $isEdit ? (string) $batch['status'] : 'ACTIVE',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/batches/' . ($isEdit ? 'edit.php?id=' . $batchId : 'create.php'));
    }

    foreach (array_keys($form) as $key) {
        if ($isEdit && $key === 'course_id') {
            continue;
        }
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    try {
        if ($isEdit) {
            update_batch($batchId, [
                'batch_name' => $form['batch_name'],
                'intake_year' => $form['intake_year'],
                'start_date' => $form['start_date'],
                'end_date' => $form['end_date'],
                'status' => $form['status'],
            ]);
            set_flash('success', 'Batch updated.');
            redirect($academicRoutePrefix . '/batches/view.php?id=' . $batchId);
        } else {
            $newId = create_batch([
                'course_id' => positive_int($form['course_id']) ?? 0,
                'batch_name' => $form['batch_name'],
                'intake_year' => $form['intake_year'],
                'start_date' => $form['start_date'],
                'end_date' => $form['end_date'],
                'status' => $form['status'],
            ]);
            set_flash('success', 'Batch created.');
            redirect($academicRoutePrefix . '/batches/view.php?id=' . $newId);
        }
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Batch save failed: ' . $exception->getMessage());
        $errors[] = 'Unable to save the batch. Check unique names and dates.';
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/batches/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Batches</a>
</div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="card shadow-sm">
    <div class="card-body">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="batch_id" value="<?= e((string) $batchId) ?>">
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label for="course_id" class="form-label">Course</label>
                <?php if ($isEdit): ?>
                    <input type="text" class="form-control" id="course_id" value="<?= e($batch['course_code'] . ' - ' . $batch['course_name']) ?>" disabled>
                    <div class="form-text">The course cannot be changed after a batch is created.</div>
                <?php else: ?>
                    <select class="form-select" id="course_id" name="course_id" required>
                        <option value="">Select course</option>
                        <?php foreach ($activeCourses as $course): ?>
                            <option value="<?= e((string) $course['course_id']) ?>" <?= $form['course_id'] === (string) $course['course_id'] ? 'selected' : '' ?>>
                                <?= e($course['course_code'] . ' - ' . $course['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <label for="batch_name" class="form-label">Batch Name</label>
                <input type="text" class="form-control" id="batch_name" name="batch_name" maxlength="100" value="<?= e($form['batch_name']) ?>" required>
            </div>
            <div class="col-md-4">
                <label for="intake_year" class="form-label">Intake Year</label>
                <input type="number" class="form-control" id="intake_year" name="intake_year" min="<?= e((string) intake_year_min()) ?>" max="<?= e((string) intake_year_max()) ?>" value="<?= e($form['intake_year']) ?>" required>
            </div>
            <div class="col-md-4">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" class="form-control" id="start_date" name="start_date" value="<?= e($form['start_date']) ?>" required>
            </div>
            <div class="col-md-4">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" class="form-control" id="end_date" name="end_date" value="<?= e($form['end_date']) ?>">
                <div class="form-text">Optional. Leave blank if the intake has no planned end date.</div>
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <?php foreach (batch_statuses() as $batchStatus): ?>
                        <option value="<?= e($batchStatus) ?>" <?= $form['status'] === $batchStatus ? 'selected' : '' ?>><?= e($batchStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Batch' ?></button>
        </div>
    </div>
</form>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
