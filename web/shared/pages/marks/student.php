<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$student = current_student_profile();
if ($student === null) {
    set_flash('error', 'No student profile is linked to this account.');
    redirect('student/dashboard.php');
}

$studentId = (int) $student['student_id'];
positive_int($_GET['student_id'] ?? null); // ignored — results always come from the signed-in profile

$pageTitle = 'My Results';
$results = list_unified_student_results($studentId);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-4">
    Your graded Assignments and Presentations, and recorded Exam and Practical results.
    Legacy module marks are not shown here.
</p>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Recorded results</div>
                <div class="fs-4"><?= e((string) count($results)) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Module</th>
                    <th>Activity</th>
                    <th>Type</th>
                    <th>Result</th>
                    <th>Max marks</th>
                    <th>Feedback / Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($results === []): ?>
                    <tr>
                        <td colspan="6" class="p-0">
                            <div class="app-empty-state">
                                <p class="app-empty-state__title mb-0">No results recorded yet.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?= app_truncate_html($row['module_code'] . ' – ' . $row['module_name'], 'md') ?></td>
                            <td><?= app_truncate_html((string) $row['title'], 'md') ?></td>
                            <td>
                                <span class="badge <?= e(assignment_activity_badge_class((string) $row['activity_type'])) ?>">
                                    <?= e((string) $row['activity_label']) ?>
                                </span>
                            </td>
                            <td><?= e((string) $row['result_display']) ?></td>
                            <td><?= e((string) $row['max_marks']) ?></td>
                            <td>
                                <?= !empty($row['feedback_or_remarks'])
                                    ? nl2br(e((string) $row['feedback_or_remarks']))
                                    : '<span class="text-muted">—</span>' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
