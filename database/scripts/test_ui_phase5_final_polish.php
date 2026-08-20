<?php

declare(strict_types=1);

/**
 * UI Phase 5 smoke checks (login, calendars, auth layout, responsive hooks).
 *
 * Usage:
 *   php database/scripts/test_ui_phase5_final_polish.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

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
    'web/index.php',
    'web/login.php',
    'web/public/index.php',
    'web/shared/includes/header.php',
    'web/shared/includes/footer.php',
    'web/shared/pages/calendar/_render.php',
    'web/shared/pages/calendar/lecturer.php',
    'web/shared/pages/calendar/institution.php',
    'web/shared/pages/timetable/student.php',
    'web/public/assets/css/app.css',
];

foreach ($files as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    assert_true(is_file($path), 'File exists: ' . $relative);
    if (str_ends_with($relative, '.php')) {
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        assert_true($code === 0, 'Lint ' . $relative, implode("\n", $out));
        $out = [];
    }
}

$index = (string) file_get_contents($root . '/web/index.php');
assert_true(
    str_contains($index, 'is_authenticated()')
    && str_contains($index, "redirect('login.php')")
    && !str_contains($index, '<html'),
    'Root index remains redirect-only (no landing markup)'
);

$login = (string) file_get_contents($root . '/web/login.php');
assert_true(
    str_contains($login, 'method="post"')
    && str_contains($login, "app_url('login.php')")
    && str_contains($login, 'name="identifier"')
    && str_contains($login, 'name="password"')
    && str_contains($login, 'csrf_field()')
    && str_contains($login, 'attempt_login(')
    && str_contains($login, 'verify_csrf(')
    && str_contains($login, 'Invalid username or password.')
    && str_contains($login, 'toggle-password')
    && str_contains($login, 'autocomplete="username"')
    && str_contains($login, 'autocomplete="current-password"')
    && str_contains($login, 'type="button"')
    && str_contains($login, 'app-auth')
    && str_contains($login, 'Smart Student Management'),
    'Login preserves auth contract and auth layout branding'
);

$header = (string) file_get_contents($root . '/web/shared/includes/header.php');
assert_true(
    str_contains($header, '$authPage')
    && str_contains($header, 'app-auth-body')
    && str_contains($header, 'get_flash()'),
    'Header supports auth layout without breaking flash alerts'
);

$publicHome = (string) file_get_contents($root . '/web/public/index.php');
assert_true(
    str_contains($publicHome, 'is_authenticated()')
    && str_contains($publicHome, 'app-auth')
    && str_contains($publicHome, "app_url('login.php')"),
    'Public entry keeps auth redirect + sign-in CTA'
);

$calendar = (string) file_get_contents($root . '/web/shared/pages/calendar/_render.php');
assert_true(
    str_contains($calendar, 'app-cal-legend')
    && str_contains($calendar, 'calendar_session_status_class')
    && str_contains($calendar, 'app-cal-event-status')
    && !str_contains($calendar, 'Weekly Timetable'),
    'Calendar legend + status text present; no Weekly Timetable'
);

$css = (string) file_get_contents($root . '/web/public/assets/css/app.css');
assert_true(
    str_contains($css, '.lecturer-cal-event--scheduled')
    && str_contains($css, '.lecturer-cal-event--in-progress')
    && str_contains($css, '.lecturer-cal-event--completed')
    && str_contains($css, '.lecturer-cal-event--cancelled')
    && str_contains($css, '.app-auth')
    && str_contains($css, 'grid-template-columns: 1fr 1fr')
    && !preg_match('/\.app-auth\s*\{[^}]*max-width:\s*1160px/s', $css)
    && str_contains($css, 'app-auth-fade-up')
    && str_contains($css, 'app-auth-mark-float')
    && str_contains($css, '.app-cal-legend')
    && str_contains($css, 'prefers-reduced-motion')
    && str_contains($css, '@media (max-width: 575.98px)'),
    'Phase 5 CSS includes full-viewport auth 50/50, animations, calendar statuses, reduced motion, mobile rules'
);

$studentTimetable = (string) file_get_contents($root . '/web/shared/pages/timetable/student.php');
assert_true(
    str_contains($studentTimetable, "Today's Lectures")
    && str_contains($studentTimetable, 'list_visible_sessions_for_student')
    && !str_contains($studentTimetable, 'Weekly Timetable'),
    'Student timetable remains session-based (no weekly grid restore)'
);

$nav = (string) file_get_contents($root . '/web/shared/includes/management.php');
assert_true(
    str_contains($nav, 'Lecture Calendar')
    && str_contains($nav, 'My Calendar')
    && str_contains($nav, 'My Timetable'),
    'Role navigation still exposes calendars/timetable labels'
);

echo $failed ? 'RESULT: FAILED' . PHP_EOL : 'RESULT: PASSED' . PHP_EOL;
exit($failed ? 1 : 0);
