<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$pageTitle = 'Module Catalogue';
$search = trim((string) ($_GET['search'] ?? ''));
$status = (string) ($_GET['status'] ?? '');

$modules = list_modules(array_filter([
    'search' => $search,
    'status' => in_array($status, module_statuses(), true) ? $status : null,
]));

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">Create a module once in the catalogue, then assign it to one or more courses.</p>
    <a href="<?= e(app_url($academicRoutePrefix . '/modules/create.php')) ?>" class="btn btn-primary">Add Module</a>
</div>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Module code or name">
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (module_statuses() as $moduleStatus): ?>
                        <option value="<?= e($moduleStatus) ?>" <?= $status === $moduleStatus ? 'selected' : '' ?>><?= e($moduleStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-outline-primary">Filter</button>
                <a href="<?= e(app_url($academicRoutePrefix . '/modules/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Credits</th>
                    <th>Semester</th>
                    <th>Status</th>
                    <th>Courses</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($modules === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No modules found.</td></tr>
                <?php else: ?>
                    <?php foreach ($modules as $module): ?>
                        <tr>
                            <td><?= e($module['module_code']) ?></td>
                            <td><?= e($module['module_name']) ?></td>
                            <td><?= e((string) $module['credits']) ?></td>
                            <td><?= e((string) $module['semester']) ?></td>
                            <td><span class="badge <?= e(status_badge_class($module['status'])) ?>"><?= e($module['status']) ?></span></td>
                            <td><?= e((string) $module['course_count']) ?></td>
                            <td class="text-end">
                                <a href="<?= e(app_url($academicRoutePrefix . '/modules/edit.php?id=' . $module['module_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
