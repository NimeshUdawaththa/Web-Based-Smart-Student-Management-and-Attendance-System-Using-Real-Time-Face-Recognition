<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$pageTitle = 'Courses';
$search = trim((string) ($_GET['search'] ?? ''));
$status = (string) ($_GET['status'] ?? '');

$courses = list_courses(array_filter([
    'search' => $search,
    'status' => in_array($status, course_statuses(), true) ? $status : null,
]));

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="app-list-toolbar">
    <p class="app-list-toolbar__desc">Courses are academic programmes. Batches and students belong to a course. Modules are selected from the Module Catalogue.</p>
    <a href="<?= e(app_url($academicRoutePrefix . '/courses/create.php')) ?>" class="btn btn-primary">Add Course</a>
</div>

<form method="get" class="card app-filter-card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Course code or name">
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (course_statuses() as $courseStatus): ?>
                        <option value="<?= e($courseStatus) ?>" <?= $status === $courseStatus ? 'selected' : '' ?>><?= e($courseStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="app-filter-actions">
                    <button type="submit" class="btn btn-outline-primary">Filter</button>
                    <a href="<?= e(app_url($academicRoutePrefix . '/courses/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Course Code</th>
                    <th>Course Name</th>
                    <th>Duration</th>
                    <th>Status</th>
                    <th>Batches</th>
                    <th>Students</th>
                    <th>Modules</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($courses === []): ?>
                    <tr>
                        <td colspan="8" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No courses found.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($courses as $course): ?>
                        <tr>
                            <td><?= e($course['course_code']) ?></td>
                            <td><?= e($course['course_name']) ?></td>
                            <td><?= e((string) $course['duration_years']) ?> year<?= (int) $course['duration_years'] === 1 ? '' : 's' ?></td>
                            <td><span class="badge <?= e(status_badge_class($course['status'])) ?>"><?= e($course['status']) ?></span></td>
                            <td><?= e((string) $course['batch_count']) ?></td>
                            <td><?= e((string) $course['student_count']) ?></td>
                            <td><?= e((string) $course['module_count']) ?></td>
                            <td class="text-end">
                                <div class="app-actions">
                                    <a href="<?= e(app_url($academicRoutePrefix . '/courses/view.php?id=' . $course['course_id'])) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                    <a href="<?= e(app_url($academicRoutePrefix . '/courses/edit.php?id=' . $course['course_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="<?= e(app_url($academicRoutePrefix . '/courses/modules.php?id=' . $course['course_id'])) ?>" class="btn btn-sm btn-outline-primary">Modules</a>
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
