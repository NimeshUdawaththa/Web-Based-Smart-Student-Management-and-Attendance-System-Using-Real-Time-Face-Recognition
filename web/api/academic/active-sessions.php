<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/shared/includes/init.php';

require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$scopeLecturerId = null;
if ($user['role'] === 'LECTURER') {
    $lecturer = current_lecturer_profile();
    if ($lecturer === null) {
        http_response_code(403);
        echo json_encode(['error' => 'No lecturer profile is linked to this account']);
        exit;
    }
    $scopeLecturerId = (int) $lecturer['lecturer_id'];
} elseif ($user['role'] === 'STUDENT') {
    $student = current_student_profile();
    if ($student === null) {
        http_response_code(403);
        echo json_encode(['error' => 'No student profile is linked to this account']);
        exit;
    }
    $sessions = get_eligible_in_progress_sessions_for_student((int) $student['student_id']);
    echo json_encode([
        'active_sessions' => array_map(static function (array $session): array {
            return [
                'session_id' => (int) $session['session_id'],
                'module_code' => $session['module_code'],
                'module_name' => $session['module_name'],
                'batch_name' => $session['batch_name'],
                'status' => $session['status'],
                'actual_start' => $session['actual_start'],
            ];
        }, $sessions),
        'future_flow' => 'Recognized face → Student ID → Active session → Eligibility check → Future IN/OUT event',
    ]);
    exit;
} elseif (!in_array($user['role'], ['ADMIN', 'ACADEMIC_STAFF', 'LECTURER'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$sessions = get_in_progress_sessions($scopeLecturerId);
$payload = [];
foreach ($sessions as $session) {
    $eligible = list_eligible_students_for_session((int) $session['session_id']);
    $payload[] = [
        'session_id' => (int) $session['session_id'],
        'module_code' => $session['module_code'],
        'module_name' => $session['module_name'],
        'batch_name' => $session['batch_name'],
        'lecturer' => $session['lecturer_first_name'] . ' ' . $session['lecturer_last_name'],
        'room' => $session['room'],
        'status' => $session['status'],
        'actual_start' => $session['actual_start'],
        'late_after_minutes' => (int) $session['late_after_minutes'],
        'eligible_student_count' => count($eligible),
        'eligible_student_ids' => array_map(static fn (array $row): int => (int) $row['student_id'], $eligible),
    ];
}

echo json_encode([
    'active_sessions' => $payload,
    'future_flow' => 'Recognized face → Student ID → Active session → Eligibility check → Future IN/OUT event',
]);
