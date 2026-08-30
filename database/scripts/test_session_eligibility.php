<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — test is_student_eligible_for_session() independently.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_session_eligibility.php <student_id> <session_id>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/auth.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';

$studentId = isset($argv[1]) ? (int) $argv[1] : 0;
$sessionId = isset($argv[2]) ? (int) $argv[2] : 0;

if ($studentId <= 0 || $sessionId <= 0) {
    fwrite(STDERR, "Usage: php database/scripts/test_session_eligibility.php <student_id> <session_id>\n");
    exit(1);
}

$student = get_student($studentId);
$session = get_lecture_session($sessionId);
$eligible = is_student_eligible_for_session($studentId, $sessionId);

echo "Student ID: {$studentId}\n";
if ($student === null) {
    echo "Student: not found\n";
} else {
    echo 'Student: ' . $student['first_name'] . ' ' . $student['last_name']
        . ' (' . $student['registration_no'] . '), status=' . $student['status']
        . ', batch_id=' . $student['batch_id'] . "\n";
}

echo "Session ID: {$sessionId}\n";
if ($session === null) {
    echo "Session: not found\n";
} else {
    echo 'Session: ' . $session['module_code'] . ' / ' . $session['batch_name']
        . ' on ' . $session['session_date']
        . ', status=' . $session['status'] . "\n";
}

echo 'Eligible: ' . ($eligible ? 'YES' : 'NO') . "\n";

if ($session !== null) {
    $list = list_eligible_students_for_session($sessionId);
    echo 'Eligible student count: ' . count($list) . "\n";
}

exit($eligible ? 0 : 2);
