<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_admin();

$userId = positive_int($_GET['id'] ?? $_POST['user_id'] ?? null);
if ($userId === null) {
    set_flash('error', 'User not found.');
    redirect('admin/users/index.php');
}

$user = get_user($userId);
if ($user === null) {
    set_flash('error', 'User not found.');
    redirect('admin/users/index.php');
}

$pageTitle = 'Edit User';
$errors = [];
$form = [
    'user_id' => (string) $userId,
    'username' => (string) $user['username'],
    'email' => (string) $user['email'],
    'role' => (string) $user['role'],
    'status' => (string) $user['status'],
    'password' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to submit the form. Please try again.');
        redirect('admin/users/edit.php?id=' . $userId);
    }

    foreach (['username', 'email', 'role', 'status', 'password'] as $key) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if ($form['username'] === '' || $form['email'] === '') {
        $errors[] = 'Username and email are required.';
    }

    if (!validate_email_address($form['email'])) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($form['password'] !== '' && strlen($form['password']) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    if ($errors === []) {
        try {
            update_user($userId, [
                'username' => $form['username'],
                'email' => $form['email'],
                'role' => $form['role'],
                'status' => $form['status'],
                'password' => $form['password'] !== '' ? $form['password'] : null,
            ]);
            set_flash('success', 'User account updated successfully.');
            redirect('admin/users/index.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('User update failed: ' . $exception->getMessage());
            $errors[] = 'Unable to update the user account.';
        }
    }
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url('admin/users/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Back to Users</a>
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
        <input type="hidden" name="user_id" value="<?= e((string) $userId) ?>">
        <div class="col-md-6">
            <label for="username" class="form-label">Username</label>
            <input type="text" class="form-control" id="username" name="username" value="<?= e($form['username']) ?>" required>
        </div>
        <div class="col-md-6">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" value="<?= e($form['email']) ?>" required>
        </div>
        <div class="col-md-6">
            <label for="password" class="form-label">New Password</label>
            <input type="password" class="form-control" id="password" name="password" placeholder="Leave blank to keep current password">
        </div>
        <div class="col-md-3">
            <label for="role" class="form-label">Role</label>
            <select class="form-select" id="role" name="role">
                <?php foreach (AUTH_ROLES as $authRole): ?>
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
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </div>
</form>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
