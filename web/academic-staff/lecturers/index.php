<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';

require_student_manager();

$pageTitle = 'Lecturers';
$search = trim((string) ($_GET['search'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$lecturers = list_lecturers(array_filter([
    'search' => $search,
    'status' => in_array($status, staff_statuses(), true) ? $status : null,
]));

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">Read-only lecturer directory for academic staff reference.</p>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body row g-3">
        <div class="col-md-5">
            <label for="search" class="form-label">Search</label>
            <input type="text" class="form-control" id="search" name="search" value="<?= e($search) ?>">
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
            <button type="submit" class="btn btn-outline-primary">Filter</button>
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
                    <th>Department</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($lecturers === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No lecturers found.</td></tr>
                <?php else: ?>
                    <?php foreach ($lecturers as $lecturer): ?>
                        <tr>
                            <td><?= e($lecturer['staff_no']) ?></td>
                            <td><?= e($lecturer['first_name'] . ' ' . $lecturer['last_name']) ?></td>
                            <td><?= e($lecturer['email']) ?></td>
                            <td><?= e($lecturer['department'] ?? '-') ?></td>
                            <td><span class="badge <?= e(status_badge_class($lecturer['status'])) ?>"><?= e($lecturer['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
