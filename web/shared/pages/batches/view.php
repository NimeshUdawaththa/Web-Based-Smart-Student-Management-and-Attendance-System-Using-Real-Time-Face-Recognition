<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$batchId = positive_int($_GET['id'] ?? $_POST['batch_id'] ?? null);
$batch = $batchId !== null ? get_batch($batchId) : null;
if ($batch === null) {
    set_flash('error', 'Batch not found.');
    redirect($academicRoutePrefix . '/batches/index.php');
}

ensure_batch_modules_table();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/batches/view.php?id=' . $batchId);
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_batch_modules') {
            $postedIds = $_POST['module_ids'] ?? [];
            if (!is_array($postedIds)) {
                $postedIds = [];
            }
            save_batch_module_selection((int) $batchId, $postedIds);
            set_flash('success', 'Batch modules saved. Existing student enrolments were not changed.');
        } elseif ($action === 'sync_batch_modules') {
            $result = sync_batch_module_enrolments((int) $batchId);
            set_flash(
                'success',
                sprintf(
                    'Students checked: %d. Modules assigned: %d. Enrolments added: %d. Enrolments reactivated: %d.',
                    $result['students_checked'],
                    $result['modules_assigned'],
                    $result['enrolments_added'],
                    $result['enrolments_reactivated']
                )
            );
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }
    } catch (InvalidArgumentException $exception) {
        set_flash('error', $exception->getMessage());
    }
    redirect($academicRoutePrefix . '/batches/view.php?id=' . $batchId);
}

$pageTitle = $batch['batch_name'];
$counts = batch_dependency_counts((int) $batch['batch_id']);
$assignmentModules = list_modules_for_batch_assignment((int) $batch['batch_id']);
$activeAssigned = list_active_assigned_batch_modules((int) $batch['batch_id']);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="<?= e(app_url($academicRoutePrefix . '/batches/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Batches</a>
    <a href="<?= e(app_url($academicRoutePrefix . '/batches/edit.php?id=' . $batch['batch_id'])) ?>" class="btn btn-primary btn-sm">Edit</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge <?= e(status_badge_class($batch['status'])) ?>"><?= e($batch['status']) ?></span>
        </div>
        <dl class="row mb-0">
            <dt class="col-sm-3">Batch Name</dt>
            <dd class="col-sm-9"><?= e($batch['batch_name']) ?></dd>
            <dt class="col-sm-3">Course</dt>
            <dd class="col-sm-9">
                <a href="<?= e(app_url($academicRoutePrefix . '/courses/view.php?id=' . $batch['course_id'])) ?>">
                    <?= e($batch['course_code'] . ' - ' . $batch['course_name']) ?>
                </a>
            </dd>
            <dt class="col-sm-3">Intake Year</dt>
            <dd class="col-sm-9"><?= e((string) $batch['intake_year']) ?></dd>
            <dt class="col-sm-3">Start Date</dt>
            <dd class="col-sm-9"><?= e((string) $batch['start_date']) ?></dd>
            <dt class="col-sm-3">End Date</dt>
            <dd class="col-sm-9"><?= e((string) ($batch['end_date'] ?: '—')) ?></dd>
            <dt class="col-sm-3">Created</dt>
            <dd class="col-sm-9"><?= e((string) $batch['created_at']) ?></dd>
            <dt class="col-sm-3">Updated</dt>
            <dd class="col-sm-9"><?= e((string) $batch['updated_at']) ?></dd>
            <dt class="col-sm-3">Students</dt>
            <dd class="col-sm-9"><?= e((string) $counts['student_count']) ?></dd>
            <dt class="col-sm-3">Timetable slots</dt>
            <dd class="col-sm-9"><?= e((string) $counts['schedule_count']) ?></dd>
            <dt class="col-sm-3">Lecture sessions</dt>
            <dd class="col-sm-9"><?= e((string) $counts['session_count']) ?></dd>
        </dl>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Batch Modules</h2>
    </div>
    <div class="card-body">
        <p class="text-muted">
            Choose which <strong>ACTIVE</strong> modules assigned to <?= e($batch['course_code']) ?> this batch currently takes.
            Saving this list does not enrol or drop existing students.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="batch_id" value="<?= e((string) $batch['batch_id']) ?>">
            <input type="hidden" name="action" value="save_batch_modules">
            <?php if ($assignmentModules === []): ?>
                <p class="text-muted mb-0">No active modules are assigned to this course yet. Manage modules from the Course View first.</p>
            <?php else: ?>
                <div class="app-checkbox-grid mb-3">
                    <?php foreach ($assignmentModules as $module): ?>
                        <div class="form-check">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="batch-module-<?= e((string) $module['module_id']) ?>"
                                name="module_ids[]"
                                value="<?= e((string) $module['module_id']) ?>"
                                <?= $module['is_assigned'] ? 'checked' : '' ?>
                                <?= $module['can_assign'] ? '' : 'disabled' ?>
                            >
                            <label class="form-check-label" for="batch-module-<?= e((string) $module['module_id']) ?>">
                                <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                                <span class="badge <?= e(status_badge_class($module['status'])) ?>"><?= e($module['status']) ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="app-form-actions">
                <button type="submit" class="btn btn-primary">Save Batch Modules</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Sync Batch Modules</h2>
    </div>
    <div class="card-body">
        <p class="text-muted">
            Enrol current <strong>ACTIVE</strong> students in this batch into the <?= count($activeAssigned) ?> currently assigned active module<?= count($activeAssigned) === 1 ? '' : 's' ?>.
            This does not run automatically when you save the module list.
        </p>
        <form method="post" onsubmit="return confirm('Enrol current active students in the assigned batch modules? Existing unrelated enrolments will not be removed.');">
            <?= csrf_field() ?>
            <input type="hidden" name="batch_id" value="<?= e((string) $batch['batch_id']) ?>">
            <input type="hidden" name="action" value="sync_batch_modules">
            <button type="submit" class="btn btn-outline-primary" <?= $batch['status'] === 'ACTIVE' ? '' : 'disabled' ?>>Sync Batch Modules</button>
        </form>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
