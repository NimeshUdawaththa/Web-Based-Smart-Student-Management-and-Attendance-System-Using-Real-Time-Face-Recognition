<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'lecturer';
/** @var int $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? 0;

if ($restrictLecturerId <= 0) {
    set_flash('error', 'No lecturer profile is linked to this account.');
    redirect($academicRoutePrefix . '/dashboard.php');
}

$assignmentId = positive_int($_GET['id'] ?? $_POST['assignment_id'] ?? null);
$isEdit = $assignmentId !== null;
$assignment = $isEdit ? get_coursework_assignment($assignmentId) : null;

if ($isEdit && ($assignment === null || !lecturer_can_manage_coursework_assignment($restrictLecturerId, $assignment))) {
    deny_access();
}

$taughtModules = list_module_lecturer_assignments(null, $restrictLecturerId);
$pageTitle = $isEdit ? 'Edit Assignment' : 'Create Assignment';
$errors = [];
$form = [
    'module_id' => $isEdit ? (string) $assignment['module_id'] : '',
    'title' => $isEdit ? (string) $assignment['title'] : '',
    'description' => $isEdit ? (string) ($assignment['description'] ?? '') : '',
    'due_date' => $isEdit ? str_replace(' ', 'T', substr((string) $assignment['due_date'], 0, 16)) : '',
    'max_marks' => $isEdit ? (string) $assignment['max_marks'] : '100',
    'status' => $isEdit ? (string) $assignment['status'] : 'DRAFT',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/assignments/' . ($isEdit ? 'edit.php?id=' . $assignmentId : 'create.php'));
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $moduleId = positive_int($form['module_id']);
    $due = parse_app_datetime(str_replace('T', ' ', $form['due_date']));
    $maxMarks = filter_var($form['max_marks'], FILTER_VALIDATE_FLOAT);

    if ($moduleId === null || !lecturer_is_assigned_to_module($restrictLecturerId, $moduleId)) {
        $errors[] = 'Select a module you are assigned to teach.';
    }
    if ($form['title'] === '') {
        $errors[] = 'Title is required.';
    }
    if ($due === null) {
        $errors[] = 'Enter a valid due date and time.';
    }
    if ($maxMarks === false || $maxMarks <= 0) {
        $errors[] = 'Max marks must be greater than 0.';
    }
    if (!in_array($form['status'], assignment_statuses(), true)) {
        $errors[] = 'Select a valid status.';
    }

    $newFilePath = $isEdit ? ($assignment['file_path'] ?? null) : null;
    $oldFilePath = $isEdit ? ($assignment['file_path'] ?? null) : null;
    $storedNewFile = null;
    $removeFile = isset($_POST['remove_file']);

    $upload = $_FILES['brief_file'] ?? null;
    $hasUpload = is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($errors === [] && $hasUpload) {
        try {
            $storedNewFile = assignment_store_uploaded_file($upload, 'briefs');
            $newFilePath = $storedNewFile['relative_path'];
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        }
    } elseif ($removeFile && !$hasUpload) {
        $newFilePath = null;
    }

    if ($errors === []) {
        $payload = [
            'module_id' => $moduleId,
            'title' => $form['title'],
            'description' => $form['description'] !== '' ? $form['description'] : null,
            'due_date' => $due->format('Y-m-d H:i:s'),
            'max_marks' => round((float) $maxMarks, 2),
            'status' => $form['status'],
            'file_path' => $newFilePath,
        ];
        try {
            if ($isEdit) {
                update_coursework_assignment($assignmentId, $restrictLecturerId, $payload);
                if ($storedNewFile !== null && is_string($oldFilePath) && $oldFilePath !== $newFilePath) {
                    assignment_delete_stored_file($oldFilePath);
                } elseif ($removeFile && is_string($oldFilePath) && $newFilePath === null) {
                    assignment_delete_stored_file($oldFilePath);
                }
                set_flash('success', 'Assignment updated.');
            } else {
                $assignmentId = create_coursework_assignment($restrictLecturerId, $payload);
                set_flash('success', 'Assignment created.');
            }
            redirect($academicRoutePrefix . '/assignments/view.php?id=' . $assignmentId);
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
            if ($storedNewFile !== null) {
                assignment_delete_stored_file($storedNewFile['relative_path']);
            }
        } catch (Throwable $exception) {
            error_log('Assignment save failed: ' . $exception->getMessage());
            $errors[] = 'Unable to save the assignment.';
            if ($storedNewFile !== null) {
                assignment_delete_stored_file($storedNewFile['relative_path']);
            }
        }
    } elseif ($storedNewFile !== null) {
        assignment_delete_stored_file($storedNewFile['relative_path']);
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="assignment_id" value="<?= e((string) $assignmentId) ?>">
            <?php endif; ?>
            <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>

            <div class="app-form-section">
                <h2 class="app-form-section__title">Assignment Details</h2>
                <div class="mb-3">
                    <label for="module_id" class="form-label app-required">Module</label>
                    <select class="form-select" id="module_id" name="module_id" required>
                        <option value="">Select module</option>
                        <?php foreach ($taughtModules as $module): ?>
                            <option value="<?= e((string) $module['module_id']) ?>" <?= $form['module_id'] === (string) $module['module_id'] ? 'selected' : '' ?>>
                                <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label for="title" class="form-label app-required">Title</label>
                    <input type="text" class="form-control" id="title" name="title" maxlength="200" required value="<?= e($form['title']) ?>">
                </div>
                <div class="mb-0">
                    <label for="description" class="form-label">Description / instructions</label>
                    <textarea class="form-control" id="description" name="description" rows="6"><?= e($form['description']) ?></textarea>
                </div>
            </div>

            <div class="app-form-section">
                <h2 class="app-form-section__title">Schedule &amp; Marks</h2>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="due_date" class="form-label app-required">Due date and time</label>
                        <input type="datetime-local" class="form-control" id="due_date" name="due_date" required value="<?= e($form['due_date']) ?>">
                        <div class="form-text">Times use <?= e(APP_TIMEZONE) ?>.</div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="max_marks" class="form-label app-required">Max marks</label>
                        <input type="number" class="form-control" id="max_marks" name="max_marks" min="0.01" step="0.01" required value="<?= e($form['max_marks']) ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <?php foreach (assignment_statuses() as $status): ?>
                                <option value="<?= e($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Students never see DRAFT assignments.</div>
                    </div>
                </div>
            </div>

            <div class="app-form-section">
                <h2 class="app-form-section__title">Attachment</h2>
                <div class="mb-0">
                    <label for="brief_file" class="form-label">Optional attachment</label>
                    <input type="file" class="form-control" id="brief_file" name="brief_file" accept=".pdf,.doc,.docx,.zip">
                    <div class="form-text">PDF, DOC, DOCX, or ZIP. Maximum <?= e((string) max(1, (int) round(ASSIGNMENT_UPLOAD_MAX_BYTES / 1048576))) ?> MB.</div>
                    <?php if ($isEdit && !empty($assignment['file_path'])): ?>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="remove_file" name="remove_file" value="1">
                            <label class="form-check-label" for="remove_file">Remove current attachment</label>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="app-form-actions">
                <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create assignment' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(app_url($academicRoutePrefix . '/assignments/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
