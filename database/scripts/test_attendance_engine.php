<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — teaching-time formula (no attendance_records writes).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_attendance_engine.php
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

$session = [
    'session_id' => 0,
    'session_date' => '2026-08-19',
    'scheduled_start' => '09:00:00',
    'scheduled_end' => '12:00:00',
    'break_start' => '10:30:00',
    'break_end' => '10:45:00',
    'late_after_minutes' => 15,
];

$now = new DateTimeImmutable('2026-08-19 12:00:00', new DateTimeZone(APP_TIMEZONE));

$eventsBreakOk = [
    ['event_type' => 'IN', 'recognized_at' => '2026-08-19 09:00:00'],
    ['event_type' => 'OUT', 'recognized_at' => '2026-08-19 10:30:00'],
    ['event_type' => 'IN', 'recognized_at' => '2026-08-19 10:44:00'],
    ['event_type' => 'OUT', 'recognized_at' => '2026-08-19 12:00:00'],
];
$previewOk = calculate_session_attendance_preview($session, 3, $eventsBreakOk, $now);

$eventsLateReturn = [
    ['event_type' => 'IN', 'recognized_at' => '2026-08-19 09:00:00'],
    ['event_type' => 'OUT', 'recognized_at' => '2026-08-19 10:30:00'],
    ['event_type' => 'IN', 'recognized_at' => '2026-08-19 11:10:00'],
    ['event_type' => 'OUT', 'recognized_at' => '2026-08-19 12:00:00'],
];
$previewLate = calculate_session_attendance_preview($session, 3, $eventsLateReturn, $now);

echo 'timezone=' . APP_TIMEZONE . PHP_EOL;
echo 'lecture_minutes=' . $previewOk['lecture_minutes'] . PHP_EOL;
echo 'break_minutes=' . $previewOk['official_break_minutes'] . PHP_EOL;
echo 'teaching_minutes=' . $previewOk['teaching_minutes'] . PHP_EOL;
echo 'test_j_attended=' . $previewOk['attended_teaching_minutes'] . PHP_EOL;
echo 'test_j_missed=' . $previewOk['missed_teaching_minutes'] . PHP_EOL;
echo 'test_j_left_early_preview=' . ($previewOk['preview_left_early_candidate'] ? '1' : '0') . PHP_EOL;
echo 'test_k_attended=' . $previewLate['attended_teaching_minutes'] . PHP_EOL;
echo 'test_k_missed=' . $previewLate['missed_teaching_minutes'] . PHP_EOL;
echo 'final_status_applied=' . ($previewOk['final_status_applied'] ? '1' : '0') . PHP_EOL;

$failed = false;
if ((int) $previewOk['teaching_minutes'] !== 165) {
    fwrite(STDERR, "expected teaching 165\n");
    $failed = true;
}
if ((int) $previewOk['missed_teaching_minutes'] !== 0) {
    fwrite(STDERR, "Test J expected missed 0, got {$previewOk['missed_teaching_minutes']}\n");
    $failed = true;
}
if ((int) $previewLate['missed_teaching_minutes'] !== 25) {
    fwrite(STDERR, "Test K expected missed 25, got {$previewLate['missed_teaching_minutes']}\n");
    $failed = true;
}

exit($failed ? 1 : 0);
