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

$errors = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect('student/assignments/view.php?id=' . $assignmentId);
    }

    try {
        $result = save_student_assignment_submission($assignmentId, $studentId, $_FILES['submission_file'] ?? []);
        set_flash('success', $result['replaced'] ? 'Submission replaced.' : 'Assignment submitted.');
        redirect('student/assignments/view.php?id=' . $assignmentId);
    } catch (InvalidArgumentException $exception) {
        $errors[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Student assignment submit failed: ' . $exception->getMessage());
        $errors[] = 'Unable to save your submission.';
    }
}

$submission = get_assignment_submission_for_student($assignmentId, $studentId);
$displayStatus = student_coursework_display_status($assignment, $submission);
$canSubmit = student_can_submit_coursework_assignment($studentId, $assignment);
$pageTitle = (string) $assignment['title'];

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url('student/assignments/index.php')) ?>">&larr; My Assignments</a>
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
        <p class="mb-2"><strong>Due:</strong> <?= e(format_assignment_datetime((string) $assignment['due_date'])) ?></p>
        <p class="mb-2"><strong>Max marks:</strong> <?= e((string) $assignment['max_marks']) ?></p>
        <p class="mb-3">
            <strong>Status:</strong>
            <span class="badge <?= e(status_badge_class($displayStatus)) ?>"><?= e($displayStatus) ?></span>
        </p>
        <div class="mb-3">
            <strong>Instructions</strong>
            <div class="border rounded p-3 bg-light mt-1"><?= nl2br(e((string) ($assignment['description'] ?? ''))) ?: '<span class="text-muted">No instructions.</span>' ?></div>
        </div>
        <?php if (!empty($assignment['file_path'])): ?>
            <a class="btn btn-outline-primary btn-sm" href="<?= e(assignment_download_url('student', 'brief', (int) $assignment['assignment_id'])) ?>">Download lecturer attachment</a>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Your submission</h2>
        <?php if ($submission !== null): ?>
            <p class="mb-2"><strong>Submitted at:</strong> <?= e(format_assignment_datetime((string) $submission['submitted_at'])) ?></p>
            <p class="mb-3">
                <strong>Submission status:</strong>
                <span class="badge <?= e(status_badge_class($displayStatus === 'LATE' ? 'LATE' : 'SUBMITTED')) ?>"><?= e($displayStatus === 'LATE' ? 'LATE' : 'SUBMITTED') ?></span>
            </p>
            <a class="btn btn-outline-primary btn-sm mb-3" href="<?= e(assignment_download_url('student', 'submission', (int) $submission['submission_id'])) ?>">Download my file</a>
        <?php else: ?>
            <p class="text-muted">You have not submitted a file yet.</p>
        <?php endif; ?>

        <?php if ($canSubmit): ?>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="assignment_id" value="<?= e((string) $assignmentId) ?>">
                <div class="mb-3">
                    <label for="submission_file" class="form-label"><?= $submission ? 'Replace file' : 'Upload file' ?></label>
                    <input type="file" class="form-control" id="submission_file" name="submission_file" required accept=".pdf,.doc,.docx,.zip">
                    <div class="form-text">PDF, DOC, DOCX, or ZIP. You may replace your file until the due date. Maximum <?= e((string) max(1, (int) round(ASSIGNMENT_UPLOAD_MAX_BYTES / 1048576))) ?> MB.</div>
                </div>
                <button type="submit" class="btn btn-primary"><?= $submission ? 'Replace submission' : 'Submit assignment' ?></button>
            </form>
        <?php elseif ($assignment['status'] === 'CLOSED'): ?>
            <p class="text-muted mb-0">This assignment is closed. Submissions are no longer accepted.</p>
        <?php elseif (assignment_due_has_passed($assignment)): ?>
            <p class="text-muted mb-0">The due date has passed. Submissions and replacements are no longer accepted.</p>
        <?php endif; ?>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
