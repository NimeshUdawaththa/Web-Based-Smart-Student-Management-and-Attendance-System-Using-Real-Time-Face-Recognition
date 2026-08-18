<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_admin();

$staffId = positive_int($_GET['id'] ?? $_POST['academic_staff_id'] ?? null);
if ($staffId === null) {
    set_flash('error', 'Academic staff member not found.');
    redirect('admin/academic-staff/index.php');
}

$member = get_academic_staff_member($staffId);
if ($member === null) {
    set_flash('error', 'Academic staff member not found.');
    redirect('admin/academic-staff/index.php');
}

$pageTitle = 'Edit Academic Staff';
$errors = [];
$form = [
    'academic_staff_id' => (string) $staffId,
    'email' => (string) $member['email'],
    'staff_no' => (string) $member['staff_no'],
    'first_name' => (string) $member['first_name'],
    'last_name' => (string) $member['last_name'],
    'phone' => (string) ($member['phone'] ?? ''),
    'position' => (string) ($member['position'] ?? ''),
    'status' => (string) $member['status'],
    'account_status' => (string) $member['account_status'],
    'password' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect('admin/academic-staff/edit.php?id=' . $staffId);
    }

    foreach (['email', 'staff_no', 'first_name', 'last_name', 'phone', 'position', 'status', 'account_status', 'password'] as $key) {
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
            update_academic_staff_member($staffId, $form);
            set_flash('success', 'Academic staff member updated successfully.');
            redirect('admin/academic-staff/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Academic staff update failed: ' . $exception->getMessage());
            $errors[] = 'Unable to update the academic staff member.';
        }
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3"><a href="<?= e(app_url('admin/academic-staff/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back</a></div>

<?php if ($errors !== []): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="card shadow-sm">
    <div class="card-body row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="academic_staff_id" value="<?= e((string) $staffId) ?>">
        <div class="col-md-4"><label class="form-label">Username</label><input class="form-control" value="<?= e($member['username']) ?>" disabled></div>
        <div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e($form['email']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">New Password</label><input type="password" class="form-control" name="password" placeholder="Leave blank to keep current password"></div>
        <div class="col-md-4"><label class="form-label">Staff Number</label><input class="form-control" name="staff_no" value="<?= e($form['staff_no']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">First Name</label><input class="form-control" name="first_name" value="<?= e($form['first_name']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Last Name</label><input class="form-control" name="last_name" value="<?= e($form['last_name']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e($form['phone']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Position</label><input class="form-control" name="position" value="<?= e($form['position']) ?>"></div>
        <div class="col-md-2"><label class="form-label">Profile Status</label><select class="form-select" name="status"><?php foreach (staff_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Account Status</label><select class="form-select" name="account_status"><?php foreach (user_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $form['account_status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Save Changes</button></div>
    </div>
</form>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
