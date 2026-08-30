<?php

declare(strict_types=1);

/**
 * UI Phase 4 smoke checks (student/face/camera/session/attendance presentation).
 *
 * Usage:
 *   php database/scripts/test_ui_phase4_ops_flow.php
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

$failed = false;
$root = dirname(__DIR__, 2);

function pass(string $label): void
{
    echo 'PASS ' . $label . PHP_EOL;
}

function fail(string $label, string $detail = ''): void
{
    global $failed;
    $failed = true;
    echo 'FAIL ' . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
}

function assert_true(bool $ok, string $label, string $detail = ''): void
{
    if ($ok) {
        pass($label);
    } else {
        fail($label, $detail);
    }
}

$files = [
    'web/shared/pages/students/index.php',
    'web/shared/pages/students/edit.php',
    'web/shared/pages/students/face-enroll.php',
    'web/shared/pages/camera/index.php',
    'web/shared/pages/sessions/view.php',
    'web/shared/pages/attendance/reports.php',
    'web/shared/pages/attendance/detail.php',
    'web/shared/pages/attendance/student.php',
    'web/shared/includes/management.php',
    'web/shared/includes/academic.php',
];

foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    assert_true($code === 0, 'Lint ' . $rel, implode("\n", $out));
}

$studentsIndex = (string) file_get_contents($root . '/web/shared/pages/students/index.php');
assert_true(
    str_contains($studentsIndex, 'render_student_profile_avatar')
    && str_contains($studentsIndex, '>Edit</a>')
    && str_contains($studentsIndex, '>Modules</a>')
    && str_contains($studentsIndex, '>Face</a>')
    && str_contains($studentsIndex, 'Reset Password'),
    'Student list avatar + actions'
);

$edit = (string) file_get_contents($root . '/web/shared/pages/students/edit.php');
assert_true(
    str_contains($edit, 'app-student-identity')
    && str_contains($edit, 'Academic &amp; account actions')
    && str_contains($edit, 'lifecycle_action')
    && str_contains($edit, 'deactivate'),
    'Student edit hierarchy + lifecycle preserved'
);

$face = (string) file_get_contents($root . '/web/shared/pages/students/face-enroll.php');
assert_true(
    str_contains($face, 'app-face-hero')
    && str_contains($face, 'app-face-card')
    && str_contains($face, 'Temporary face samples are used only to generate the biometric encoding')
    && str_contains($face, 'btn-retry-service')
    && str_contains($face, '/api/enrollment/start')
    && str_contains($face, '/api/enrollment/preview')
    && str_contains($face, 'Camera preview will appear when enrollment starts.')
    && str_contains($face, 'Live preview unavailable. Enrollment service is still running.')
    && str_contains($face, 'Keep your face within the guide and turn only slightly.')
    && str_contains($face, 'Re-enroll Face')
    && str_contains($face, 'confirm_reenroll')
    && str_contains($face, 'Start Face Enrollment')
    && !str_contains($face, 'alert-info app-face-capture__tips')
    && !str_contains($face, 'encrypt'),
    'Face enrollment hero + re-enroll + privacy + live preview hooks'
);

$camera = (string) file_get_contents($root . '/web/shared/pages/camera/index.php');
assert_true(
    str_contains($camera, 'app-camera-hero')
    && str_contains($camera, 'camera-status-badge')
    && str_contains($camera, 'ENTRY')
    && str_contains($camera, 'EXIT')
    && str_contains($camera, 'app-diag-details')
    && str_contains($camera, "action\" value=\"start\""),
    'Camera management demo layout'
);

$session = (string) file_get_contents($root . '/web/shared/pages/sessions/view.php');
assert_true(
    str_contains($session, 'app-workflow-steps')
    && str_contains($session, 'app-session-summary-grid')
    && str_contains($session, 'presentCount')
    && str_contains($session, 'Final OUT')
    && !str_contains($session, '10-minute')
    && !str_contains($session, '10 minute')
    && str_contains($session, 'start_lecture_session')
    && str_contains($session, 'complete_lecture_session'),
    'Session view workflow + Final OUT copy; logic hooks intact'
);

$reports = (string) file_get_contents($root . '/web/shared/pages/attendance/reports.php');
assert_true(
    str_contains($reports, 'Unique Students')
    && str_contains($reports, 'summarize_attendance_report')
    && !str_contains($reports, 'Eligible Students'),
    'Attendance reports Unique Students label'
);

$detail = (string) file_get_contents($root . '/web/shared/pages/attendance/detail.php');
assert_true(
    str_contains($detail, 'physical')
    || str_contains($detail, 'teaching time'),
    'Audit detail explanatory copy'
);

$studentAtt = (string) file_get_contents($root . '/web/shared/pages/attendance/student.php');
assert_true(
    str_contains($studentAtt, 'metric-card')
    && str_contains($studentAtt, 'app-empty-state'),
    'Student My Attendance polish'
);

$mgmt = (string) file_get_contents($root . '/web/shared/includes/management.php');
assert_true(str_contains($mgmt, 's.profile_photo'), 'list_students selects profile_photo');

echo ($failed ? 'RESULT: FAILED' : 'RESULT: PASSED') . PHP_EOL;
exit($failed ? 1 : 0);
