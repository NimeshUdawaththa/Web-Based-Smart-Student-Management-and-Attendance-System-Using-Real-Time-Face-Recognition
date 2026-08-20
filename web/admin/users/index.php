<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_admin();

$pageTitle = 'User Management';
$search = trim((string) ($_GET['search'] ?? ''));
$role = (string) ($_GET['role'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$managementRoles = user_management_roles();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['toggle_user_id'], $_POST['toggle_status'])) {
    if (!verify_csrf()) {
        set_flash('error', 'Unable to update the account. Please try again.');
        redirect('admin/users/index.php');
    }

    $userId = positive_int($_POST['toggle_user_id']);
    $newStatus = (string) $_POST['toggle_status'];

    if ($userId !== null && in_array($newStatus, user_statuses(), true)) {
        try {
            set_user_status($userId, $newStatus);
            set_flash('success', 'User account status updated.');
        } catch (InvalidArgumentException $exception) {
            set_flash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('User status update failed: ' . $exception->getMessage());
            set_flash('error', 'Unable to update the account.');
        }
    }

    redirect('admin/users/index.php?' . http_build_query(array_filter([
        'search' => $search,
        'role' => $role,
        'status' => $status,
    ])));
}

$users = list_users(array_filter([
    'search' => $search,
    'role' => in_array($role, $managementRoles, true) ? $role : null,
    'status' => in_array($status, user_statuses(), true) ? $status : null,
]));

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">Manage Admin, Academic Staff, and Lecturer login accounts. Student accounts are managed from Student Management.</p>
    <a href="<?= e(app_url('admin/users/create.php')) ?>" class="btn btn-primary">Create User</a>
</div>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Username or email">
            </div>
            <div class="col-md-3">
                <label for="role" class="form-label">Role</label>
                <select class="form-select" id="role" name="role">
                    <option value="">All roles</option>
                    <?php foreach ($managementRoles as $authRole): ?>
                        <option value="<?= e($authRole) ?>" <?= $role === $authRole ? 'selected' : '' ?>><?= e(role_label($authRole)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (user_statuses() as $userStatus): ?>
                        <option value="<?= e($userStatus) ?>" <?= $status === $userStatus ? 'selected' : '' ?>><?= e($userStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-outline-primary">Filter</button>
            </div>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No users found.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $listedUser): ?>
                        <tr>
                            <td><?= e($listedUser['username']) ?></td>
                            <td><?= e($listedUser['email']) ?></td>
                            <td><?= e(role_label($listedUser['role'])) ?></td>
                            <td><span class="badge <?= e(status_badge_class($listedUser['status'])) ?>"><?= e($listedUser['status']) ?></span></td>
                            <td class="text-end">
                                <a href="<?= e(app_url('admin/users/edit.php?id=' . $listedUser['user_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                <?php if ($listedUser['status'] === 'ACTIVE'): ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="toggle_user_id" value="<?= e((string) $listedUser['user_id']) ?>">
                                        <input type="hidden" name="toggle_status" value="INACTIVE">
                                        <button type="submit" class="btn btn-sm btn-outline-warning">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="toggle_user_id" value="<?= e((string) $listedUser['user_id']) ?>">
                                        <input type="hidden" name="toggle_status" value="ACTIVE">
                                        <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
