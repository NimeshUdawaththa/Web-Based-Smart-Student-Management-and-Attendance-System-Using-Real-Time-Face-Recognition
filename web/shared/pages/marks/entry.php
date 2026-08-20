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

$moduleId = positive_int($_GET['module_id'] ?? $_POST['module_id'] ?? null);
$assessmentType = trim((string) ($_GET['assessment_type'] ?? $_POST['assessment_type'] ?? ''));
$assessmentName = trim((string) ($_GET['assessment_name'] ?? $_POST['assessment_name'] ?? ''));
$recordedBy = positive_int($_GET['recorded_by'] ?? null);

if ($marksCanEdit) {
    if ($restrictLecturerId === null || $restrictLecturerId <= 0) {
        set_flash('error', 'No lecturer profile is linked to this account.');
        redirect($academicRoutePrefix . '/dashboard.php');
    }
    $recordedBy = $restrictLecturerId;
    if ($moduleId !== null && !lecturer_can_manage_module_marks($restrictLecturerId, $moduleId)) {
        deny_access();
    }
} else {
    if (!staff_can_monitor_marks()) {
        deny_access();
    }
    if ($recordedBy === null) {
        $recordedBy = 0;
    }
}

$module = $moduleId !== null ? get_module($moduleId) : null;
$isExisting = $assessmentType !== '' && $assessmentName !== '' && $moduleId !== null;
$pageTitle = $marksCanEdit
    ? ($isExisting ? 'Edit Assessment Results' : 'Add Assessment Results')
    : 'View Assessment Results';

$taughtModules = $marksCanEdit ? list_module_lecturer_assignments(null, $restrictLecturerId) : [];
$errors = [];
$maxMarksForm = trim((string) ($_POST['max_marks'] ?? $_GET['max_marks'] ?? '20'));
$students = [];

if ($moduleId !== null) {
    $students = list_module_assessment_entry_rows(
        $moduleId,
        $assessmentType !== '' ? $assessmentType : '__none__',
        $assessmentName !== '' ? $assessmentName : '__none__',
        (int) $recordedBy
    );
    if ($isExisting && $students !== []) {
        foreach ($students as $row) {
            if ($row['max_marks'] !== null) {
                $maxMarksForm = (string) $row['max_marks'];
                break;
            }
        }
    }
}

