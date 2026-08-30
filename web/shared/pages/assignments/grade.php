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

$submissionId = positive_int($_GET['submission_id'] ?? $_POST['submission_id'] ?? null);
$submission = $submissionId !== null ? get_assignment_submission($submissionId) : null;
$assignment = $submission !== null ? get_coursework_assignment((int) $submission['assignment_id']) : null;
$student = $submission !== null ? get_student((int) $submission['student_id']) : null;

if ($submission === null || $assignment === null || $student === null) {
    set_flash('error', 'Submission not found.');
    redirect($academicRoutePrefix . '/assignments/index.php');
}

if (!assignment_requires_submission($assignment)) {
    set_flash('error', 'Grading via submissions is only available for Assignment and Presentation activities.');
    redirect($academicRoutePrefix . '/assignments/results.php?id=' . (int) $assignment['assignment_id']);
}

if ($courseworkCanEdit) {
    if ($restrictLecturerId === null || !lecturer_can_grade_submission($restrictLecturerId, $assignment, $submission)) {
        deny_access();
    }
} elseif (!staff_can_monitor_coursework()) {
    deny_access();
}

$errors = [];
$form = [
    'grade' => $submission['grade'] !== null ? (string) $submission['grade'] : '',
    'feedback' => (string) ($submission['feedback'] ?? ''),
];

if ($courseworkCanEdit && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($academicRoutePrefix . '/assignments/grade.php?submission_id=' . $submissionId);
    }

    $form['grade'] = trim((string) ($_POST['grade'] ?? ''));
    $form['feedback'] = trim((string) ($_POST['feedback'] ?? ''));

    try {
        grade_coursework_submission($restrictLecturerId, $submissionId, $form['grade'], $form['feedback']);
        set_flash('success', 'Grade saved.');
        redirect($academicRoutePrefix . '/assignments/submissions.php?id=' . (int) $assignment['assignment_id']);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Assignment grading failed: ' . $exception->getMessage());
        $errors[] = 'Unable to save the grade.';
    }
}

$isGraded = ($submission['status'] ?? '') === 'GRADED';
$pageTitle = $courseworkCanEdit
    ? ($isGraded ? 'Edit Grade' : 'Grade Submission')
    : 'Submission Result';
$timing = assignment_submission_is_late($assignment, $submission) ? 'Late' : 'On Time';

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/assignments/submissions.php?id=' . $assignment['assignment_id'])) ?>">&larr; Submissions</a>
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

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <p class="mb-1"><strong>Registration No:</strong> <?= e((string) $student['registration_no']) ?></p>
        <p class="mb-1"><strong>Student:</strong> <?= e($student['first_name'] . ' ' . $student['last_name']) ?></p>
        <p class="mb-1"><strong>Assignment:</strong> <?= e((string) $assignment['title']) ?></p>
        <p class="mb-1"><strong>Module:</strong> <?= e($assignment['module_code'] . ' – ' . $assignment['module_name']) ?></p>
        <p class="mb-1"><strong>Maximum marks:</strong> <?= e((string) $assignment['max_marks']) ?></p>
        <p class="mb-1"><strong>Submitted at:</strong> <?= e(format_assignment_datetime((string) $submission['submitted_at'])) ?></p>
        <p class="mb-3">
            <strong>Submission timing:</strong> <?= e($timing) ?>
            <?php if (($submission['status'] ?? '') === 'GRADED'): ?>
                <span class="badge <?= e(status_badge_class('GRADED')) ?> ms-1">GRADED</span>
            <?php endif; ?>
        </p>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(assignment_download_url($academicRoutePrefix, 'submission', (int) $submission['submission_id'])) ?>">Download submission</a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if ($courseworkCanEdit): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="submission_id" value="<?= e((string) $submissionId) ?>">
                <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>
                <div class="app-form-section">
                    <h2 class="app-form-section__title">Grade</h2>
                    <div class="mb-3">
                        <label for="grade" class="form-label app-required">Grade</label>
                        <input type="text" inputmode="decimal" class="form-control" id="grade" name="grade" required value="<?= e($form['grade']) ?>">
                        <div class="form-text">Must be between 0 and <?= e((string) $assignment['max_marks']) ?>.</div>
                    </div>
                    <div class="mb-0">
                        <label for="feedback" class="form-label">Feedback <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control" id="feedback" name="feedback" rows="5"><?= e($form['feedback']) ?></textarea>
                    </div>
                </div>
                <div class="app-form-actions">
                    <button type="submit" class="btn btn-primary">Save grade</button>
                </div>
            </form>
        <?php else: ?>
            <p class="mb-2"><strong>Grade:</strong> <?= e(format_assignment_grade_display($submission['grade'], $assignment['max_marks'])) ?></p>
            <p class="mb-1"><strong>Feedback</strong></p>
            <div class="border rounded p-3 bg-light">
                <?= $submission['feedback'] ? nl2br(e((string) $submission['feedback'])) : '<span class="text-muted">No feedback.</span>' ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
