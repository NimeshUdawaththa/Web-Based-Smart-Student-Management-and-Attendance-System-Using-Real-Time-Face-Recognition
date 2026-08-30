<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'lecturer';
/** @var bool $courseworkCanEdit */
$courseworkCanEdit = $courseworkCanEdit ?? false;
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;

if (!$courseworkCanEdit || $restrictLecturerId === null || $restrictLecturerId <= 0) {
    deny_access();
}

$assignmentId = positive_int($_GET['id'] ?? $_POST['assignment_id'] ?? null);
$studentId = positive_int($_GET['student_id'] ?? $_POST['student_id'] ?? null);
$assignment = $assignmentId !== null ? get_coursework_assignment($assignmentId) : null;
$student = $studentId !== null ? get_student($studentId) : null;

if ($assignment === null || $student === null) {
    set_flash('error', 'Activity or student not found.');
    redirect($academicRoutePrefix . '/assignments/index.php');
}

if (!lecturer_can_manage_coursework_assignment($restrictLecturerId, $assignment)) {
    deny_access();
}

if (assignment_requires_submission($assignment)) {
    set_flash('error', 'Direct results are only available for Exam and Practical activities.');
    redirect($academicRoutePrefix . '/assignments/submissions.php?id=' . $assignment['assignment_id']);
}

$existing = get_assignment_direct_result((int) $assignment['assignment_id'], (int) $student['student_id']);
$errors = [];
$form = [
    'marks_obtained' => $existing !== null ? (string) $existing['marks_obtained'] : '',
    'remarks' => $existing !== null ? (string) ($existing['remarks'] ?? '') : '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/assignments/result-entry.php?id=' . $assignmentId . '&student_id=' . $studentId);
    }

    $form['marks_obtained'] = trim((string) ($_POST['marks_obtained'] ?? ''));
    $form['remarks'] = trim((string) ($_POST['remarks'] ?? ''));

    try {
        $action = save_assignment_direct_result(
            $restrictLecturerId,
            (int) $assignment['assignment_id'],
            (int) $student['student_id'],
            $form['marks_obtained'],
            $form['remarks']
        );
        set_flash('success', $action === 'updated' ? 'Result updated.' : 'Result saved.');
        redirect($academicRoutePrefix . '/assignments/results.php?id=' . $assignment['assignment_id']);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Assignment direct result save failed: ' . $exception->getMessage());
        $errors[] = 'Unable to save the result.';
    }
}

$typeLabel = assignment_activity_type_label($assignment);
$pageTitle = ($existing ? 'Update Result' : 'Enter Mark') . ' — ' . (string) $assignment['title'];

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/assignments/results.php?id=' . $assignment['assignment_id'])) ?>">&larr; Results</a>
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

<div class="card shadow-sm">
    <div class="card-body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="assignment_id" value="<?= e((string) $assignment['assignment_id']) ?>">
            <input type="hidden" name="student_id" value="<?= e((string) $student['student_id']) ?>">
            <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>

            <div class="app-form-section">
                <h2 class="app-form-section__title">Student</h2>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Student</label>
                        <input type="text" class="form-control" value="<?= e($student['first_name'] . ' ' . $student['last_name']) ?>" readonly>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Registration No</label>
                        <input type="text" class="form-control" value="<?= e((string) $student['registration_no']) ?>" readonly>
                    </div>
                </div>
            </div>

            <div class="app-form-section">
                <h2 class="app-form-section__title">Assessment</h2>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Assessment</label>
                        <input type="text" class="form-control" value="<?= e($typeLabel . ' — ' . (string) $assignment['title']) ?>" readonly>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Maximum Marks</label>
                        <input type="text" class="form-control" value="<?= e((string) $assignment['max_marks']) ?>" readonly>
                    </div>
                </div>
            </div>

            <div class="app-form-section">
                <h2 class="app-form-section__title">Result</h2>
                <div class="mb-3">
                    <label for="marks_obtained" class="form-label app-required">Marks Obtained</label>
                    <input type="number" class="form-control" id="marks_obtained" name="marks_obtained" min="0" step="0.01"
                           max="<?= e((string) $assignment['max_marks']) ?>" required value="<?= e($form['marks_obtained']) ?>">
                </div>
                <div class="mb-0">
                    <label for="remarks" class="form-label">Remarks</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="4"><?= e($form['remarks']) ?></textarea>
                </div>
            </div>

            <div class="app-form-actions">
                <button type="submit" class="btn btn-primary"><?= $existing ? 'Update Result' : 'Save Result' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(app_url($academicRoutePrefix . '/assignments/results.php?id=' . $assignment['assignment_id'])) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
