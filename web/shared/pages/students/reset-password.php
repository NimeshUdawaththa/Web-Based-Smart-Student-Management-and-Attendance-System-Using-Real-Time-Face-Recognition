<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $studentRoutePrefix */
$studentRoutePrefix = $studentRoutePrefix ?? 'admin/students';

$studentId = positive_int($_GET['id'] ?? $_POST['student_id'] ?? null);
if ($studentId === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$student = get_student($studentId);
if ($student === null) {
    set_flash('error', 'Student not found.');
    redirect($studentRoutePrefix . '/index.php');
}

$pageTitle = 'Reset Student Password';
$resetPath = $studentRoutePrefix . '/reset-password.php?id=' . $studentId;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect($resetPath);
    }

    // Ignore any posted user_id / role / username — target is derived from student_id only.
    try {
        admin_reset_student_password(
            $studentId,
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        set_flash('success', 'Student password reset successfully.');
        redirect($studentRoutePrefix . '/edit.php?id=' . $studentId);
    } catch (InvalidArgumentException $exception) {
        set_flash('error', $exception->getMessage());
        redirect($resetPath);
    } catch (RuntimeException $exception) {
        set_flash('error', $exception->getMessage());
        redirect($resetPath);
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($studentRoutePrefix . '/edit.php?id=' . $studentId)) ?>" class="btn btn-outline-secondary btn-sm">Back to Edit Student</a>
</div>

<div class="alert alert-warning">
    This resets the student login password without the current password.
    Share the new password securely outside the system. The student can change it later from My Profile.
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Account to reset</h2>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Registration No</dt>
            <dd class="col-sm-9"><?= e((string) $student['registration_no']) ?></dd>
            <dt class="col-sm-3">Student name</dt>
            <dd class="col-sm-9"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></dd>
            <dt class="col-sm-3">Username</dt>
            <dd class="col-sm-9"><?= e((string) $student['username']) ?></dd>
            <dt class="col-sm-3">Email</dt>
            <dd class="col-sm-9"><?= e((string) $student['email']) ?></dd>
            <dt class="col-sm-3">Student status</dt>
            <dd class="col-sm-9">
                <span class="badge <?= e(status_badge_class((string) $student['status'])) ?>"><?= e((string) $student['status']) ?></span>
            </dd>
            <dt class="col-sm-3">Account status</dt>
            <dd class="col-sm-9">
                <span class="badge <?= e(status_badge_class((string) $student['account_status'])) ?>"><?= e((string) $student['account_status']) ?></span>
            </dd>
        </dl>
    </div>
</div>

<?php if ((string) $student['status'] === 'INACTIVE' || (string) $student['account_status'] !== 'ACTIVE'): ?>
    <div class="alert alert-info mb-4">
        Resetting the password does <strong>not</strong> reactivate this student.
        An inactive student still cannot sign in until explicitly reactivated.
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h2 class="h6 mb-0">Set new password</h2>
    </div>
    <div class="card-body">
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="student_id" value="<?= e((string) $studentId) ?>">
            <div class="mb-3">
                <label for="new_password" class="form-label">New password</label>
                <input type="password" class="form-control" id="new_password" name="new_password" required minlength="<?= PROFILE_PASSWORD_MIN_LENGTH ?>">
                <div class="form-text">At least <?= PROFILE_PASSWORD_MIN_LENGTH ?> characters.</div>
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm new password</label>
                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="<?= PROFILE_PASSWORD_MIN_LENGTH ?>">
            </div>
            <button type="submit" class="btn btn-warning">Reset password</button>
        </form>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
