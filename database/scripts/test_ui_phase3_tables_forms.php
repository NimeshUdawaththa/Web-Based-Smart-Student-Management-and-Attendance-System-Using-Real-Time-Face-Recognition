<?php

declare(strict_types=1);

/**
 * UI Phase 3 smoke checks (tables/filters/forms presentation).
 *
 * Usage:
 *   php database/scripts/test_ui_phase3_tables_forms.php
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
    'web/shared/pages/students/register.php',
    'web/shared/pages/students/edit.php',
    'web/shared/pages/sessions/create.php',
    'web/admin/users/index.php',
    'web/admin/users/create.php',
    'web/shared/pages/courses/form.php',
    'web/shared/pages/assignments/form.php',
    'web/shared/pages/announcements/form.php',
    'web/shared/pages/campus-events/form.php',
    'web/shared/pages/profile/index.php',
    'web/shared/pages/marks/student.php',
    'web/shared/pages/assignments/student-index.php',
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
    str_contains($studentsIndex, 'app-filter-card')
    && str_contains($studentsIndex, 'app-actions')
    && str_contains($studentsIndex, 'Reset Password')
    && str_contains($studentsIndex, '>Modules</a>')
    && str_contains($studentsIndex, 'app-empty-state'),
    'Student list has filter/actions/empty polish'
);

$register = (string) file_get_contents($root . '/web/shared/pages/students/register.php');
assert_true(
    str_contains($register, 'app-form-section')
    && str_contains($register, 'Academic Placement')
    && str_contains($register, 'app-required')
    && str_contains($register, 'app-required-note'),
    'Student register form sections + required markers'
);

$sessionCreate = (string) file_get_contents($root . '/web/shared/pages/sessions/create.php');
assert_true(
    str_contains($sessionCreate, 'Session Details')
    && str_contains($sessionCreate, 'Create Session')
    && str_contains($sessionCreate, 'app-form-actions'),
    'Create Session form hierarchy'
);

$edit = (string) file_get_contents($root . '/web/shared/pages/students/edit.php');
assert_true(
    str_contains($edit, 'lifecycle_action')
    && str_contains($edit, 'deactivate')
    && str_contains($edit, 'app-form-section'),
    'Student edit keeps lifecycle + gains sections'
);

assert_true(
    function_exists('app_truncate_html')
    && str_contains(app_truncate_html('hello@example.com', 'md'), 'title="hello@example.com"'),
    'app_truncate_html helper works'
);

assert_true(
    !str_contains($studentsIndex, 'list_students(') === false,
    'Student list still uses list_students (logic intact)'
);
assert_true(str_contains($studentsIndex, 'list_students'), 'Student list query helper still present');

echo ($failed ? 'RESULT: FAILED' : 'RESULT: PASSED') . PHP_EOL;
exit($failed ? 1 : 0);