if ($marksCanEdit && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/marks/entry.php');
    }

    $moduleId = positive_int($_POST['module_id'] ?? null);
    $assessmentType = trim((string) ($_POST['assessment_type'] ?? ''));
    $assessmentName = trim((string) ($_POST['assessment_name'] ?? ''));
    $maxMarksForm = trim((string) ($_POST['max_marks'] ?? ''));
    $marksInput = $_POST['marks'] ?? [];
    $remarksInput = $_POST['remarks'] ?? [];
    $entries = [];

    if (is_array($marksInput)) {
        foreach ($marksInput as $studentId => $value) {
            $id = positive_int($studentId);
            if ($id === null) {
                continue;
            }
            $entries[$id] = [
                'marks' => $value,
                'remarks' => is_array($remarksInput) ? ($remarksInput[$studentId] ?? '') : '',
            ];
        }
    }

    try {
        if ($moduleId === null) {
            throw new InvalidArgumentException('Select a valid module.');
        }
        $result = save_module_assessment_results(
            $restrictLecturerId,
            $moduleId,
            $assessmentType,
            $assessmentName,
            $maxMarksForm,
            $entries
        );
        set_flash('success', 'Results saved (' . $result['inserted'] . ' new, ' . $result['updated'] . ' updated).');
        redirect($academicRoutePrefix . '/marks/entry.php?' . marks_entry_query($moduleId, normalize_assessment_type($assessmentType), normalize_assessment_name($assessmentName)));
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
        if ($moduleId !== null) {
            $students = list_enrolled_students_for_module($moduleId);
            $mapped = [];
            foreach ($students as $student) {
                $sid = (int) $student['student_id'];
                $mapped[] = [
                    'student_id' => $sid,
                    'registration_no' => $student['registration_no'],
                    'first_name' => $student['first_name'],
                    'last_name' => $student['last_name'],
                    'mark_id' => null,
                    'marks_obtained' => $entries[$sid]['marks'] ?? '',
                    'remarks' => $entries[$sid]['remarks'] ?? '',
                    'percentage' => null,
                    'status' => 'Not Recorded',
                ];
            }
            $students = $mapped;
        }
    } catch (Throwable $exception) {
        error_log('Marks save failed: ' . $exception->getMessage());
        $errors[] = 'Unable to save results.';
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/marks/index.php')) ?>">&larr; <?= $marksCanEdit ? 'Assessment Results' : 'Marks Monitor' ?></a>
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

<?php if ($marksCanEdit): ?>
    <form method="post">
        <?= csrf_field() ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>
                <div class="app-form-section">
                    <h2 class="app-form-section__title">Assessment</h2>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="module_id" class="form-label app-required">Module</label>
                            <select class="form-select" id="module_id" name="module_id" required>
                                <option value="">Select module</option>
                                <?php foreach ($taughtModules as $module): ?>
                                    <option value="<?= e((string) $module['module_id']) ?>" <?= $moduleId === (int) $module['module_id'] ? 'selected' : '' ?>>
                                        <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="assessment_type" class="form-label app-required">Assessment Type</label>
                            <select class="form-select" id="assessment_type" name="assessment_type" required <?= $isExisting ? 'readonly' : '' ?>>
                                <?php foreach (marks_assessment_types() as $type): ?>
                                    <option value="<?= e($type) ?>" <?= $assessmentType === $type ? 'selected' : '' ?>><?= e($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="assessment_name" class="form-label app-required">Assessment Name</label>
                            <input type="text" class="form-control" id="assessment_name" name="assessment_name" maxlength="150" required value="<?= e($assessmentName) ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="max_marks" class="form-label app-required">Maximum Marks</label>
                            <input type="text" inputmode="decimal" class="form-control" id="max_marks" name="max_marks" required value="<?= e($maxMarksForm) ?>">
                        </div>
                    </div>
                    <p class="form-text mt-2 mb-0">Leave a mark blank to keep it Not Recorded (not zero). Saving the same assessment again updates existing rows.</p>
                </div>
            </div>
        </div>
<?php else: ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <p class="mb-1"><strong>Module:</strong> <?= $module ? e($module['module_code'] . ' – ' . $module['module_name']) : '—' ?></p>
            <p class="mb-1"><strong>Type:</strong> <?= e($assessmentType) ?></p>
            <p class="mb-1"><strong>Assessment:</strong> <?= e($assessmentName) ?></p>
            <p class="mb-0"><strong>Maximum marks:</strong> <?= e($maxMarksForm) ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Student Marks</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead>
                <tr>
                    <th>Registration No</th>
                    <th>Student</th>
                    <th>Marks</th>
                    <th>Maximum</th>
                    <th>Percentage</th>
                    <th>Remarks</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($moduleId === null): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Select a module to load enrolled students.</td></tr>
                <?php elseif ($students === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No enrolled students for this module.</td></tr>
                <?php else: ?>
                    <?php foreach ($students as $row): ?>
                        <?php $sid = (int) $row['student_id']; ?>
                        <tr>
                            <td><?= e((string) $row['registration_no']) ?></td>
                            <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td>
                                <?php if ($marksCanEdit): ?>
                                    <input type="text" inputmode="decimal" class="form-control form-control-sm" name="marks[<?= e((string) $sid) ?>]" value="<?= e((string) ($row['marks_obtained'] ?? '')) ?>">
                                <?php else: ?>
                                    <?= $row['marks_obtained'] === null || $row['marks_obtained'] === '' ? '—' : e((string) $row['marks_obtained']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= e($maxMarksForm !== '' ? $maxMarksForm : '—') ?></td>
                            <td><?= $row['percentage'] !== null ? e(format_marks_percentage((float) $row['percentage'])) : '—' ?></td>
                            <td>
                                <?php if ($marksCanEdit): ?>
                                    <input type="text" class="form-control form-control-sm" name="remarks[<?= e((string) $sid) ?>]" maxlength="255" value="<?= e((string) ($row['remarks'] ?? '')) ?>">
                                <?php else: ?>
                                    <?= e((string) ($row['remarks'] ?? '')) ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?= e(status_badge_class((string) ($row['status'] ?? 'Not Recorded'))) ?>"><?= e((string) ($row['status'] ?? 'Not Recorded')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($marksCanEdit): ?>
        <div class="app-form-actions">
            <button type="submit" class="btn btn-primary">Save results</button>
            <a class="btn btn-outline-secondary" href="<?= e(app_url($academicRoutePrefix . '/marks/index.php')) ?>">Cancel</a>
        </div>
    </form>
    <form method="get" class="mt-2">
        <input type="hidden" name="assessment_type" value="<?= e($assessmentType) ?>">
        <input type="hidden" name="assessment_name" value="<?= e($assessmentName) ?>">
        <div class="d-flex gap-2 align-items-center">
            <label class="form-label mb-0 small text-muted" for="load_module_id">Load students for module</label>
            <select class="form-select form-select-sm w-auto" id="load_module_id" name="module_id">
                <?php foreach ($taughtModules as $module): ?>
                    <option value="<?= e((string) $module['module_id']) ?>" <?= $moduleId === (int) $module['module_id'] ? 'selected' : '' ?>>
                        <?= e($module['module_code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-primary">Load</button>
        </div>
    </form>
<?php endif; ?>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
