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
    $pageTitle = 'Assessment Results';
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

<div class="app-list-toolbar">
    <p class="app-list-toolbar__desc">
        <?php if ($marksCanEdit): ?>
            Record and manage student results for quizzes, tests, exams and other module assessments.
            Coursework grades are managed separately under Coursework &amp; Assessments.
        <?php else: ?>
            Read-only module assessment results. This is not coursework assignment grading.
        <?php endif; ?>
    </p>
    <?php if ($marksCanEdit): ?>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="<?= e(app_url($academicRoutePrefix . '/marks/entry.php')) ?>">Add assessment results</a>
            <?php if ($moduleId !== null): ?>
                <a class="btn btn-outline-primary" href="<?= e(app_url($academicRoutePrefix . '/marks/summary.php?module_id=' . $moduleId)) ?>">View Module Summary</a>
            <?php else: ?>
                <button type="button" class="btn btn-outline-secondary" disabled title="Select a module first">View Module Summary</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<form method="get" class="card app-filter-card mb-4">
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
                <div class="app-filter-actions">
                    <button type="submit" class="btn btn-outline-primary">Filter</button>
                </div>
            </div>
        </div>
        <?php if ($marksCanEdit): ?>
            <p class="form-text mb-0 mt-2">Select a module, then use View Module Summary to see enrolled students and assessment totals.</p>
        <?php endif; ?>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Module</th>
                    <th>Type</th>
                    <th>Assessment</th>
                    <th>Max marks</th>
                    <?php if (!$marksCanEdit): ?>
                        <th>Lecturer</th>
                    <?php endif; ?>
                    <th>Students recorded</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($assessments === []): ?>
                    <tr>
                        <td colspan="<?= $marksCanEdit ? '6' : '7' ?>" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No assessment results found.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assessments as $row): ?>
                        <?php
                        $recordedCount = (int) $row['recorded_count'];
                        $recordedLabel = $recordedCount === 1 ? '1 student' : $recordedCount . ' students';
                        ?>
                        <tr>
                            <td><?= app_truncate_html((string) ($row['module_code'] . ' – ' . $row['module_name']), 'md') ?></td>
                            <td><?= e((string) $row['assessment_type']) ?></td>
                            <td><?= e((string) $row['assessment_name']) ?></td>
                            <td><?= e((string) $row['max_marks']) ?></td>
                            <?php if (!$marksCanEdit): ?>
                                <td><?= e($row['lecturer_first_name'] . ' ' . $row['lecturer_last_name']) ?></td>
                            <?php endif; ?>
                            <td><?= e($recordedLabel) ?></td>
                            <td class="text-end">
                                <?php
                                $query = marks_entry_query(
                                    (int) $row['module_id'],
                                    (string) $row['assessment_type'],
                                    (string) $row['assessment_name'],
                                    $marksCanEdit ? null : (int) $row['recorded_by']
                                );
                                ?>
                                <div class="app-actions">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(app_url($academicRoutePrefix . '/marks/entry.php?' . $query)) ?>">
                                        <?= $marksCanEdit ? 'Edit' : 'View' ?>
                                    </a>
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
