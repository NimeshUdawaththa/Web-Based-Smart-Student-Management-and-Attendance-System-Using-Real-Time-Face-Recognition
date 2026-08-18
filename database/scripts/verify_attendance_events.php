<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — list recent attendance_events (IN/OUT raw events).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/verify_attendance_events.php
 *   C:\xampp\php\php.exe database/scripts/verify_attendance_events.php <student_id>
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';

$studentId = isset($argv[1]) ? (int) $argv[1] : 0;

$sql = "SELECT e.event_id, e.student_id, s.registration_no,
               CONCAT(s.first_name, ' ', s.last_name) AS student_name,
               e.session_id, m.module_code, e.event_type, e.recognized_at,
               e.confidence, e.camera_id, e.created_at
        FROM attendance_events e
        INNER JOIN students s ON s.student_id = e.student_id
        INNER JOIN lecture_sessions ls ON ls.session_id = e.session_id
        INNER JOIN modules m ON m.module_id = ls.module_id";
$params = [];
if ($studentId > 0) {
    $sql .= ' WHERE e.student_id = :student_id';
    $params['student_id'] = $studentId;
}
$sql .= ' ORDER BY e.event_id DESC LIMIT 20';

$statement = db()->prepare($sql);
$statement->execute($params);
$rows = $statement->fetchAll();

if ($rows === []) {
    echo "No attendance events found.\n";
    exit(0);
}

foreach ($rows as $row) {
    echo sprintf(
        "event_id=%s student=%s (%s) session_id=%s module=%s type=%s recognized_at=%s confidence=%s camera=%s\n",
        $row['event_id'],
        $row['registration_no'],
        $row['student_name'],
        $row['session_id'],
        $row['module_code'],
        $row['event_type'],
        $row['recognized_at'],
        $row['confidence'],
        $row['camera_id'] ?? '-'
    );
}
