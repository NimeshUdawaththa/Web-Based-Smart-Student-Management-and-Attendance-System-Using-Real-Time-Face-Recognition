<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';
/** @var string $studentRoutePrefix */
$studentRoutePrefix = $studentRoutePrefix ?? $academicRoutePrefix . '/students';

$pageTitle = 'Student Module Enrollment';
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/enrollments/index.php');
    }

    try {
        $batchId = positive_int($_POST['batch_id'] ?? null);
        $moduleId = positive_int($_POST['module_id'] ?? null);
        if ($batchId === null || $moduleId === null) {
            throw new InvalidArgumentException('Select a batch and a module.');
        }
        $result = bulk_enroll_batch_in_module($batchId, $moduleId);
        set_flash(
            'success',
            sprintf(
                'Batch enrolment updated: %d new, %d reactivated, %d already enrolled.',
                $result['enrolled'],
                $result['reactivated'],
                $result['skipped']
            )
        );
        redirect($academicRoutePrefix . '/enrollments/index.php');
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$batches = list_batches(null, true);
$selectedBatchId = positive_int($_POST['batch_id'] ?? $_GET['batch_id'] ?? null);
$modules = $selectedBatchId !== null ? list_active_modules_for_batch($selectedBatchId) : [];
$modulesByBatch = [];
foreach ($batches as $batch) {
    $courseModules = list_active_modules_for_batch((int) $batch['batch_id']);
    $modulesByBatch[(string) $batch['batch_id']] = array_map(
        static function (array $module): array {
            return [
                'module_id' => (int) $module['module_id'],
                'label' => $module['module_code'] . ' – ' . $module['module_name'],
            ];
        },
        $courseModules
    );
}
$students = list_students(['status' => 'ACTIVE']);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">
    Future attendance will only be allowed for students enrolled in the lecture module and belonging to the lecture batch.
    Dropping an enrolment sets status to <strong>DROPPED</strong> and does not delete history.
</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Bulk Enrol Batch in Module</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-5">
                <label for="batch_id" class="form-label">Batch</label>
                <select class="form-select" id="batch_id" name="batch_id" data-module-target="module_id" required>
                    <option value="">Select batch</option>
                    <?php foreach ($batches as $batch): ?>
                        <option value="<?= e((string) $batch['batch_id']) ?>" <?= $selectedBatchId === (int) $batch['batch_id'] ? 'selected' : '' ?>>
                            <?= e($batch['course_code'] . ' – ' . $batch['batch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label for="module_id" class="form-label">Module</label>
                <select class="form-select" id="module_id" name="module_id" required>
                    <option value="">Select module</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= e((string) $module['module_id']) ?>">
                            <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Enrol Batch</button>
            </div>
        </form>
        <script type="application/json" id="enrol-modules-by-batch"><?= json_encode($modulesByBatch, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Enrol a Single Student</h2></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Registration No</th>
                    <th>Name</th>
                    <th>Course / Batch</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($students === []): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No active students found.</td></tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?= e($student['registration_no']) ?></td>
                            <td><?= e($student['first_name'] . ' ' . $student['last_name']) ?></td>
                            <td><?= e($student['course_code'] . ' – ' . $student['batch_name']) ?></td>
                            <td class="text-end">
                                <a href="<?= e(app_url($academicRoutePrefix . '/enrollments/student.php?id=' . $student['student_id'])) ?>" class="btn btn-sm btn-outline-primary">Manage Modules</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
