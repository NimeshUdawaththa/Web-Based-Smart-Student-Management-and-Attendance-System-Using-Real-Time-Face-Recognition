<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'lecturer';
/** @var bool $courseworkCanEdit */
$courseworkCanEdit = $courseworkCanEdit ?? false;
/** @var int|null $restrictLecturerId */
$restrictLecturerId = $restrictLecturerId ?? null;

$assignmentId = positive_int($_GET['id'] ?? null);
$assignment = $assignmentId !== null ? get_coursework_assignment($assignmentId) : null;

if ($assignment === null) {
    set_flash('error', 'Activity not found.');
    redirect($academicRoutePrefix . '/assignments/index.php');
}

if ($courseworkCanEdit) {
    if ($restrictLecturerId === null || !lecturer_can_manage_coursework_assignment($restrictLecturerId, $assignment)) {
        deny_access();
    }
} elseif (!staff_can_monitor_coursework()) {
    deny_access();
}

if (assignment_requires_submission($assignment)) {
    set_flash('error', 'Results are only available for Exam and Practical activities. Use Submissions for this activity.');
    redirect($academicRoutePrefix . '/assignments/submissions.php?id=' . $assignment['assignment_id']);
}

$typeLabel = assignment_activity_type_label($assignment);
$coverage = count_assignment_direct_result_coverage((int) $assignment['assignment_id']);
$rows = list_assignment_direct_results_roster((int) $assignment['assignment_id']);
$pageTitle = 'Results — ' . (string) $assignment['title'];

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="mb-3">
    <a href="<?= e(app_url($academicRoutePrefix . '/assignments/view.php?id=' . $assignment['assignment_id'])) ?>">&larr; Activity details</a>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6 col-xl-3">
                <div class="text-muted small">Module</div>
                <div class="fw-semibold"><?= e((string) $assignment['module_code']) ?></div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="text-muted small">Type</div>
                <div><span class="badge <?= e(assignment_activity_badge_class($assignment)) ?>"><?= e($typeLabel) ?></span></div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="text-muted small">Schedule</div>
                <div class="fw-semibold"><?= e(format_assignment_schedule_summary($assignment)) ?></div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="text-muted small">Maximum Marks</div>
                <div class="fw-semibold"><?= e((string) $assignment['max_marks']) ?></div>
            </div>
            <div class="col-12">
                <div class="text-muted small">Recorded</div>
                <div class="fw-semibold"><?= (int) $coverage['recorded'] ?> / <?= (int) $coverage['enrolled'] ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Registration No</th>
                    <th>Student</th>
                    <th>Result</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="5" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No enrolled students were found for this module.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e((string) $row['registration_no']) ?></td>
                            <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                            <td>
                                <?= $row['marks_obtained'] !== null
                                    ? e(format_assignment_grade_display($row['marks_obtained'], $row['max_marks']))
                                    : '—' ?>
                            </td>
                            <td>
                                <span class="badge <?= e(status_badge_class((string) $row['status'])) ?>"><?= e((string) $row['status']) ?></span>
                            </td>
                            <td class="text-end">
                                <?php if ($courseworkCanEdit): ?>
                                    <a class="btn btn-sm btn-primary" href="<?= e(app_url($academicRoutePrefix . '/assignments/result-entry.php?id=' . $assignment['assignment_id'] . '&student_id=' . $row['student_id'])) ?>">
                                        <?= $row['result_id'] ? 'Edit' : 'Enter Mark' ?>
                                    </a>
                                <?php else: ?>
                                    <?php if ($row['result_id']): ?>
                                        <span class="text-muted small"><?= e((string) ($row['remarks'] ?? '')) ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
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
