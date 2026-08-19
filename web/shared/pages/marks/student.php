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
$courseworkRows = list_graded_coursework_results_for_student($studentId);
$marksRows = list_marks_for_student($studentId);
$marksSummary = summarize_student_marks($studentId);

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<p class="text-muted mb-3">Your own coursework grades and module assessment marks. These are stored separately and are not combined into a final module grade.</p>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Coursework Graded</div>
                <div class="fs-4"><?= e((string) count($courseworkRows)) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Module Assessments Recorded</div>
                <div class="fs-4"><?= e((string) $marksSummary['count']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Module Assessment Average</div>
                <div class="fs-4"><?= $marksSummary['average_percentage'] === null ? '—' : e(format_marks_percentage($marksSummary['average_percentage'])) ?></div>
                <div class="small text-muted">Average of module assessment percentages from the marks table only. Not a GPA or combined result.</div>
            </div>
        </div>
    </div>
</div>

<h2 class="h5 mb-3">Coursework Results</h2>
<div class="card shadow-sm mb-4">
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Coursework</th>
                    <th>Grade</th>
                    <th>Percentage</th>
                    <th>Feedback</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($courseworkRows === []): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No graded coursework results yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($courseworkRows as $row): ?>
                        <tr>
                            <td><?= e($row['module_code'] . ' – ' . $row['module_name']) ?></td>
                            <td><?= e((string) $row['title']) ?></td>
                            <td><?= e((string) $row['grade_display']) ?></td>
                            <td><?= $row['percentage'] === null ? '—' : e(format_marks_percentage((float) $row['percentage'])) ?></td>
                            <td><?= !empty($row['feedback']) ? nl2br(e((string) $row['feedback'])) : '<span class="text-muted">No feedback.</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<h2 class="h5 mb-3">Module Assessment Results</h2>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-striped mb-0 align-middle">
            <thead>
                <tr>
                    <th>Module</th>
                    <th>Type</th>
                    <th>Assessment</th>
                    <th>Marks</th>
                    <th>Percentage</th>
                    <th>Remarks</th>
                    <th>Recorded</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($marksRows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No assessment results recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($marksRows as $row): ?>
                        <tr>
                            <td><?= e($row['module_code'] . ' – ' . $row['module_name']) ?></td>
                            <td><?= e((string) $row['assessment_type']) ?></td>
                            <td><?= e((string) $row['assessment_name']) ?></td>
                            <td><?= e(format_marks_pair($row['marks_obtained'], $row['max_marks'])) ?></td>
                            <td><?= e(format_marks_percentage($row['percentage'])) ?></td>
                            <td><?= e((string) ($row['remarks'] ?? '')) ?></td>
                            <td><?= e(format_assignment_datetime((string) $row['recorded_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
