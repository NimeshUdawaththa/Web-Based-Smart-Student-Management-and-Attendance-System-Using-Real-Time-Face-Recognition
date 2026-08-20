<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$student = current_student_profile();
if ($student === null) {
    set_flash('error', 'No student profile is linked to this account.');
    redirect('student/dashboard.php');
}

$studentId = (int) $student['student_id'];
$assignmentId = positive_int($_GET['id'] ?? $_POST['assignment_id'] ?? null);
$assignment = $assignmentId !== null ? get_coursework_assignment($assignmentId) : null;

if ($assignment === null || !student_can_view_coursework_assignment($studentId, $assignment)) {
    deny_access();
}

$requiresSubmission = assignment_requires_submission($assignment);
$showsSchedule = assignment_requires_schedule($assignment);
$typeLabel = assignment_activity_type_label($assignment);
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$requiresSubmission) {
        set_flash('error', 'This activity does not accept student file submissions.');
        redirect('student/assignments/view.php?id=' . $assignmentId);
    }
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect('student/assignments/view.php?id=' . $assignmentId);
    }

    try {
        $result = save_student_assignment_submission($assignmentId, $studentId, $_FILES['submission_file'] ?? []);
        set_flash('success', $result['replaced'] ? 'Submission replaced.' : 'Submission saved.');
        redirect('student/assignments/view.php?id=' . $assignmentId);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Student assignment submit failed: ' . $exception->getMessage());
        $errors[] = 'Unable to save your submission.';
    }
}

$pageTitle = (string) $assignment['title'];
$directResult = !$requiresSubmission
    ? get_assignment_direct_result($assignmentId, $studentId)
    : null;

$submission = $requiresSubmission
    ? get_assignment_submission_for_student($assignmentId, $studentId)
    : null;
$displayStatus = student_coursework_display_status($assignment, $submission);
if (!$requiresSubmission && $directResult !== null) {
    $displayStatus = 'RECORDED';
}
$canSubmit = $requiresSubmission && student_can_submit_coursework_assignment($studentId, $assignment);
$isGraded = assignment_submission_is_graded($submission);
$timing = $submission !== null
    ? (assignment_submission_is_late($assignment, $submission) ? 'Late' : 'On Time')
    : null;

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url('student/assignments/index.php')) ?>">&larr; Coursework &amp; Assessments</a>
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
        <p class="text-muted mb-1"><?= e($assignment['module_code'] . ' – ' . $assignment['module_name']) ?></p>
        <p class="mb-2">
            <span class="badge <?= e(assignment_activity_badge_class($assignment)) ?>"><?= e($typeLabel) ?></span>
            <span class="badge <?= e(status_badge_class($displayStatus)) ?>"><?= e($displayStatus) ?></span>
        </p>
        <p class="mb-2"><strong>Maximum marks:</strong> <?= e((string) $assignment['max_marks']) ?></p>

        <?php if ($showsSchedule): ?>
            <p class="mb-2"><strong><?= $typeLabel === 'Presentation' ? 'Presentation schedule' : 'Scheduled' ?>:</strong> <?= e(format_assignment_schedule_summary($assignment)) ?></p>
            <p class="mb-2"><strong>Room / Location:</strong> <?= e((string) ($assignment['room'] ?? '') !== '' ? (string) $assignment['room'] : '—') ?></p>
        <?php else: ?>
            <p class="mb-2"><strong>Due:</strong> <?= e(format_assignment_datetime((string) $assignment['due_date'])) ?></p>
        <?php endif; ?>

        <div class="mb-3">
            <strong>Instructions</strong>
            <div class="border rounded p-3 bg-light mt-1"><?= nl2br(e((string) ($assignment['description'] ?? ''))) ?: '<span class="text-muted">No instructions.</span>' ?></div>
        </div>

        <?php if ($requiresSubmission && !empty($assignment['file_path'])): ?>
            <a class="btn btn-outline-primary btn-sm" href="<?= e(assignment_download_url('student', 'brief', (int) $assignment['assignment_id'])) ?>">Download lecturer attachment</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$requiresSubmission): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5">Your Result</h2>
            <?php if ($directResult !== null): ?>
                <p class="mb-2">
                    <strong><?= e(format_assignment_grade_display($directResult['marks_obtained'], $assignment['max_marks'])) ?></strong>
                </p>
                <?php if (!empty($directResult['remarks'])): ?>
                    <p class="mb-1"><strong>Remarks</strong></p>
                    <div class="border rounded p-3 bg-light mb-0"><?= nl2br(e((string) $directResult['remarks'])) ?></div>
                <?php else: ?>
                    <p class="text-muted mb-0">No remarks.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-muted mb-0">Not recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <h2 class="h5">Your submission</h2>
            <?php if ($submission !== null): ?>
                <p class="mb-2"><strong>Submitted at:</strong> <?= e(format_assignment_datetime((string) $submission['submitted_at'])) ?></p>
                <p class="mb-2"><strong>On time / Late:</strong> <?= e((string) $timing) ?></p>
                <p class="mb-2">
                    <strong>Submission status:</strong>
                    <span class="badge <?= e(status_badge_class($displayStatus)) ?>"><?= e($displayStatus) ?></span>
                </p>
                <p class="mb-2">
                    <strong>Grade:</strong>
                    <?= $isGraded
                        ? e(format_assignment_grade_display($submission['grade'], $assignment['max_marks']))
                        : 'Not graded yet' ?>
                </p>
                <?php if ($isGraded): ?>
                    <p class="mb-1"><strong>Feedback</strong></p>
                    <div class="border rounded p-3 bg-light mb-3">
                        <?= !empty($submission['feedback'])
                            ? nl2br(e((string) $submission['feedback']))
                            : '<span class="text-muted">No feedback.</span>' ?>
                    </div>
                <?php endif; ?>
                <a class="btn btn-outline-primary btn-sm mb-3" href="<?= e(assignment_download_url('student', 'submission', (int) $submission['submission_id'])) ?>">Download my file</a>
            <?php else: ?>
                <p class="text-muted">You have not submitted a file yet.</p>
                <p class="mb-0"><strong>Grade:</strong> Not graded yet</p>
            <?php endif; ?>

            <?php if ($isGraded): ?>
                <p class="alert alert-info mb-0">Graded submissions cannot be replaced.</p>
            <?php elseif ($canSubmit): ?>
                <form method="post" enctype="multipart/form-data" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="assignment_id" value="<?= e((string) $assignmentId) ?>">
                    <div class="mb-3">
                        <label for="submission_file" class="form-label"><?= $submission ? 'Replace file' : 'Upload file' ?></label>
                        <input type="file" class="form-control" id="submission_file" name="submission_file" required accept=".pdf,.doc,.docx,.zip">
                        <div class="form-text">PDF, DOC, DOCX, or ZIP. You may replace your file until the due date. Maximum <?= e((string) max(1, (int) round(ASSIGNMENT_UPLOAD_MAX_BYTES / 1048576))) ?> MB.</div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $submission ? 'Replace submission' : 'Submit' ?></button>
                </form>
            <?php elseif ($assignment['status'] === 'CLOSED'): ?>
                <p class="text-muted mb-0">This activity is closed. Submissions are no longer accepted.</p>
            <?php elseif (assignment_due_has_passed($assignment)): ?>
                <p class="text-muted mb-0">
                    <?= $showsSchedule
                        ? 'The presentation/scheduled end time has passed. Submissions and replacements are no longer accepted.'
                        : 'The due date has passed. Submissions and replacements are no longer accepted.' ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
