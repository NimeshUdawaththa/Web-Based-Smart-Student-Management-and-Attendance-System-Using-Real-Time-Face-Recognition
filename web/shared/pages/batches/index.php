<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'academic-staff';

$pageTitle = 'Batches';
$courseId = positive_int($_GET['course_id'] ?? null);
$status = (string) ($_GET['status'] ?? '');

$batches = list_manage_batches(array_filter([
    'course_id' => $courseId,
    'status' => in_array($status, batch_statuses(), true) ? $status : null,
]));
$courses = list_courses();

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0">A batch is a student intake attached to one course.</p>
    <a href="<?= e(app_url($academicRoutePrefix . '/batches/create.php')) ?>" class="btn btn-primary">Add Batch</a>
</div>

<form method="get" class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label for="course_id" class="form-label">Course</label>
                <select class="form-select" id="course_id" name="course_id">
                    <option value="">All courses</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= e((string) $course['course_id']) ?>" <?= $courseId === (int) $course['course_id'] ? 'selected' : '' ?>>
                            <?= e(course_choice_label($course)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All statuses</option>
                    <?php foreach (batch_statuses() as $batchStatus): ?>
                        <option value="<?= e($batchStatus) ?>" <?= $status === $batchStatus ? 'selected' : '' ?>><?= e($batchStatus) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-outline-primary">Filter</button>
                <a href="<?= e(app_url($academicRoutePrefix . '/batches/index.php')) ?>" class="btn btn-outline-secondary">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Batch Name</th>
                    <th>Course</th>
                    <th>Intake Year</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Status</th>
                    <th>Students</th>
                    <th>Timetable</th>
                    <th>Sessions</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($batches === []): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No batches found.</td></tr>
                <?php else: ?>
                    <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><?= e($batch['batch_name']) ?></td>
                            <td><?= e($batch['course_code'] . ' - ' . $batch['course_name']) ?></td>
                            <td><?= e((string) $batch['intake_year']) ?></td>
                            <td><?= e((string) $batch['start_date']) ?></td>
                            <td><?= e((string) ($batch['end_date'] ?: '—')) ?></td>
                            <td><span class="badge <?= e(status_badge_class($batch['status'])) ?>"><?= e($batch['status']) ?></span></td>
                            <td><?= e((string) $batch['student_count']) ?></td>
                            <td><?= e((string) $batch['schedule_count']) ?></td>
                            <td><?= e((string) $batch['session_count']) ?></td>
                            <td class="text-end">
                                <a href="<?= e(app_url($academicRoutePrefix . '/batches/view.php?id=' . $batch['batch_id'])) ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                <a href="<?= e(app_url($academicRoutePrefix . '/batches/edit.php?id=' . $batch['batch_id'])) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
