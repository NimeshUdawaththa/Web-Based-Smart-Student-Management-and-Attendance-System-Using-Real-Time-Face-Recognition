<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$pageTitle = 'Modules';
$search = trim((string) ($_GET['search'] ?? ''));
$courseId = positive_int($_GET['course_id'] ?? null);
$status = (string) ($_GET['status'] ?? '');

$modules = list_modules(array_filter([
    'search' => $search,
    'course_id' => $courseId,
    'status' => in_array($status, module_statuses(), true) ? $status : null,
]));
$courses = list_active_courses();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">Modules belong to a course and are used for timetable, enrolment, and lecture sessions.</p>
    <a href="<?= e(app_url($academicRoutePrefix . '/modules/create.php')) ?>" class="btn btn-primary">Add Module</a>
</div>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Module code or name">
            </div>
            <div class="col-md-3">
                <label for="course_id" class="form-label">Course</label>
                <select class="form-select" id="course_id" name="course_id">
                    <option value="">All courses</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= e((string) $course['course_id']) ?>" <?= $courseId === (int) $course['course_id'] ? 'selected' : '' ?>>
                            <?= e($course['course_code'] . ' - ' . $course['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (module_statuses() as $moduleStatus): ?>
                        <option value="<?= e($moduleStatus) ?>" <?= $status === $moduleStatus ? 'selected' : '' ?>><?= e($moduleStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
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
                    <th>Course</th>
                    <th>Semester</th>
                    <th>Credits</th>
                    <th>Status</th>
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
                            <td><?= e($module['course_code']) ?></td>
                            <td><?= e((string) $module['semester']) ?></td>
                            <td><?= e((string) $module['credits']) ?></td>
                            <td><span class="badge <?= e(status_badge_class($module['status'])) ?>"><?= e($module['status']) ?></span></td>
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
