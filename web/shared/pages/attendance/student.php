<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

require_role('STUDENT');

$pageTitle = 'My Attendance';
$student = current_student_profile();
$records = $student !== null ? list_student_attendance_records((int) $student['student_id']) : [];

require INCLUDES_PATH . '/dashboard-layout-start.php';
?>

<div class="card shadow-sm">
    <div class="card-body">
        <h1 class="h4 mb-3">My Attendance</h1>
        <?php if ($student === null): ?>
            <div class="alert alert-warning mb-0">No student profile is linked to this login.</div>
        <?php else: ?>
            <p class="text-muted">Final results for lectures you were eligible to attend. Other students’ records are not shown.</p>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Module</th>
                            <th>Date</th>
                            <th>Attendance %</th>
                            <th>Status</th>
                            <th>Left Early</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($records === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No finalized attendance yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <td><?= e($record['module_code'] . ' · ' . $record['module_name']) ?></td>
                                    <td><?= e((string) $record['session_date']) ?></td>
                                    <td><?= e(number_format((float) $record['attendance_percent'], 2)) ?>%</td>
                                    <td><span class="badge <?= e(status_badge_class((string) $record['status'])) ?>"><?= e((string) $record['status']) ?></span></td>
                                    <td><?= (int) $record['left_early'] === 1 ? 'Yes' : 'No' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require INCLUDES_PATH . '/dashboard-layout-end.php'; ?>
