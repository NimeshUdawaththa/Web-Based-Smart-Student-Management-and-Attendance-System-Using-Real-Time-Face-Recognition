<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$courseId = positive_int($_GET['id'] ?? null);
$course = $courseId !== null ? get_course($courseId) : null;
if ($course === null) {
    set_flash('error', 'Course not found.');
    redirect($academicRoutePrefix . '/courses/index.php');
}

$pageTitle = $course['course_code'] . ' — ' . $course['course_name'];
$counts = course_dependency_counts((int) $course['course_id']);
$batches = list_manage_batches(['course_id' => (int) $course['course_id']]);
$courseModules = list_course_module_rows((int) $course['course_id']);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="<?= e(app_url($academicRoutePrefix . '/courses/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Courses</a>
    <div class="d-flex gap-2">
        <a href="<?= e(app_url($academicRoutePrefix . '/courses/modules.php?id=' . $course['course_id'])) ?>" class="btn btn-outline-primary btn-sm">Manage Modules</a>
        <a href="<?= e(app_url($academicRoutePrefix . '/courses/edit.php?id=' . $course['course_id'])) ?>" class="btn btn-primary btn-sm">Edit</a>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge <?= e(status_badge_class($course['status'])) ?>"><?= e($course['status']) ?></span>
        </div>
        <dl class="row mb-0">
            <dt class="col-sm-3">Course Code</dt>
            <dd class="col-sm-9"><?= e($course['course_code']) ?></dd>
            <dt class="col-sm-3">Course Name</dt>
            <dd class="col-sm-9"><?= e($course['course_name']) ?></dd>
            <dt class="col-sm-3">Duration</dt>
            <dd class="col-sm-9"><?= e((string) $course['duration_years']) ?> year<?= (int) $course['duration_years'] === 1 ? '' : 's' ?></dd>
            <dt class="col-sm-3">Created</dt>
            <dd class="col-sm-9"><?= e((string) $course['created_at']) ?></dd>
            <dt class="col-sm-3">Updated</dt>
            <dd class="col-sm-9"><?= e((string) $course['updated_at']) ?></dd>
            <dt class="col-sm-3">Batches</dt>
            <dd class="col-sm-9"><?= e((string) $counts['batch_count']) ?></dd>
            <dt class="col-sm-3">Students</dt>
            <dd class="col-sm-9"><?= e((string) $counts['student_count']) ?></dd>
            <dt class="col-sm-3">Modules</dt>
            <dd class="col-sm-9"><?= e((string) $counts['module_count']) ?></dd>
        </dl>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Linked batches</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Batch</th>
                    <th>Intake</th>
                    <th>Dates</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($batches === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No batches for this course.</td></tr>
                <?php else: ?>
                    <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><?= e($batch['batch_name']) ?></td>
                            <td><?= e((string) $batch['intake_year']) ?></td>
                            <td><?= e((string) $batch['start_date']) ?><?= $batch['end_date'] ? ' → ' . e((string) $batch['end_date']) : '' ?></td>
                            <td><span class="badge <?= e(status_badge_class($batch['status'])) ?>"><?= e($batch['status']) ?></span></td>
                            <td class="text-end">
                                <a href="<?= e(app_url($academicRoutePrefix . '/batches/view.php?id=' . $batch['batch_id'])) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h2 class="h6 mb-0">Modules (<?= count($courseModules) ?>)</h2>
        <a href="<?= e(app_url($academicRoutePrefix . '/courses/modules.php?id=' . $course['course_id'])) ?>" class="btn btn-sm btn-outline-primary">Manage Modules</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Credits</th>
                    <th>Semester</th>
                    <th>Catalogue</th>
                    <th>Assignment</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($courseModules === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No modules assigned to this course.</td></tr>
                <?php else: ?>
                    <?php foreach ($courseModules as $module): ?>
                        <tr>
                            <td><?= e($module['module_code']) ?></td>
                            <td><?= e($module['module_name']) ?></td>
                            <td><?= e((string) $module['credits']) ?></td>
                            <td><?= e((string) $module['semester']) ?></td>
                            <td><span class="badge <?= e(status_badge_class($module['module_status'])) ?>"><?= e($module['module_status']) ?></span></td>
                            <td><span class="badge <?= e(status_badge_class($module['status'])) ?>"><?= e($module['status']) ?></span></td>
                            <td class="text-end">
                                <a href="<?= e(app_url($academicRoutePrefix . '/modules/edit.php?id=' . $module['module_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
