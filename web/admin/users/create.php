<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_admin();

$pageTitle = 'Create User';
$managementRoles = user_management_roles();
$errors = [];
$form = [
    'username' => '',
    'email' => '',
    'password' => '',
    'role' => 'LECTURER',
    'status' => 'ACTIVE',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect('admin/users/create.php');
    }

    foreach (array_keys($form) as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['username'] === '' || $form['email'] === '' || $form['password'] === '') {
        $errors[] = 'Username, email, and password are required.';
    }

    if (!validate_email_address($form['email'])) {
        $errors[] = 'Enter a valid email address.';
    }

    if (strlen($form['password']) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($errors === []) {
        try {
            create_user($form);
            set_flash('success', 'User account created successfully.');
            redirect('admin/users/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('User creation failed: ' . $exception->getMessage());
            $errors[] = 'Unable to create the user account.';
        }
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url('admin/users/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to Users</a>
</div>

<div class="alert alert-info">
    Create Admin, Academic Staff, or Lecturer accounts here.
    Student accounts must be created from <a href="<?= e(app_url('admin/students/register.php')) ?>" class="alert-link">Student Management → Register Student</a>.
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
    <div class="card-body row g-3">
        <?= csrf_field() ?>
        <div class="col-md-6">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control" id="username" name="username" value="<?= e($form['username']) ?>" required>
        </div>
        <div class="col-md-6">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" value="<?= e($form['email']) ?>" required>
        </div>
        <div class="col-md-6">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <div class="col-md-3">
            <label for="role" class="form-label">Role</label>
            <select class="form-select" id="role" name="role">
                <?php foreach ($managementRoles as $authRole): ?>
                    <option value="<?= e($authRole) ?>" <?= $form['role'] === $authRole ? 'selected' : '' ?>><?= e(role_label($authRole)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="status" class="form-label">Status</label>
            <select class="form-select" id="status" name="status">
                <?php foreach (user_statuses() as $userStatus): ?>
                    <option value="<?= e($userStatus) ?>" <?= $form['status'] === $userStatus ? 'selected' : '' ?>><?= e($userStatus) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <button type="submit" class="btn btn-primary">Create User</button>
        </div>
    </div>
</form>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
