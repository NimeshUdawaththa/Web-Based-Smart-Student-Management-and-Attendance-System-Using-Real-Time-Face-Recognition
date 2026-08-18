<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — resolve eligible IN_PROGRESS session without inserting.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_check_in_resolution.php <student_id>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';

$studentId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($studentId <= 0) {
    fwrite(STDERR, "Usage: php database/scripts/test_check_in_resolution.php <student_id>\n");
    exit(1);
}

$resolution = resolve_attendance_session_for_student($studentId);
echo 'result=' . ($resolution['result'] ?? '') . PHP_EOL;
if (isset($resolution['session']['session_id'])) {
    echo 'session_id=' . $resolution['session']['session_id'] . PHP_EOL;
    echo 'module_code=' . ($resolution['session']['module_code'] ?? '') . PHP_EOL;
    echo 'batch_name=' . ($resolution['session']['batch_name'] ?? '') . PHP_EOL;
    $threshold = session_late_threshold_datetime($resolution['session']);
    if ($threshold instanceof DateTimeImmutable) {
        echo 'late_threshold=' . $threshold->format('Y-m-d H:i') . ' (not applied yet)' . PHP_EOL;
    }
}
if (isset($resolution['session_ids'])) {
    echo 'session_ids=' . implode(',', $resolution['session_ids']) . PHP_EOL;
}
