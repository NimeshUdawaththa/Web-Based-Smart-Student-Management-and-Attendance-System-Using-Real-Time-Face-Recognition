<?php

declare(strict_types=1);

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/auth.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';

$failed = false;

function assert_true(bool $ok, string $label): void
{
    global $failed;
    if ($ok) {
        echo "PASS $label\n";
    } else {
        $failed = true;
        echo "FAIL $label\n";
    }
}

foreach (['ADMIN', 'ACADEMIC_STAFF', 'LECTURER', 'STUDENT'] as $role) {
    $labels = array_column(management_nav_items($role), 'label');
    assert_true($labels !== [], "$role nav non-empty");
    assert_true(!in_array('Timetable Management', $labels, true), "$role has no Timetable Management");
}

$admin = array_column(management_nav_items('ADMIN'), 'label');
$staff = array_column(management_nav_items('ACADEMIC_STAFF'), 'label');
$lecturer = array_column(management_nav_items('LECTURER'), 'label');
$student = array_column(management_nav_items('STUDENT'), 'label');

assert_true(in_array('User Management', $admin, true) && !in_array('User Management', $staff, true), 'User Management admin-only');
assert_true(in_array('Module Catalogue', $admin, true) && in_array('Module Catalogue', $staff, true), 'Module Catalogue present');
assert_true(in_array('My Calendar', $lecturer, true) && !in_array('User Management', $lecturer, true), 'Lecturer scoped');
assert_true(in_array('My Timetable', $student, true) && !in_array('Camera Management', $student, true), 'Student scoped');
assert_true(count_active_students() >= 0, 'count_active_students readable');

$studentDash = (string) file_get_contents(dirname(__DIR__, 2) . '/web/student/dashboard.php');
assert_true(
    !str_contains($studentDash, 'Weekly Timetable')
    && !str_contains($studentDash, 'Weekly slots')
    && !str_contains($studentDash, 'list_student_schedules'),
    'Student dashboard has no weekly/legacy schedule UI'
);

$staffDash = (string) file_get_contents(dirname(__DIR__, 2) . '/web/academic-staff/dashboard.php');
assert_true(
    !str_contains($staffDash, 'follow the timetable automatically')
    && !str_contains($staffDash, 'Generate This Week')
    && !str_contains($staffDash, 'weekly generation'),
    'Staff dashboard has no legacy timetable-generation copy'
);

$layout = (string) file_get_contents(dirname(__DIR__, 2) . '/web/shared/includes/dashboard-layout-start.php');
assert_true(
    str_contains($layout, 'bootstrap-icons')
    && str_contains($layout, 'offcanvas')
    && str_contains($layout, 'aria-label="Open navigation menu"'),
    'Layout has icons + accessible mobile menu'
);

assert_true(
    nav_item_is_active('/admin/students/edit.php', '/admin/students/index.php')
    && !nav_item_is_active('/admin/sessions/index.php', '/admin/calendar/index.php'),
    'Active nav matching improved safely'
);

echo ($failed ? "RESULT: FAILED\n" : "RESULT: PASSED\n");
exit($failed ? 1 : 0);
