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

$pageTitle = 'Select Modules for Course';
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/courses/modules.php?id=' . $courseId);
    }

    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'finish') {
        set_flash('success', 'Course setup finished.');
        redirect($academicRoutePrefix . '/courses/view.php?id=' . $courseId);
    }

    $postedIds = $_POST['module_ids'] ?? [];
    if (!is_array($postedIds)) {
        $postedIds = [];
    }

    try {
        save_course_module_selection((int) $courseId, $postedIds);
        set_flash('success', 'Course modules saved. Existing batch modules and student enrolments were not changed.');
        redirect($academicRoutePrefix . '/courses/modules.php?id=' . $courseId);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Course module assignment failed: ' . $exception->getMessage());
        $errors[] = 'Unable to save course modules.';
    }
}

$assignmentModules = list_modules_for_course_assignment((int) $courseId);
$assignedRows = list_course_module_rows((int) $courseId);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="<?= e(app_url($academicRoutePrefix . '/courses/view.php?id=' . $course['course_id'])) ?>" class="btn btn-outline-secondary btn-sm">&larr; Course View</a>
    <a href="<?= e(app_url($academicRoutePrefix . '/courses/view.php?id=' . $course['course_id'])) ?>" class="btn btn-outline-primary btn-sm">Finish Course Setup</a>
</div>

<h1 class="h3 mb-3">Select Modules for Course</h1>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Course Code</dt>
            <dd class="col-sm-9"><?= e($course['course_code']) ?></dd>
            <dt class="col-sm-3">Course Name</dt>
            <dd class="col-sm-9"><?= e($course['course_name']) ?></dd>
            <dt class="col-sm-3">Duration</dt>
            <dd class="col-sm-9"><?= e((string) $course['duration_years']) ?> year<?= (int) $course['duration_years'] === 1 ? '' : 's' ?></dd>
            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9"><span class="badge <?= e(status_badge_class($course['status'])) ?>"><?= e($course['status']) ?></span></dd>
        </dl>
    </div>
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

<p class="text-muted">
    Choose existing <strong>ACTIVE</strong> catalogue modules for this course. The same module can be used by more than one course.
    Unchecking a module sets the relationship to INACTIVE and does not delete batch modules, enrolments, or history.
</p>

<form method="post" class="card shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Module Catalogue</h2>
    </div>
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($assignmentModules === []): ?>
            <p class="text-muted mb-3">No active catalogue modules are available yet. Add modules in the Module Catalogue first.</p>
            <a href="<?= e(app_url($academicRoutePrefix . '/modules/create.php')) ?>" class="btn btn-outline-primary btn-sm">Open Module Catalogue</a>
        <?php else: ?>
            <div class="app-checkbox-grid">
                <?php foreach ($assignmentModules as $module): ?>
                    <div class="form-check">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="module_ids[]"
                            id="course-module-<?= e((string) $module['module_id']) ?>"
                            value="<?= e((string) $module['module_id']) ?>"
                            <?= !empty($module['is_assigned']) ? 'checked' : '' ?>
                        >
                        <label class="form-check-label" for="course-module-<?= e((string) $module['module_id']) ?>">
                            <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                            <span class="text-muted">(<?= e((string) $module['credits']) ?> credits, semester <?= e((string) $module['semester']) ?>)</span>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="app-form-actions">
            <button type="submit" class="btn btn-primary">Save Course Modules</button>
            <a href="<?= e(app_url($academicRoutePrefix . '/courses/view.php?id=' . $course['course_id'])) ?>" class="btn btn-outline-primary">Finish Course Setup</a>
        </div>
    </div>
</form>

<?php if ($assignedRows !== []): ?>
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0">Current relationships (<?= count($assignedRows) ?>)</h2>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Credits</th>
                        <th>Semester</th>
                        <th>Catalogue</th>
                        <th>Assignment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignedRows as $row): ?>
                        <tr>
                            <td><?= e($row['module_code']) ?></td>
                            <td><?= e($row['module_name']) ?></td>
                            <td><?= e((string) $row['credits']) ?></td>
                            <td><?= e((string) $row['semester']) ?></td>
                            <td><span class="badge <?= e(status_badge_class($row['module_status'])) ?>"><?= e($row['module_status']) ?></span></td>
                            <td><span class="badge <?= e(status_badge_class($row['status'])) ?>"><?= e($row['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
