<?php

declare(strict_types=1);

/**
 * Course → Module management discoverability regression.
 *
 * Run:
 *   C:\xampp\php\php.exe database/scripts/test_course_modules_discoverability.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/web/shared/includes/init.php';

$failed = 0;

function pass(string $label): void
{
    echo "PASS {$label}\n";
}

function fail(string $label): void
{
    global $failed;
    $failed++;
    echo "FAIL {$label}\n";
}

$form = (string) file_get_contents($root . '/web/shared/pages/courses/form.php');
$view = (string) file_get_contents($root . '/web/shared/pages/courses/view.php');
$index = (string) file_get_contents($root . '/web/shared/pages/courses/index.php');
$modules = (string) file_get_contents($root . '/web/shared/pages/courses/modules.php');

// A/B Create Course redirects to Course View (optional modules on create).
if (
    str_contains($form, 'create_course_with_modules')
    && str_contains($form, "courses/view.php?id=")
    && (str_contains($form, 'You can assign modules from Manage Modules')
        || str_contains($form, 'Course created successfully with '))
    && !preg_match('/create_course_with_modules[\s\S]*redirect\(\$academicRoutePrefix \. \'\/courses\/modules\.php/s', $form)
) {
    pass('A Create Course works (create_course retained)');
    pass('B Create redirects to Course View');
} else {
    fail('A Create Course works (create_course retained)');
    fail('B Create redirects to Course View');
}

// C Manage Modules prominent on Course View.
if (
    str_contains($view, 'Manage Modules')
    && str_contains($view, 'btn-primary btn-sm">Manage Modules')
    && str_contains($view, 'courses/modules.php?id=')
) {
    pass('C Manage Modules is clearly visible after create (Course View)');
} else {
    fail('C Manage Modules is clearly visible after create (Course View)');
}

// D Edit Course shows Manage Modules; module checkbox grid is create-only.
if (
    str_contains($form, 'Manage Modules')
    && str_contains($form, 'courses/modules.php?id=')
    && str_contains($form, 'if (!$isEdit):')
    && str_contains($form, 'module_ids[]')
    && !str_contains($form, 'save_course_module_selection')
) {
    pass('D Edit Course shows Manage Modules');
} else {
    fail('D Edit Course shows Manage Modules');
}

// E Existing Course View Manage Modules still present.
if (substr_count($view, 'Manage Modules') >= 2 && str_contains($view, 'Modules (')) {
    pass('E Existing Course View Manage Modules still works');
} else {
    fail('E Existing Course View Manage Modules still works');
}

// List action.
if (str_contains($index, '>Modules</a>') && str_contains($index, 'courses/modules.php?id=')) {
    pass('List Catalogue has Modules action');
} else {
    fail('List Catalogue has Modules action');
}

$suffix = strtoupper(bin2hex(random_bytes(3)));
$cleanup = ['course_ids' => [], 'module_ids' => []];

try {
    // F Module assignment still works via existing helpers.
    $courseId = create_course([
        'course_code' => 'DSC' . $suffix,
        'course_name' => 'Discoverability Course ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ]);
    $cleanup['course_ids'][] = $courseId;

    $moduleId = create_module([
        'module_code' => 'DSM' . $suffix,
        'module_name' => 'Discoverability Module ' . $suffix,
        'credits' => 15,
        'semester' => 1,
        'status' => 'ACTIVE',
    ]);
    $cleanup['module_ids'][] = $moduleId;

    save_course_module_selection($courseId, [$moduleId]);
    $links = list_active_course_modules($courseId);
    if (count($links) === 1 && (int) $links[0]['module_id'] === $moduleId) {
        pass('F Module assignment works');
    } else {
        fail('F Module assignment works');
    }

    // G Batch Module flow helpers unchanged (function still available; no course UI rewrite).
    if (
        function_exists('save_batch_module_selection')
        && function_exists('list_active_course_modules')
        && str_contains($modules, 'save_course_module_selection')
    ) {
        pass('G Batch Module flow remains unchanged');
    } else {
        fail('G Batch Module flow remains unchanged');
    }

    // Permissions: modules routes still gated.
    $adminModules = (string) file_get_contents($root . '/web/admin/courses/modules.php');
    $staffModules = (string) file_get_contents($root . '/web/academic-staff/courses/modules.php');
    $lecturerModules = is_file($root . '/web/lecturer/courses/modules.php');
    if (
        str_contains($adminModules, 'require_admin()')
        && str_contains($staffModules, 'require_student_manager()')
        && !$lecturerModules
    ) {
        pass('Permissions Admin/Academic Staff only');
    } else {
        fail('Permissions Admin/Academic Staff only');
    }

    // H No schema change (script does not CREATE TABLE / ALTER).
    $changed = [
        $root . '/web/shared/pages/courses/form.php',
        $root . '/web/shared/pages/courses/view.php',
        $root . '/web/shared/pages/courses/index.php',
    ];
    $schemaTouched = false;
    foreach ($changed as $path) {
        $src = (string) file_get_contents($path);
        if (preg_match('/\b(CREATE TABLE|ALTER TABLE|DROP TABLE)\b/i', $src)) {
            $schemaTouched = true;
        }
    }
    !$schemaTouched ? pass('H No schema change') : fail('H No schema change');

    // I No PHP warnings from helper path.
    $prev = set_error_handler(static function (int $severity, string $message): bool {
        throw new RuntimeException($message);
    });
    try {
        $rows = list_course_module_rows($courseId);
        get_course($courseId);
        if (is_array($rows) && count($rows) >= 1) {
            pass('I No PHP warnings');
        } else {
            fail('I No PHP warnings');
        }
    } catch (Throwable $e) {
        fail('I No PHP warnings');
        echo '  ' . $e->getMessage() . "\n";
    } finally {
        restore_error_handler();
        if ($prev !== null) {
            set_error_handler($prev);
        }
    }
} catch (Throwable $e) {
    fail('Runtime: ' . $e->getMessage());
} finally {
    $pdo = db();
    foreach ($cleanup['module_ids'] as $mid) {
        $pdo->prepare('DELETE FROM course_modules WHERE module_id = :id')->execute(['id' => $mid]);
        $pdo->prepare('DELETE FROM modules WHERE module_id = :id')->execute(['id' => $mid]);
    }
    foreach ($cleanup['course_ids'] as $cid) {
        $pdo->prepare('DELETE FROM course_modules WHERE course_id = :id')->execute(['id' => $cid]);
        $pdo->prepare('DELETE FROM courses WHERE course_id = :id')->execute(['id' => $cid]);
    }
}

echo $failed === 0 ? "RESULT: PASSED\n" : "RESULT: FAILED ({$failed})\n";
exit($failed === 0 ? 0 : 1);
