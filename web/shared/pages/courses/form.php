<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$courseId = positive_int($_GET['id'] ?? $_POST['course_id'] ?? null);
$isEdit = $courseId !== null;
$course = $isEdit ? get_course($courseId) : null;

if ($isEdit && $course === null) {
    set_flash('error', 'Course not found.');
    redirect($academicRoutePrefix . '/courses/index.php');
}

$pageTitle = $isEdit ? 'Edit Course' : 'Add Course';
$errors = [];
$form = [
    'course_id' => $isEdit ? (string) $courseId : '',
    'course_code' => $isEdit ? (string) $course['course_code'] : '',
    'course_name' => $isEdit ? (string) $course['course_name'] : '',
    'duration_years' => $isEdit ? (string) $course['duration_years'] : '3',
    'status' => $isEdit ? (string) $course['status'] : 'ACTIVE',
];

/** @var list<int> $selectedModuleIds */
$selectedModuleIds = [];
if (!$isEdit && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedIds = $_POST['module_ids'] ?? [];
    if (!is_array($postedIds)) {
        $postedIds = [];
    }
    foreach ($postedIds as $rawId) {
        $moduleId = positive_int($rawId);
        if ($moduleId !== null) {
            $selectedModuleIds[$moduleId] = $moduleId;
        }
    }
    $selectedModuleIds = array_values($selectedModuleIds);
}

$catalogueModules = !$isEdit ? list_modules(['status' => 'ACTIVE']) : [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/courses/' . ($isEdit ? 'edit.php?id=' . $courseId : 'create.php'));
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $payload = [
        'course_code' => $form['course_code'],
        'course_name' => $form['course_name'],
        'duration_years' => $form['duration_years'],
        'status' => $form['status'],
    ];

    try {
        if ($isEdit) {
            update_course($courseId, $payload);
            set_flash('success', 'Course updated.');
            redirect($academicRoutePrefix . '/courses/view.php?id=' . $courseId);
        }

        $result = create_course_with_modules($payload, $selectedModuleIds);
        $newId = (int) $result['course_id'];
        $moduleCount = (int) $result['module_count'];
        if ($moduleCount > 0) {
            set_flash(
                'success',
                'Course created successfully with ' . $moduleCount . ' module' . ($moduleCount === 1 ? '' : 's') . '.'
            );
        } else {
            set_flash('success', 'Course created successfully. You can assign modules from Manage Modules.');
        }
        redirect($academicRoutePrefix . '/courses/view.php?id=' . $newId);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Course save failed: ' . $exception->getMessage());
        $errors[] = 'Unable to save the course. Check for a duplicate course code.';
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <a href="<?= e(app_url($academicRoutePrefix . '/courses/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Courses</a>
    <?php if ($isEdit): ?>
        <a href="<?= e(app_url($academicRoutePrefix . '/courses/modules.php?id=' . $courseId)) ?>" class="btn btn-outline-primary btn-sm">Manage Modules</a>
    <?php endif; ?>
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
            <input type="hidden" name="course_id" value="<?= e((string) $courseId) ?>">
        <?php endif; ?>
        <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>

        <div class="app-form-section">
            <h2 class="app-form-section__title">Course Details</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="course_code" class="form-label app-required">Course Code</label>
                    <input type="text" class="form-control" id="course_code" name="course_code" maxlength="20" value="<?= e($form['course_code']) ?>" required>
                </div>
                <div class="col-md-8">
                    <label for="course_name" class="form-label app-required">Course Name</label>
                    <input type="text" class="form-control" id="course_name" name="course_name" maxlength="150" value="<?= e($form['course_name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="duration_years" class="form-label app-required">Duration (years)</label>
                    <input type="number" class="form-control" id="duration_years" name="duration_years" min="<?= e((string) course_duration_min()) ?>" max="<?= e((string) course_duration_max()) ?>" value="<?= e($form['duration_years']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (course_statuses() as $courseStatus): ?>
                            <option value="<?= e($courseStatus) ?>" <?= $form['status'] === $courseStatus ? 'selected' : '' ?>><?= e($courseStatus) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <?php if (!$isEdit): ?>
            <div class="app-form-section">
                <h2 class="app-form-section__title">Modules for this Course</h2>
                <p class="text-muted mb-3">
                    Select the modules that belong to this course. You can change these later from Manage Modules.
                </p>
                <?php if ($catalogueModules === []): ?>
                    <p class="text-muted mb-0">No active catalogue modules are available yet. You can create the course now and assign modules later from Manage Modules.</p>
                <?php else: ?>
                    <div class="mb-3">
                        <label for="course-module-filter" class="form-label">Search modules</label>
                        <input
                            type="search"
                            class="form-control"
                            id="course-module-filter"
                            placeholder="Filter by code or name"
                            autocomplete="off"
                        >
                    </div>
                    <div class="app-checkbox-grid" id="course-module-grid" role="group" aria-label="Modules for this course">
                        <?php foreach ($catalogueModules as $module): ?>
                            <?php
                            $mid = (int) $module['module_id'];
                            $label = $module['module_code'] . ' – ' . $module['module_name'];
                            $searchText = strtolower($module['module_code'] . ' ' . $module['module_name']);
                            ?>
                            <div class="form-check" data-module-search="<?= e($searchText) ?>">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="module_ids[]"
                                    id="create-course-module-<?= e((string) $mid) ?>"
                                    value="<?= e((string) $mid) ?>"
                                    <?= in_array($mid, $selectedModuleIds, true) ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="create-course-module-<?= e((string) $mid) ?>">
                                    <?= e($label) ?>
                                    <span class="text-muted">(<?= e((string) $module['credits']) ?> credits, semester <?= e((string) $module['semester']) ?>)</span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-muted small mt-2 mb-0" id="course-module-filter-empty" hidden>No modules match this search.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="app-form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Course' ?></button>
            <?php if ($isEdit): ?>
                <a href="<?= e(app_url($academicRoutePrefix . '/courses/modules.php?id=' . $courseId)) ?>" class="btn btn-outline-primary">Manage Modules</a>
            <?php else: ?>
                <a href="<?= e(app_url($academicRoutePrefix . '/courses/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if (!$isEdit && $catalogueModules !== []): ?>
<script>
(function () {
    var input = document.getElementById('course-module-filter');
    var grid = document.getElementById('course-module-grid');
    var empty = document.getElementById('course-module-filter-empty');
    if (!input || !grid) {
        return;
    }
    input.addEventListener('input', function () {
        var q = (input.value || '').toLowerCase().trim();
        var visible = 0;
        grid.querySelectorAll('[data-module-search]').forEach(function (row) {
            var match = q === '' || (row.getAttribute('data-module-search') || '').indexOf(q) !== -1;
            row.hidden = !match;
            if (match) {
                visible += 1;
            }
        });
        if (empty) {
            empty.hidden = visible > 0;
        }
    });
})();
</script>
<?php endif; ?>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
