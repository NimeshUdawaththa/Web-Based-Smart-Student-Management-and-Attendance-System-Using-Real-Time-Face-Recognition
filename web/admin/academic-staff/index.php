<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_admin();

$pageTitle = 'Academic Staff Management';
$search = trim((string) ($_GET['search'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$staffMembers = list_academic_staff(array_filter([
    'search' => $search,
    'status' => in_array($status, staff_statuses(), true) ? $status : null,
]));

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="app-list-toolbar">
    <p class="app-list-toolbar__desc">Manage academic staff profiles and linked accounts.</p>
    <a href="<?= e(app_url('admin/academic-staff/create.php')) ?>" class="btn btn-primary">Add Academic Staff</a>
</div>

<form method="get" class="card app-filter-card mb-4">
    <div class="card-body row g-3">
        <div class="col-md-5">
            <label for="search" class="form-label">Search</label>
            <input type="text" class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Staff no, name, email">
        </div>
        <div class="col-md-4">
            <label for="status" class="form-label">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                <?php foreach (staff_statuses() as $staffStatus): ?>
                    <option value="<?= e($staffStatus) ?>" <?= $status === $staffStatus ? 'selected' : '' ?>><?= e($staffStatus) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
            <div class="app-filter-actions">
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
                    <th>Staff No</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Position</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($staffMembers === []): ?>
                    <tr>
                        <td colspan="6" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No academic staff found.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($staffMembers as $member): ?>
                        <tr>
                            <td><?= e($member['staff_no']) ?></td>
                            <td><?= e($member['first_name'] . ' ' . $member['last_name']) ?></td>
                            <td><?= app_truncate_html((string) $member['email'], 'md') ?></td>
                            <td><?= e($member['position'] ?? '-') ?></td>
                            <td><span class="badge <?= e(status_badge_class($member['status'])) ?>"><?= e($member['status']) ?></span></td>
                            <td class="text-end">
                                <div class="app-actions">
                                    <a href="<?= e(app_url('admin/academic-staff/edit.php?id=' . $member['academic_staff_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
