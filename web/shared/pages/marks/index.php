<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'lecturer';
/** @var bool $marksCanEdit */
$marksCanEdit = $marksCanEdit ?? false;
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;

if ($marksCanEdit) {
    if ($restrictLecturerId === null || $restrictLecturerId <= 0) {
        set_flash('error', 'No lecturer profile is linked to this account.');
        redirect($academicRoutePrefix . '/dashboard.php');
    }
    $pageTitle = 'Module Marks';
    $moduleId = positive_int($_GET['module_id'] ?? null);
    if ($moduleId !== null && !lecturer_can_manage_module_marks($restrictLecturerId, $moduleId)) {
        deny_access();
    }
    $taughtModules = list_module_lecturer_assignments(null, $restrictLecturerId);
    $assessments = list_marks_assessments_for_lecturer($restrictLecturerId, $moduleId);
} else {
    if (!staff_can_monitor_marks()) {
        deny_access();
    }
    $pageTitle = 'Marks Monitor';
    $moduleId = positive_int($_GET['module_id'] ?? null);
    $lecturerFilter = positive_int($_GET['lecturer_id'] ?? null);
    $studentFilter = positive_int($_GET['student_id'] ?? null);
    $typeFilter = (string) ($_GET['assessment_type'] ?? '');
    $assessments = list_marks_assessments_for_monitor(array_filter([
        'module_id' => $moduleId,
        'lecturer_id' => $lecturerFilter,
        'student_id' => $studentFilter,
        'assessment_type' => in_array($typeFilter, marks_assessment_types(), true) ? $typeFilter : null,
    ]));
    $allModules = list_modules();
    $allLecturers = list_lecturers();
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">
        <?php if ($marksCanEdit): ?>
            Module assessment results (quizzes, exams, and similar). Coursework assignment grades stay under Coursework Assignments.
        <?php else: ?>
            Read-only module assessment results. This is not coursework assignment grading.
        <?php endif; ?>
    </p>
    <?php if ($marksCanEdit): ?>
        <a class="btn btn-primary" href="<?= e(app_url($academicRoutePrefix . '/marks/entry.php')) ?>">Add assessment results</a>
    <?php endif; ?>
</div>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <?php if ($marksCanEdit): ?>
                <div class="col-md-6">
                    <label for="module_id" class="form-label">Module</label>
                    <select class="form-select" id="module_id" name="module_id">
                        <option value="">All modules</option>
                        <?php foreach ($taughtModules as $module): ?>
                            <option value="<?= e((string) $module['module_id']) ?>" <?= $moduleId === (int) $module['module_id'] ? 'selected' : '' ?>>
                                <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="col-md-3">
                    <label for="module_id" class="form-label">Module</label>
                    <select class="form-select" id="module_id" name="module_id">
                        <option value="">All modules</option>
                        <?php foreach ($allModules as $module): ?>
                            <option value="<?= e((string) $module['module_id']) ?>" <?= $moduleId === (int) $module['module_id'] ? 'selected' : '' ?>>
                                <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="lecturer_id" class="form-label">Lecturer</label>
                    <select class="form-select" id="lecturer_id" name="lecturer_id">
                        <option value="">All lecturers</option>
                        <?php foreach ($allLecturers as $lecturer): ?>
                            <option value="<?= e((string) $lecturer['lecturer_id']) ?>" <?= ($lecturerFilter ?? null) === (int) $lecturer['lecturer_id'] ? 'selected' : '' ?>>
                                <?= e($lecturer['first_name'] . ' ' . $lecturer['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="student_id" class="form-label">Student ID</label>
                    <input type="number" class="form-control" id="student_id" name="student_id" min="1" value="<?= isset($studentFilter) && $studentFilter ? e((string) $studentFilter) : '' ?>">
                </div>
                <div class="col-md-3">
                    <label for="assessment_type" class="form-label">Assessment Type</label>
                    <select class="form-select" id="assessment_type" name="assessment_type">
                        <option value="">All types</option>
                        <?php foreach (marks_assessment_types() as $type): ?>
                            <option value="<?= e($type) ?>" <?= ($typeFilter ?? '') === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary">Filter</button>
            </div>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Type</th>
                    <th>Assessment</th>
                    <th>Max marks</th>
                    <?php if (!$marksCanEdit): ?>
                        <th>Lecturer</th>
                    <?php endif; ?>
                    <th>Recorded</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($assessments === []): ?>
                    <tr>
                        <td colspan="<?= $marksCanEdit ? '6' : '7' ?>" class="text-center text-muted py-4">No assessment results found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assessments as $row): ?>
                        <tr>
                            <td><?= e($row['module_code'] . ' – ' . $row['module_name']) ?></td>
                            <td><?= e((string) $row['assessment_type']) ?></td>
                            <td><?= e((string) $row['assessment_name']) ?></td>
                            <td><?= e((string) $row['max_marks']) ?></td>
                            <?php if (!$marksCanEdit): ?>
                                <td><?= e($row['lecturer_first_name'] . ' ' . $row['lecturer_last_name']) ?></td>
                            <?php endif; ?>
                            <td><?= e((string) $row['recorded_count']) ?></td>
                            <td>
                                <?php
                                $query = marks_entry_query(
                                    (int) $row['module_id'],
                                    (string) $row['assessment_type'],
                                    (string) $row['assessment_name'],
                                    $marksCanEdit ? null : (int) $row['recorded_by']
                                );
                                ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url($academicRoutePrefix . '/marks/entry.php?' . $query)) ?>">
                                    <?= $marksCanEdit ? 'Edit' : 'View' ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
