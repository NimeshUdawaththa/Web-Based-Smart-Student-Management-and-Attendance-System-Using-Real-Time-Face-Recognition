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
        } else {
            $newId = create_course($payload);
            set_flash('success', 'Course created. Select modules for this course, or finish setup.');
            redirect($academicRoutePrefix . '/courses/modules.php?id=' . $newId);
        }
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Course save failed: ' . $exception->getMessage());
        $errors[] = 'Unable to save the course. Check for a duplicate course code.';
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/courses/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Courses</a>
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

        <div class="app-form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Course' ?></button>
        </div>
    </div>
</form>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
