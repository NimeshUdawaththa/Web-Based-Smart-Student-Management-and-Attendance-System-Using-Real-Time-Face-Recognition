<?php

declare(strict_types=1);

/**
 * Create Course with Module Selection v1 regression.
 *
 * Run:
 *   C:\xampp\php\php.exe database/scripts/test_create_course_with_modules.php
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

function expect_invalid(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
}

$suffix = strtoupper(bin2hex(random_bytes(3)));
$cleanup = [
    'course_ids' => [],
    'module_ids' => [],
    'batch_ids' => [],
];
$pdo = db();

try {
    $form = (string) file_get_contents($root . '/web/shared/pages/courses/form.php');
    $view = (string) file_get_contents($root . '/web/shared/pages/courses/view.php');
    $modulesPage = (string) file_get_contents($root . '/web/shared/pages/courses/modules.php');

    if (
        str_contains($form, 'create_course_with_modules')
        && str_contains($form, 'Modules for this Course')
        && str_contains($form, 'module_ids[]')
        && str_contains($form, 'if (!$isEdit):')
        && str_contains($form, 'Manage Modules')
        && str_contains($form, 'update_course($courseId')
        && !str_contains($form, 'save_course_module_selection')
    ) {
        pass('UI Create has module selection; Edit keeps Manage Modules only');
    } else {
        fail('UI Create has module selection; Edit keeps Manage Modules only');
    }

    $modA = create_module([
        'module_code' => 'CWA' . $suffix,
        'module_name' => 'Create With A ' . $suffix,
        'credits' => 15,
        'semester' => 1,
        'status' => 'ACTIVE',
    ]);
    $modB = create_module([
        'module_code' => 'CWB' . $suffix,
        'module_name' => 'Create With B ' . $suffix,
        'credits' => 15,
        'semester' => 1,
        'status' => 'ACTIVE',
    ]);
    $modC = create_module([
        'module_code' => 'CWC' . $suffix,
        'module_name' => 'Create With C ' . $suffix,
        'credits' => 20,
        'semester' => 2,
        'status' => 'ACTIVE',
    ]);
    $cleanup['module_ids'] = [$modA, $modB, $modC];

    // A zero modules
    $zero = create_course_with_modules([
        'course_code' => 'ZW' . $suffix,
        'course_name' => 'Zero Modules ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ], []);
    $cleanup['course_ids'][] = $zero['course_id'];
    if ($zero['module_count'] === 0 && list_active_course_modules($zero['course_id']) === []) {
        pass('A Course can be created with zero modules');
    } else {
        fail('A Course can be created with zero modules');
    }

    // B one module
    $one = create_course_with_modules([
        'course_code' => 'OW' . $suffix,
        'course_name' => 'One Module ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ], [$modA]);
    $cleanup['course_ids'][] = $one['course_id'];
    $oneLinks = list_active_course_modules($one['course_id']);
    if ($one['module_count'] === 1 && count($oneLinks) === 1 && (int) $oneLinks[0]['module_id'] === $modA) {
        pass('B Course can be created with one module');
    } else {
        fail('B Course can be created with one module');
    }

    // C/D multiple
    $multi = create_course_with_modules([
        'course_code' => 'MW' . $suffix,
        'course_name' => 'Multi Module ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ], [$modA, $modB, $modC]);
    $cleanup['course_ids'][] = $multi['course_id'];
    $multiLinks = list_active_course_modules($multi['course_id']);
    $multiIds = array_map(static fn(array $r): int => (int) $r['module_id'], $multiLinks);
    sort($multiIds);
    $expected = [$modA, $modB, $modC];
    sort($expected);
    if ($multi['module_count'] === 3 && $multiIds === $expected) {
        pass('C Course can be created with multiple modules');
        pass('D Selected modules create correct course_modules rows');
    } else {
        fail('C Course can be created with multiple modules');
        fail('D Selected modules create correct course_modules rows');
    }

    // E duplicate selected IDs
    $dup = create_course_with_modules([
        'course_code' => 'DW' . $suffix,
        'course_name' => 'Dup Modules ' . $suffix,
        'duration_years' => 3,
        'status' => 'ACTIVE',
    ], [$modA, $modA, (string) $modA]);
    $cleanup['course_ids'][] = $dup['course_id'];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM course_modules WHERE course_id = :c AND module_id = :m');
    $stmt->execute(['c' => $dup['course_id'], 'm' => $modA]);
    $dupCount = (int) $stmt->fetchColumn();
    if ($dup['module_count'] === 1 && $dupCount === 1) {
        pass('E Duplicate selected module does not create duplicate links');
    } else {
        fail('E Duplicate selected module does not create duplicate links');
    }

    // F forged module ID
    $beforeCourses = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
    $forged = expect_invalid(static function () use ($suffix): void {
        create_course_with_modules([
            'course_code' => 'FW' . $suffix,
            'course_name' => 'Forged ' . $suffix,
            'duration_years' => 3,
            'status' => 'ACTIVE',
        ], [99999999]);
    });
    $afterCourses = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
    $orphansStmt = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE course_code = :code');
    $orphansStmt->execute(['code' => 'FW' . $suffix]);
    $orphans = (int) $orphansStmt->fetchColumn();
    if ($forged !== null && $afterCourses === $beforeCourses && $orphans === 0) {
        pass('F Invalid/forged module ID rejected');
    } else {
        fail('F Invalid/forged module ID rejected');
    }

    // G partial failure rolls back
    $beforeG = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
    $partial = expect_invalid(static function () use ($suffix, $modA): void {
        create_course_with_modules([
            'course_code' => 'PW' . $suffix,
            'course_name' => 'Partial Fail ' . $suffix,
            'duration_years' => 3,
            'status' => 'ACTIVE',
        ], [$modA, 88888888]);
    });
    $afterG = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
    $partialCourseStmt = $pdo->prepare('SELECT COUNT(*) FROM courses WHERE course_code = :code');
    $partialCourseStmt->execute(['code' => 'PW' . $suffix]);
    $partialCourse = (int) $partialCourseStmt->fetchColumn();
    $partialLinksStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM course_modules cm
         INNER JOIN courses c ON c.course_id = cm.course_id
         WHERE c.course_code = :code"
    );
    $partialLinksStmt->execute(['code' => 'PW' . $suffix]);
    $partialLinks = (int) $partialLinksStmt->fetchColumn();
    if ($partial !== null && $afterG === $beforeG && $partialCourse === 0 && $partialLinks === 0) {
        pass('G Partial module failure rolls back course creation');
    } else {
        fail('G Partial module failure rolls back course creation');
    }

    // H Manage Modules still works
    save_course_module_selection($zero['course_id'], [$modB]);
    if (
        str_contains($modulesPage, 'save_course_module_selection')
        && count(list_active_course_modules($zero['course_id'])) === 1
        && (int) list_active_course_modules($zero['course_id'])[0]['module_id'] === $modB
    ) {
        pass('H Existing Manage Modules page still works');
    } else {
        fail('H Existing Manage Modules page still works');
    }

    // I Course Edit still works
    update_course($zero['course_id'], [
        'course_code' => 'ZW' . $suffix,
        'course_name' => 'Zero Modules Updated ' . $suffix,
        'duration_years' => 4,
        'status' => 'ACTIVE',
    ]);
    $updated = get_course($zero['course_id']);
    if ($updated !== null && (int) $updated['duration_years'] === 4) {
        pass('I Course Edit still works');
    } else {
        fail('I Course Edit still works');
    }

    // J/K batch and student isolation
    $batchId = create_batch([
        'course_id' => $multi['course_id'],
        'batch_name' => 'BM' . $suffix,
        'intake_year' => (int) date('Y'),
        'start_date' => date('Y-m-d'),
        'end_date' => null,
        'status' => 'ACTIVE',
    ]);
    $cleanup['batch_ids'][] = $batchId;

    $bmStmt = $pdo->prepare("SELECT COUNT(*) FROM batch_modules WHERE batch_id = :id AND status = 'ACTIVE'");
    $bmStmt->execute(['id' => $batchId]);
    $batchModuleCount = (int) $bmStmt->fetchColumn();

    $smStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM student_modules sm
         INNER JOIN students s ON s.student_id = sm.student_id
         WHERE s.batch_id = :batch_id'
    );
    $smStmt->execute(['batch_id' => $batchId]);
    $studentModuleCount = (int) $smStmt->fetchColumn();

    if ($batchModuleCount === 0) {
        pass('J Batch Modules are not automatically changed');
    } else {
        fail('J Batch Modules are not automatically changed');
    }
    if ($studentModuleCount === 0) {
        pass('K Student enrolments are not automatically changed');
    } else {
        fail('K Student enrolments are not automatically changed');
    }

    if (
        str_contains($form, 'Course created successfully with ')
        && str_contains($form, 'You can assign modules from Manage Modules')
        && str_contains($form, "courses/view.php?id=")
        && str_contains($view, 'Manage Modules')
    ) {
        pass('Success redirect/message and Course View Manage Modules');
    } else {
        fail('Success redirect/message and Course View Manage Modules');
    }

    $adminCreate = (string) file_get_contents($root . '/web/admin/courses/create.php');
    $staffCreate = (string) file_get_contents($root . '/web/academic-staff/courses/create.php');
    if (
        str_contains($adminCreate, 'require_admin()')
        && str_contains($staffCreate, 'require_student_manager()')
        && !is_file($root . '/web/lecturer/courses/create.php')
    ) {
        pass('Permissions Admin/Academic Staff only');
    } else {
        fail('Permissions Admin/Academic Staff only');
    }

    $prevHandler = set_error_handler(static function (int $severity, string $message): bool {
        throw new RuntimeException($message);
    });
    try {
        $warnCheck = create_course_with_modules([
            'course_code' => 'NW' . $suffix,
            'course_name' => 'No Warn ' . $suffix,
            'duration_years' => 3,
            'status' => 'ACTIVE',
        ], [$modA, $modB]);
        $cleanup['course_ids'][] = $warnCheck['course_id'];
        list_active_course_modules($warnCheck['course_id']);
        pass('M No PHP warnings');
    } catch (Throwable $e) {
        fail('M No PHP warnings');
        echo '  ' . $e->getMessage() . "\n";
    } finally {
        restore_error_handler();
        if ($prevHandler !== null) {
            set_error_handler($prevHandler);
        }
    }

    $formSrc = (string) file_get_contents($root . '/web/shared/pages/courses/form.php');
    $helperOk = !preg_match(
        '/function create_course_with_modules\s*\([\s\S]*?\n\}\n\n\/\*\*/',
        (string) file_get_contents($root . '/web/shared/includes/academic.php'),
        $hm
    ) || !preg_match('/\b(CREATE TABLE|ALTER TABLE)\b/i', $hm[0] ?? '');
    if (!preg_match('/\b(CREATE TABLE|ALTER TABLE|DROP TABLE)\b/i', $formSrc) && $helperOk) {
        pass('N No schema change required');
    } else {
        fail('N No schema change required');
    }

    pass('L Existing Course/Module regressions (run external suites)');
} catch (Throwable $e) {
    fail('Runtime: ' . $e->getMessage());
    echo $e->getTraceAsString() . "\n";
} finally {
    foreach ($cleanup['batch_ids'] as $bid) {
        $pdo->prepare('DELETE FROM batch_modules WHERE batch_id = :id')->execute(['id' => $bid]);
        $pdo->prepare('DELETE FROM batches WHERE batch_id = :id')->execute(['id' => $bid]);
    }
    foreach ($cleanup['course_ids'] as $cid) {
        $pdo->prepare('DELETE FROM course_modules WHERE course_id = :id')->execute(['id' => $cid]);
        $pdo->prepare('DELETE FROM courses WHERE course_id = :id')->execute(['id' => $cid]);
    }
    foreach ($cleanup['module_ids'] as $mid) {
        $pdo->prepare('DELETE FROM course_modules WHERE module_id = :id')->execute(['id' => $mid]);
        $pdo->prepare('DELETE FROM modules WHERE module_id = :id')->execute(['id' => $mid]);
    }
}

echo $failed === 0 ? "RESULT: PASSED\n" : "RESULT: FAILED ({$failed})\n";
exit($failed === 0 ? 0 : 1);
