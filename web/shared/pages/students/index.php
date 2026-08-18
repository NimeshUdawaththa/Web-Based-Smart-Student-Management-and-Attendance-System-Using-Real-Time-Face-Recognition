<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $studentRoutePrefix e.g. admin/students or academic-staff/students */
$studentRoutePrefix = $studentRoutePrefix ?? 'admin/students';
$readOnly = $readOnly ?? false;

$pageTitle = 'Student Management';
$search = trim((string) ($_GET['search'] ?? ''));
$courseId = positive_int($_GET['course_id'] ?? null);
$batchId = positive_int($_GET['batch_id'] ?? null);
$status = (string) ($_GET['status'] ?? '');

$filters = array_filter([
    'search' => $search,
    'course_id' => $courseId,
    'batch_id' => $batchId,
    'status' => in_array($status, student_statuses(), true) ? $status : null,
]);

$students = list_students($filters);
$courses = list_active_courses();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">View and manage student records.</p>
    <?php if (!$readOnly): ?>
        <a href="<?= e(app_url($studentRoutePrefix . '/register.php')) ?>" class="btn btn-primary">Register Student</a>
    <?php endif; ?>
</div>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
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
            <div class="col-md-2">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (student_statuses() as $studentStatus): ?>
                        <option value="<?= e($studentStatus) ?>" <?= $status === $studentStatus ? 'selected' : '' ?>><?= e($studentStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-outline-primary">Filter</button>
                <a href="<?= e(app_url($studentRoutePrefix . '/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Registration No</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Course</th>
                    <th>Batch</th>
                    <th>Status</th>
                    <th>Face</th>
                    <?php if (!$readOnly): ?>
                        <th class="text-end">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($students === []): ?>
                    <tr>
                        <td colspan="<?= $readOnly ? 7 : 8 ?>" class="text-center text-muted py-4">No students found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td><?= e($student['registration_no']) ?></td>
                            <td><?= e($student['first_name'] . ' ' . $student['last_name']) ?></td>
                            <td><?= e($student['email']) ?></td>
                            <td><?= e($student['course_code']) ?></td>
                            <td><?= e($student['batch_name']) ?></td>
                            <td><span class="badge <?= e(status_badge_class($student['status'])) ?>"><?= e($student['status']) ?></span></td>
                            <td><span class="badge <?= e(status_badge_class($student['face_status'])) ?>"><?= e($student['face_status']) ?></span></td>
                            <?php if (!$readOnly): ?>
                                <td class="text-end">
                                    <a href="<?= e(app_url($studentRoutePrefix . '/edit.php?id=' . $student['student_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <a href="<?= e(app_url(str_replace('/students', '/enrollments', $studentRoutePrefix) . '/student.php?id=' . $student['student_id'])) ?>" class="btn btn-sm btn-outline-secondary">Modules</a>
                                    <a href="<?= e(app_url($studentRoutePrefix . '/face-enroll.php?id=' . $student['student_id'])) ?>" class="btn btn-sm btn-outline-secondary">Enroll Face</a>
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
