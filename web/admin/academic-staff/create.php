<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_admin();

$pageTitle = 'Add Academic Staff';
$errors = [];
$form = [
    'username' => '',
    'email' => '',
    'password' => '',
    'staff_no' => '',
    'first_name' => '',
    'last_name' => '',
    'phone' => '',
    'position' => '',
    'status' => 'ACTIVE',
    'account_status' => 'ACTIVE',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect('admin/academic-staff/create.php');
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['username'] === '' || $form['email'] === '' || $form['password'] === '' || $form['staff_no'] === '' || $form['first_name'] === '' || $form['last_name'] === '') {
        $errors[] = 'Username, email, password, staff number, first name, and last name are required.';
    }

    if (!validate_email_address($form['email'])) {
        $errors[] = 'Enter a valid email address.';
    }

    if (strlen($form['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($errors === []) {
        try {
            create_academic_staff_member($form);
            set_flash('success', 'Academic staff member created successfully.');
            redirect('admin/academic-staff/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Academic staff creation failed: ' . $exception->getMessage());
            $errors[] = 'Unable to create the academic staff member.';
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
        <div class="col-md-4"><label class="form-label">Username</label><input class="form-control" name="username" value="<?= e($form['username']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e($form['email']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Password</label><input type="password" class="form-control" name="password" required></div>
        <div class="col-md-4"><label class="form-label">Staff Number</label><input class="form-control" name="staff_no" value="<?= e($form['staff_no']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">First Name</label><input class="form-control" name="first_name" value="<?= e($form['first_name']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Last Name</label><input class="form-control" name="last_name" value="<?= e($form['last_name']) ?>" required></div>
        <div class="col-md-4"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= e($form['phone']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Position</label><input class="form-control" name="position" value="<?= e($form['position']) ?>"></div>
        <div class="col-md-2"><label class="form-label">Profile Status</label><select class="form-select" name="status"><?php foreach (staff_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $form['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Account Status</label><select class="form-select" name="account_status"><?php foreach (user_statuses() as $s): ?><option value="<?= e($s) ?>" <?= $form['account_status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><button type="submit" class="btn btn-primary">Create Academic Staff</button></div>
    </div>
</form>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
