<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_admin();

$lecturerId = positive_int($_GET['id'] ?? $_POST['lecturer_id'] ?? null);
if ($lecturerId === null) {
    set_flash('error', 'Lecturer not found.');
    redirect('admin/lecturers/index.php');
}

$lecturer = get_lecturer($lecturerId);
if ($lecturer === null) {
    set_flash('error', 'Lecturer not found.');
    redirect('admin/lecturers/index.php');
}

$pageTitle = 'Edit Lecturer';
$errors = [];
$form = [
    'lecturer_id' => (string) $lecturerId,
    'email' => (string) $lecturer['email'],
    'staff_no' => (string) $lecturer['staff_no'],
    'first_name' => (string) $lecturer['first_name'],
    'last_name' => (string) $lecturer['last_name'],
    'phone' => (string) ($lecturer['phone'] ?? ''),
    'department' => (string) ($lecturer['department'] ?? ''),
    'status' => (string) $lecturer['status'],
    'account_status' => (string) $lecturer['account_status'],
    'password' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect('admin/lecturers/edit.php?id=' . $lecturerId);
    }

    foreach (['email', 'staff_no', 'first_name', 'last_name', 'phone', 'department', 'status', 'account_status', 'password'] as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['email'] === '' || $form['staff_no'] === '' || $form['first_name'] === '' || $form['last_name'] === '') {
        $errors[] = 'Email, staff number, first name, and last name are required.';
    }

    if (!validate_email_address($form['email'])) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($form['password'] !== '' && strlen($form['password']) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    if ($errors === []) {
        try {
            update_lecturer($lecturerId, $form);
            set_flash('success', 'Lecturer updated successfully.');
            redirect('admin/lecturers/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Lecturer update failed: ' . $exception->getMessage());
            $errors[] = 'Unable to update the lecturer.';
        }
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3"><a href="<?= e(app_url('admin/lecturers/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back</a></div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="card shadow-sm">
    <div class="card-body row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="lecturer_id" value="<?= e((string) $lecturerId) ?>">
        <div class="col-md-4"><label class="form-label">Username</label><input class="form-control" value="<?= e($lecturer['username']) ?>" disabled></div>
        <div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e($form['email']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">New Password</label><input type="password" class="form-control" name="password" placeholder="Leave blank to keep current password"></div>
        <div class="col-md-4"><label class="form-label">Staff Number</label><input class="form-control" name="staff_no" value="<?= e($form['staff_no']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">First Name</label><input class="form-control" name="first_name" value="<?= e($form['first_name']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Last Name</label><input class="form-control" name="last_name" value="<?= e($form['last_name']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e($form['phone']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Department</label><input class="form-control" name="department" value="<?= e($form['department']) ?>"></div>
        <div class="col-md-2"><label class="form-label">Profile Status</label><select class="form-select" name="status"><?php foreach (staff_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Account Status</label><select class="form-select" name="account_status"><?php foreach (user_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $form['account_status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Save Changes</button></div>
    </div>
</form>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
