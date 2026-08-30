<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$moduleId = positive_int($_GET['id'] ?? $_POST['module_id'] ?? null);
$isEdit = $moduleId !== null;
$module = $isEdit ? get_module($moduleId) : null;

if ($isEdit && $module === null) {
    set_flash('error', 'Module not found.');
    redirect($academicRoutePrefix . '/modules/index.php');
}

$pageTitle = $isEdit ? 'Edit Module' : 'Add Module';
$errors = [];
$form = [
    'module_id' => $isEdit ? (string) $moduleId : '',
    'module_code' => $isEdit ? (string) $module['module_code'] : '',
    'module_name' => $isEdit ? (string) $module['module_name'] : '',
    'credits' => $isEdit ? (string) $module['credits'] : '15.0',
    'semester' => $isEdit ? (string) $module['semester'] : '1',
    'status' => $isEdit ? (string) $module['status'] : 'ACTIVE',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/modules/' . ($isEdit ? 'edit.php?id=' . $moduleId : 'create.php'));
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $credits = filter_var($form['credits'], FILTER_VALIDATE_FLOAT);
    $semester = filter_var($form['semester'], FILTER_VALIDATE_INT);

    if ($form['module_code'] === '' || $form['module_name'] === '') {
        $errors[] = 'Module code and name are required.';
    }
    if ($credits === false || $credits <= 0) {
        $errors[] = 'Credits must be greater than 0.';
    }
    if ($semester === false || $semester < 1) {
        $errors[] = 'Semester must be 1 or greater.';
    }
    if (!in_array($form['status'], module_statuses(), true)) {
        $errors[] = 'Select a valid status.';
    }
    if ($form['module_code'] !== '' && module_code_exists($form['module_code'], $moduleId)) {
        $errors[] = 'That module code already exists in the catalogue.';
    }

    if ($errors === []) {
        $payload = [
            'module_code' => $form['module_code'],
            'module_name' => $form['module_name'],
            'credits' => $credits,
            'semester' => $semester,
            'status' => $form['status'],
        ];
        try {
            if ($isEdit) {
                update_module($moduleId, $payload);
                set_flash('success', 'Module updated.');
            } else {
                create_module($payload);
                set_flash('success', 'Module created.');
            }
            redirect($academicRoutePrefix . '/modules/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Module save failed: ' . $exception->getMessage());
            $errors[] = 'Unable to save the module. Check for a duplicate module code.';
        }
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/modules/index.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Module Catalogue</a>
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
            <input type="hidden" name="module_id" value="<?= e((string) $moduleId) ?>">
        <?php endif; ?>
        <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>

        <div class="app-form-section">
            <h2 class="app-form-section__title">Module Details</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="module_code" class="form-label app-required">Module Code</label>
                    <input type="text" class="form-control" id="module_code" name="module_code" maxlength="20" value="<?= e($form['module_code']) ?>" required>
                </div>
                <div class="col-md-8">
                    <label for="module_name" class="form-label app-required">Module Name</label>
                    <input type="text" class="form-control" id="module_name" name="module_name" maxlength="150" value="<?= e($form['module_name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="credits" class="form-label app-required">Credits</label>
                    <input type="number" step="0.1" min="0.1" class="form-control" id="credits" name="credits" value="<?= e($form['credits']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="semester" class="form-label app-required">Semester</label>
                    <input type="number" min="1" class="form-control" id="semester" name="semester" value="<?= e($form['semester']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach (module_statuses() as $moduleStatus): ?>
                            <option value="<?= e($moduleStatus) ?>" <?= $form['status'] === $moduleStatus ? 'selected' : '' ?>><?= e($moduleStatus) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="app-form-actions">
            <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Create Module' ?></button>
        </div>
    </div>
</form>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
