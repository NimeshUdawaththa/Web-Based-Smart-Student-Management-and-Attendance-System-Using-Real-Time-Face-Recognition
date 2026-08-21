<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_admin();

$userId = positive_int($_GET['id'] ?? $_POST['user_id'] ?? null);
if ($userId === null) {
    set_flash('error', 'User not found.');
    redirect('admin/users/index.php');
}

$accountUser = get_user($userId);
if ($accountUser === null) {
    set_flash('error', 'User not found.');
    redirect('admin/users/index.php');
}

if ((string) $accountUser['role'] !== 'LECTURER') {
    set_flash('error', 'Only Lecturer accounts can complete a lecturer profile.');
    redirect('admin/users/edit.php?id=' . $userId);
}

if (get_lecturer_id_by_user_id($userId) !== null) {
    set_flash('error', 'This account already has a lecturer profile.');
    redirect('admin/lecturers/index.php');
}

$pageTitle = 'Complete Lecturer Profile';
$errors = [];
$form = [
    'user_id' => (string) $userId,
    'staff_no' => '',
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'department' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect('admin/users/complete-lecturer-profile.php?id=' . $userId);
    }

    foreach (['staff_no', 'first_name', 'last_name', 'phone', 'department'] as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['staff_no'] === '' || $form['first_name'] === '' || $form['last_name'] === '') {
        $errors[] = 'Staff number, first name, and last name are required.';
    }

    if ($errors === []) {
        try {
            complete_lecturer_profile_for_user($userId, $form);
            set_flash('success', 'Lecturer profile completed. The account now appears in Lecturer Management.');
            redirect('admin/lecturers/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Complete lecturer profile failed: ' . $exception->getMessage());
            $errors[] = 'Unable to complete the lecturer profile.';
        }
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url('admin/users/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to Users</a>
</div>

<div class="alert alert-info">
    Completing the Lecturer Management profile for an existing login.
    Username, email, and password are not changed.
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Username</dt>
            <dd class="col-sm-9"><?= e((string) $accountUser['username']) ?></dd>
            <dt class="col-sm-3">Email</dt>
            <dd class="col-sm-9"><?= e((string) $accountUser['email']) ?></dd>
            <dt class="col-sm-3">Role</dt>
            <dd class="col-sm-9"><?= e(role_label((string) $accountUser['role'])) ?></dd>
            <dt class="col-sm-3">Account Status</dt>
            <dd class="col-sm-9"><span class="badge <?= e(status_badge_class((string) $accountUser['status'])) ?>"><?= e((string) $accountUser['status']) ?></span></dd>
        </dl>
    </div>
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
        <input type="hidden" name="user_id" value="<?= e((string) $userId) ?>">
        <p class="app-required-note"><span class="app-required-note__mark" aria-hidden="true">*</span> <span class="visually-hidden">Asterisk means </span>Required</p>

        <div class="app-form-section">
            <h2 class="app-form-section__title">Lecturer Profile</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="staff_no" class="form-label app-required">Staff Number</label>
                    <input type="text" class="form-control" id="staff_no" name="staff_no" maxlength="50" value="<?= e($form['staff_no']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="first_name" class="form-label app-required">First Name</label>
                    <input type="text" class="form-control" id="first_name" name="first_name" maxlength="80" value="<?= e($form['first_name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="last_name" class="form-label app-required">Last Name</label>
                    <input type="text" class="form-control" id="last_name" name="last_name" maxlength="80" value="<?= e($form['last_name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="phone" class="form-label">Phone</label>
                    <input type="text" class="form-control" id="phone" name="phone" maxlength="20" value="<?= e($form['phone']) ?>">
                </div>
                <div class="col-md-4">
                    <label for="department" class="form-label">Department</label>
                    <input type="text" class="form-control" id="department" name="department" maxlength="100" value="<?= e($form['department']) ?>">
                </div>
            </div>
        </div>

        <div class="app-form-actions">
            <button type="submit" class="btn btn-primary">Save Lecturer Profile</button>
            <a href="<?= e(app_url('admin/users/index.php')) ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
