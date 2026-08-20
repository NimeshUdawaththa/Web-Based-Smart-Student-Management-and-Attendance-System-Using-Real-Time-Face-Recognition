<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$pageTitle = 'Lecturer Module Assignments';
$moduleId = positive_int($_GET['module_id'] ?? $_POST['module_id'] ?? null);
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/module-assignments/index.php' . ($moduleId ? '?module_id=' . $moduleId : ''));
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'assign') {
            $assignModuleId = positive_int($_POST['module_id'] ?? null);
            $lecturerId = positive_int($_POST['lecturer_id'] ?? null);
            if ($assignModuleId === null || $lecturerId === null) {
                throw new InvalidArgumentException('Select a module and a lecturer.');
            }
            assign_lecturer_to_module($assignModuleId, $lecturerId);
            set_flash('success', 'Lecturer assigned to the module.');
            redirect($academicRoutePrefix . '/module-assignments/index.php?module_id=' . $assignModuleId);
        }

        if ($action === 'unassign') {
            $assignmentId = positive_int($_POST['module_lecturer_id'] ?? null);
            if ($assignmentId === null) {
                throw new InvalidArgumentException('Assignment not found.');
            }
            unassign_lecturer_from_module($assignmentId);
            set_flash('success', 'Lecturer assignment removed.');
            redirect($academicRoutePrefix . '/module-assignments/index.php' . ($moduleId ? '?module_id=' . $moduleId : ''));
        }

        throw new InvalidArgumentException('Unknown action.');
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$modules = list_modules(['status' => 'ACTIVE']);
$lecturers = list_lecturers(['status' => 'ACTIVE']);
$assignments = list_module_lecturer_assignments($moduleId);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">Only lecturers assigned here can be selected when creating a timetable entry.</p>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h6 mb-0">Assign Lecturer</h2></div>
    <div class="card-body">
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign">
            <div class="col-md-5">
                <label for="module_id" class="form-label">Module</label>
                <select class="form-select" id="module_id" name="module_id" required>
                    <option value="">Select module</option>
                    <?php foreach ($modules as $module): ?>
                        <option value="<?= e((string) $module['module_id']) ?>" <?= $moduleId === (int) $module['module_id'] ? 'selected' : '' ?>>
                            <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label for="lecturer_id" class="form-label">Lecturer</label>
                <select class="form-select" id="lecturer_id" name="lecturer_id" required>
                    <option value="">Select lecturer</option>
                    <?php foreach ($lecturers as $lecturer): ?>
                        <option value="<?= e((string) $lecturer['lecturer_id']) ?>">
                            <?= e($lecturer['first_name'] . ' ' . $lecturer['last_name'] . ' (' . $lecturer['staff_no'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Assign</button>
            </div>
        </form>
    </div>
</div>

<form method="get" class="card app-filter-card mb-4">
    <div class="card-body row g-3">
        <div class="col-md-6">
            <label for="filter_module_id" class="form-label">Filter by module</label>
            <select class="form-select" id="filter_module_id" name="module_id" onchange="this.form.submit()">
                <option value="">All modules</option>
                <?php foreach ($modules as $module): ?>
                    <option value="<?= e((string) $module['module_id']) ?>" <?= $moduleId === (int) $module['module_id'] ? 'selected' : '' ?>>
                        <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Module</th>
                    <th>Course</th>
                    <th>Lecturer</th>
                    <th>Staff No</th>
                    <th>Assigned</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($assignments === []): ?>
                    <tr>
                        <td colspan="6" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No lecturer assignments found.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td><?= app_truncate_html((string) ($assignment['module_code'] . ' – ' . $assignment['module_name']), 'md') ?></td>
                            <td><?= e($assignment['course_code'] ?: '—') ?></td>
                            <td><?= e($assignment['first_name'] . ' ' . $assignment['last_name']) ?></td>
                            <td><?= e($assignment['staff_no']) ?></td>
                            <td><?= e((string) $assignment['assigned_at']) ?></td>
                            <td class="text-end">
                                <div class="app-actions">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Remove this lecturer assignment?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="unassign">
                                        <input type="hidden" name="module_lecturer_id" value="<?= e((string) $assignment['module_lecturer_id']) ?>">
                                        <input type="hidden" name="module_id" value="<?= e((string) ($moduleId ?? '')) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
