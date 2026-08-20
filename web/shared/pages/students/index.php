<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $studentRoutePrefix e.g. admin/students or academic-staff/students */
$studentRoutePrefix = $studentRoutePrefix ?? 'admin/students';
$readOnly = $readOnly ?? false;
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;
$isLecturerDirectory = $restrictLecturerId !== null && $restrictLecturerId > 0;

$pageTitle = $isLecturerDirectory ? 'Student Directory' : 'Student Management';
$search = trim((string) ($_GET['search'] ?? ''));
$courseId = positive_int($_GET['course_id'] ?? null);
$batchId = positive_int($_GET['batch_id'] ?? null);
$moduleId = positive_int($_GET['module_id'] ?? null);
$status = (string) ($_GET['status'] ?? ($isLecturerDirectory ? 'ACTIVE' : ''));

$filters = array_filter([
    'search' => $search,
    'course_id' => $courseId,
    'batch_id' => $batchId,
    'module_id' => $isLecturerDirectory ? $moduleId : null,
    'status' => in_array($status, student_statuses(), true) ? $status : null,
]);

if ($isLecturerDirectory) {
    $students = list_students_for_lecturer($restrictLecturerId, $filters);
    $courses = list_courses_for_lecturer_directory($restrictLecturerId);
    $batches = list_batches_for_lecturer_directory($restrictLecturerId, $courseId);
    $modules = list_module_lecturer_assignments(null, $restrictLecturerId);
} else {
    $students = list_students($filters);
    $courses = list_active_courses();
    $batches = [];
    $modules = [];
}

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="app-list-toolbar">
    <p class="app-list-toolbar__desc">
        <?= $isLecturerDirectory
            ? 'Students currently enrolled in modules assigned to you.'
            : 'View and manage student records.' ?>
    </p>
    <?php if (!$readOnly): ?>
        <a href="<?= e(app_url($studentRoutePrefix . '/register.php')) ?>" class="btn btn-primary">Register Student</a>
    <?php endif; ?>
</div>

<form method="get" class="card app-filter-card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-<?= $isLecturerDirectory ? '3' : '4' ?>">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Registration no, name, email">
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
            <?php if ($isLecturerDirectory): ?>
                <div class="col-md-2">
                    <label for="batch_id" class="form-label">Batch</label>
                    <select class="form-select" id="batch_id" name="batch_id">
                        <option value="">All batches</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?= e((string) $batch['batch_id']) ?>" <?= $batchId === (int) $batch['batch_id'] ? 'selected' : '' ?>>
                                <?= e($batch['batch_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="module_id" class="form-label">Module</label>
                    <select class="form-select" id="module_id" name="module_id">
                        <option value="">All my modules</option>
                        <?php foreach ($modules as $module): ?>
                            <option value="<?= e((string) $module['module_id']) ?>" <?= $moduleId === (int) $module['module_id'] ? 'selected' : '' ?>>
                                <?= e($module['module_code'] . ' – ' . $module['module_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <?php if ($isLecturerDirectory): ?>
                        <option value="ACTIVE" <?= $status === 'ACTIVE' || $status === '' ? 'selected' : '' ?>>Active</option>
                    <?php else: ?>
                        <option value="">All statuses</option>
                        <?php foreach (student_statuses() as $studentStatus): ?>
                            <option value="<?= e($studentStatus) ?>" <?= $status === $studentStatus ? 'selected' : '' ?>><?= e($studentStatus) ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-<?= $isLecturerDirectory ? '12' : '3' ?> d-flex align-items-end">
                <div class="app-filter-actions">
                    <button type="submit" class="btn btn-outline-primary">Filter</button>
                    <a href="<?= e(app_url($studentRoutePrefix . '/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
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
                    <th>Student</th>
                    <th>Registration No</th>
                    <th>Email</th>
                    <th>Course</th>
                    <th>Batch</th>
                    <?php if ($isLecturerDirectory): ?>
                        <th>Enrolled Modules</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th>Face</th>
                    <?php if (!$readOnly || $isLecturerDirectory): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $colspan = 7;
                if ($isLecturerDirectory) {
                    $colspan += 2;
                } elseif (!$readOnly) {
                    $colspan += 1;
                }
                ?>
                <?php if ($students === []): ?>
                    <tr>
                        <td colspan="<?= $colspan ?>" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No students found.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <?= render_student_profile_avatar($student, 'sm') ?>
                                    <span><?= e($student['first_name'] . ' ' . $student['last_name']) ?></span>
                                </div>
                            </td>
                            <td><?= e($student['registration_no']) ?></td>
                            <td><?= app_truncate_html((string) $student['email'], 'md') ?></td>
                            <td><?= e($student['course_code']) ?></td>
                            <td><?= e($student['batch_name']) ?></td>
                            <?php if ($isLecturerDirectory): ?>
                                <td><?= app_truncate_html((string) ($student['enrolled_modules'] ?? ''), 'md') ?></td>
                            <?php endif; ?>
                            <td><span class="badge <?= e(status_badge_class($student['status'])) ?>"><?= e($student['status']) ?></span></td>
                            <td><span class="badge <?= e(status_badge_class($student['face_status'])) ?>"><?= e($student['face_status']) ?></span></td>
                            <?php if ($isLecturerDirectory): ?>
                                <td class="text-end">
                                    <div class="app-actions">
                                        <a href="<?= e(app_url($studentRoutePrefix . '/view.php?id=' . $student['student_id'])) ?>" class="btn btn-sm btn-outline-primary">View</a>
                                    </div>
                                </td>
                            <?php elseif (!$readOnly): ?>
                                <td class="text-end">
                                    <div class="app-actions">
                                        <a href="<?= e(app_url($studentRoutePrefix . '/edit.php?id=' . $student['student_id'])) ?>" class="btn btn-sm btn-primary">Edit</a>
                                        <a href="<?= e(app_url(str_replace('/students', '/enrollments', $studentRoutePrefix) . '/student.php?id=' . $student['student_id'])) ?>" class="btn btn-sm btn-outline-secondary">Modules</a>
                                        <a href="<?= e(app_url($studentRoutePrefix . '/face-enroll.php?id=' . $student['student_id'])) ?>" class="btn btn-sm btn-outline-secondary">Face</a>
                                        <a href="<?= e(app_url($studentRoutePrefix . '/reset-password.php?id=' . $student['student_id'])) ?>" class="btn btn-sm btn-outline-warning">Reset Password</a>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
