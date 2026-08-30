<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Announcements v1.1 multi-target Tests A–T.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_announcements.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/auth.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/announcements.php';

$failed = false;
$cleanupIds = [];
$root = dirname(__DIR__, 2);

function fail(string $message): void
{
    global $failed;
    $failed = true;
    fwrite(STDERR, 'FAIL ' . $message . PHP_EOL);
}

function pass(string $message): void
{
    echo 'PASS ' . $message . PHP_EOL;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<int>
 */
function announcement_ids_of(array $rows): array
{
    return array_map(static fn(array $row): int => (int) $row['announcement_id'], $rows);
}

function list_has_id(array $rows, int $id): bool
{
    return in_array($id, announcement_ids_of($rows), true);
}

/**
 * @return array{title: string, message: string, target_roles: list<string>, published_at: string, expires_at: ?string, status: string}
 */
function notice_payload(string $title, array $roles, string $publishedAt, string $status = 'PUBLISHED', ?string $expiresAt = null, string $message = 'Body'): array
{
    return [
        'title' => $title,
        'message' => $message,
        'target_roles' => $roles,
        'published_at' => $publishedAt,
        'expires_at' => $expiresAt,
        'status' => $status,
    ];
}

$pdo = db();
ensure_announcement_targets_table();

$staff = $pdo->query(
    "SELECT user_id FROM users
     WHERE role IN ('ADMIN', 'ACADEMIC_STAFF') AND status = 'ACTIVE'
     ORDER BY user_id
     LIMIT 1"
)->fetch();
if ($staff === false) {
    fwrite(STDERR, "Need an active ADMIN or ACADEMIC_STAFF user.\n");
    exit(1);
}

$staffUserId = (int) $staff['user_id'];
$suffix = bin2hex(random_bytes(4));
$now = new DateTimeImmutable('2026-08-20 12:00:00', new DateTimeZone(APP_TIMEZONE));
set_app_now_override($now);

$idsBefore = $pdo->query('SELECT announcement_id FROM announcements ORDER BY announcement_id')->fetchAll(PDO::FETCH_COLUMN);

try {
    $studentOnlyId = create_announcement($staffUserId, notice_payload(
        'Student only ' . $suffix,
        ['STUDENT'],
        $now->modify('-1 hour')->format('Y-m-d H:i:s')
    ));
    $cleanupIds[] = $studentOnlyId;
    $studentRow = get_announcement($studentOnlyId);
    if (
        $studentRow !== null
        && $studentRow['target_roles'] === ['STUDENT']
        && (int) $studentRow['created_by'] === $staffUserId
    ) {
        pass('A Create STUDENT-only announcement');
    } else {
        fail('A STUDENT-only create/targets');
    }

    $studentFeed = list_visible_announcements_for_role('STUDENT');
    $lecturerFeed = list_visible_announcements_for_role('LECTURER');
    if (list_has_id($studentFeed, $studentOnlyId) && !list_has_id($lecturerFeed, $studentOnlyId)) {
        pass('B Student sees it; Lecturer does not');
    } else {
        fail('B STUDENT-only reader filter');
    }

    $lecturerOnlyId = create_announcement($staffUserId, notice_payload(
        'Lecturer only ' . $suffix,
        ['LECTURER'],
        $now->modify('-1 hour')->format('Y-m-d H:i:s')
    ));
    $cleanupIds[] = $lecturerOnlyId;
    pass('C Create LECTURER-only announcement');

    $studentFeed = list_visible_announcements_for_role('STUDENT');
    $lecturerFeed = list_visible_announcements_for_role('LECTURER');
    if (list_has_id($lecturerFeed, $lecturerOnlyId) && !list_has_id($studentFeed, $lecturerOnlyId)) {
        pass('D Lecturer sees it; Student does not');
    } else {
        fail('D LECTURER-only reader filter');
    }

    $bothId = create_announcement($staffUserId, notice_payload(
        'Campus Maintenance Notice ' . $suffix,
        ['STUDENT', 'LECTURER'],
        $now->modify('-1 hour')->format('Y-m-d H:i:s')
    ));
    $cleanupIds[] = $bothId;
    pass('E Create STUDENT + LECTURER announcement');

    $studentFeed = list_visible_announcements_for_role('STUDENT');
    $lecturerFeed = list_visible_announcements_for_role('LECTURER');
    $staffFeed = list_visible_announcements_for_role('ACADEMIC_STAFF');
    $adminFeed = list_visible_announcements_for_role('ADMIN');
    if (list_has_id($studentFeed, $bothId) && list_has_id($lecturerFeed, $bothId)) {
        pass('F Both Student and Lecturer see it');
    } else {
        fail('F Combined audience reader filter');
    }

    $managed = list_announcements_for_management();
    if (
        !list_has_id($staffFeed, $bothId)
        && !list_has_id($adminFeed, $bothId)
        && list_has_id($managed, $bothId)
        && !reader_can_view_announcement(get_announcement($bothId), 'ACADEMIC_STAFF')
        && !reader_can_view_announcement(get_announcement($bothId), 'ADMIN')
    ) {
        pass('G Academic Staff/Admin are not targeted merely because they can manage');
    } else {
        fail('G Management is not the same as audience targeting');
    }

    $allRolesId = create_announcement($staffUserId, notice_payload(
        'All roles ' . $suffix,
        announcement_selectable_roles(),
        $now->modify('-1 hour')->format('Y-m-d H:i:s')
    ));
    $cleanupIds[] = $allRolesId;
    $allRow = get_announcement($allRolesId);
    if (
        $allRow !== null
        && $allRow['target_roles'] === announcement_selectable_roles()
        && (string) $allRow['target_role'] === 'ALL'
    ) {
        pass('H Select All saves all four roles');
    } else {
        fail('H Select All / four target rows');
    }

    if (($allRow['audience_label'] ?? '') === 'All Roles' && announcement_audience_label($allRow['target_roles']) === 'All Roles') {
        pass('I All four roles display as All Roles');
    } else {
        fail('I All Roles label');
    }

    $editAddId = create_announcement($staffUserId, notice_payload(
        'Edit add lecturer ' . $suffix,
        ['STUDENT'],
        $now->modify('-1 hour')->format('Y-m-d H:i:s')
    ));
    $cleanupIds[] = $editAddId;
    $existing = get_announcement($editAddId);
    update_announcement($editAddId, [
        'title' => (string) $existing['title'],
        'message' => (string) $existing['message'],
        'target_roles' => ['STUDENT', 'LECTURER'],
        'published_at' => (string) $existing['published_at'],
        'expires_at' => $existing['expires_at'],
        'status' => (string) $existing['status'],
    ]);
    $studentFeed = list_visible_announcements_for_role('STUDENT');
    $lecturerFeed = list_visible_announcements_for_role('LECTURER');
    $afterAdd = get_announcement($editAddId);
    if (
        $afterAdd['target_roles'] === ['STUDENT', 'LECTURER']
        && list_has_id($studentFeed, $editAddId)
        && list_has_id($lecturerFeed, $editAddId)
    ) {
        pass('J Edit STUDENT-only → add LECTURER → both see it');
    } else {
        fail('J Edit add LECTURER');
    }

    update_announcement($editAddId, [
        'title' => (string) $afterAdd['title'],
        'message' => (string) $afterAdd['message'],
        'target_roles' => ['LECTURER'],
        'published_at' => (string) $afterAdd['published_at'],
        'expires_at' => $afterAdd['expires_at'],
        'status' => (string) $afterAdd['status'],
    ]);
    $studentFeed = list_visible_announcements_for_role('STUDENT');
    $lecturerFeed = list_visible_announcements_for_role('LECTURER');
    $afterRemove = get_announcement($editAddId);
    $targetCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM announcement_targets WHERE announcement_id = ' . $editAddId
    )->fetchColumn();
    if (
        $afterRemove['target_roles'] === ['LECTURER']
        && $targetCount === 1
        && !list_has_id($studentFeed, $editAddId)
        && list_has_id($lecturerFeed, $editAddId)
    ) {
        pass('K Edit STUDENT + LECTURER → remove STUDENT → student no longer sees it');
    } else {
        fail('K Edit remove STUDENT');
    }

    $duplicateBlocked = false;
    try {
        $pdo->prepare(
            'INSERT INTO announcement_targets (announcement_id, target_role) VALUES (?, ?)'
        )->execute([$studentOnlyId, 'STUDENT']);
    } catch (PDOException $exception) {
        $duplicateBlocked = str_contains($exception->getMessage(), '1062')
            || str_contains($exception->getMessage(), 'Duplicate')
            || $exception->getCode() === '23000';
    }
    if ($duplicateBlocked) {
        pass('L Duplicate target rows cannot be created');
    } else {
        fail('L Unique constraint should block duplicate targets');
    }

    $zeroRejected = false;
    try {
        create_announcement($staffUserId, notice_payload('Zero ' . $suffix, [], $now->format('Y-m-d H:i:s')));
    } catch (InvalidArgumentException) {
        $zeroRejected = true;
    }
    if ($zeroRejected) {
        pass('M Zero selected audiences rejected');
    } else {
        fail('M Empty audience must be rejected');
    }

    $invalidRejected = false;
    try {
        create_announcement($staffUserId, notice_payload('Bad role ' . $suffix, ['PARENT'], $now->format('Y-m-d H:i:s')));
    } catch (InvalidArgumentException) {
        $invalidRejected = true;
    }
    $allLiteralRejected = false;
    try {
        create_announcement($staffUserId, notice_payload('Bad ALL ' . $suffix, ['ALL'], $now->format('Y-m-d H:i:s')));
    } catch (InvalidArgumentException) {
        $allLiteralRejected = true;
    }
    if ($invalidRejected && $allLiteralRejected) {
        pass('N Invalid role value rejected server-side');
    } else {
        fail('N Invalid/ALL target values must be rejected');
    }

    $insertLegacy = $pdo->prepare(
        'INSERT INTO announcements (title, message, target_role, created_by, published_at, expires_at, status)
         VALUES (:title, :message, :target_role, :created_by, :published_at, NULL, :status)'
    );
    $insertLegacy->execute([
        'title' => 'Legacy ALL ' . $suffix,
        'message' => 'Migrated ALL',
        'target_role' => 'ALL',
        'created_by' => $staffUserId,
        'published_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
        'status' => 'PUBLISHED',
    ]);
    $legacyAllId = (int) $pdo->lastInsertId();
    $cleanupIds[] = $legacyAllId;
    backfill_legacy_announcement_targets();
    $legacyAllRoles = list_announcement_target_roles($legacyAllId);
    if (
        $legacyAllRoles === announcement_selectable_roles()
        && list_has_id(list_visible_announcements_for_role('STUDENT'), $legacyAllId)
        && list_has_id(list_visible_announcements_for_role('LECTURER'), $legacyAllId)
        && list_has_id(list_visible_announcements_for_role('ACADEMIC_STAFF'), $legacyAllId)
        && list_has_id(list_visible_announcements_for_role('ADMIN'), $legacyAllId)
    ) {
        pass('O Existing v1 ALL announcement remains visible to all intended roles after migration');
    } else {
        fail('O Legacy ALL backfill');
    }

    $insertLegacy->execute([
        'title' => 'Legacy STUDENT ' . $suffix,
        'message' => 'Migrated student',
        'target_role' => 'STUDENT',
        'created_by' => $staffUserId,
        'published_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
        'status' => 'PUBLISHED',
    ]);
    $legacyStudentId = (int) $pdo->lastInsertId();
    $cleanupIds[] = $legacyStudentId;
    $insertLegacy->execute([
        'title' => 'Legacy LECTURER ' . $suffix,
        'message' => 'Migrated lecturer',
        'target_role' => 'LECTURER',
        'created_by' => $staffUserId,
        'published_at' => $now->modify('-1 hour')->format('Y-m-d H:i:s'),
        'status' => 'PUBLISHED',
    ]);
    $legacyLecturerId = (int) $pdo->lastInsertId();
    $cleanupIds[] = $legacyLecturerId;
    backfill_legacy_announcement_targets();
    if (
        list_announcement_target_roles($legacyStudentId) === ['STUDENT']
        && list_announcement_target_roles($legacyLecturerId) === ['LECTURER']
        && list_has_id(list_visible_announcements_for_role('STUDENT'), $legacyStudentId)
        && !list_has_id(list_visible_announcements_for_role('LECTURER'), $legacyStudentId)
        && list_has_id(list_visible_announcements_for_role('LECTURER'), $legacyLecturerId)
        && !list_has_id(list_visible_announcements_for_role('STUDENT'), $legacyLecturerId)
    ) {
        pass('P Existing single-role announcements retain correct audience after migration');
    } else {
        fail('P Legacy single-role backfill');
    }

    $draftId = create_announcement($staffUserId, notice_payload(
        'Draft ' . $suffix,
        ['STUDENT', 'LECTURER'],
        $now->modify('-1 hour')->format('Y-m-d H:i:s'),
        'DRAFT'
    ));
    $cleanupIds[] = $draftId;
    $futureId = create_announcement($staffUserId, notice_payload(
        'Future ' . $suffix,
        ['STUDENT', 'LECTURER'],
        $now->modify('+2 hours')->format('Y-m-d H:i:s')
    ));
    $cleanupIds[] = $futureId;
    $expiredId = create_announcement($staffUserId, notice_payload(
        'Expired ' . $suffix,
        ['STUDENT', 'LECTURER'],
        $now->modify('-2 hours')->format('Y-m-d H:i:s'),
        'PUBLISHED',
        $now->modify('-1 hour')->format('Y-m-d H:i:s')
    ));
    $cleanupIds[] = $expiredId;
    $studentFeed = list_visible_announcements_for_role('STUDENT');
    if (
        !list_has_id($studentFeed, $draftId)
        && !list_has_id($studentFeed, $futureId)
        && !list_has_id($studentFeed, $expiredId)
        && announcement_lifecycle_label(get_announcement($draftId)) === 'Draft'
        && announcement_lifecycle_label(get_announcement($futureId)) === 'Scheduled'
        && announcement_lifecycle_label(get_announcement($expiredId)) === 'Expired'
        && list_has_id(list_announcements_for_management(), $draftId)
        && list_has_id(list_announcements_for_management(), $expiredId)
    ) {
        pass('Q Draft/scheduled/expired filtering still works');
    } else {
        fail('Q Publish/expiry filtering');
    }

    $dashIds = [];
    foreach ([4, 3, 2, 1] as $minutesAgo) {
        $id = create_announcement($staffUserId, notice_payload(
            'Dash ' . $minutesAgo . ' ' . $suffix,
            ['STUDENT'],
            $now->modify('-' . $minutesAgo . ' minutes')->format('Y-m-d H:i:s')
        ));
        $cleanupIds[] = $id;
        $dashIds[$minutesAgo] = $id;
    }
    $studentAll = list_visible_announcements_for_role('STUDENT');
    $dashInOrder = [];
    foreach ($studentAll as $row) {
        if (preg_match('/^Dash [1-4] ' . preg_quote($suffix, '/') . '$/', (string) $row['title']) === 1) {
            $dashInOrder[] = (int) $row['announcement_id'];
        }
    }
    $limited = list_visible_announcements_for_role('STUDENT', 3);
    $lecturerDash = list_visible_announcements_for_role('LECTURER', 3);
    if (
        $dashInOrder === [$dashIds[1], $dashIds[2], $dashIds[3], $dashIds[4]]
        && announcement_ids_of($limited) === array_slice(announcement_ids_of($studentAll), 0, 3)
        && !list_has_id($lecturerDash, $dashIds[1])
    ) {
        pass('R Dashboard Recent Announcements respects multi-target filtering');
    } else {
        fail('R Dashboard limit/filter');
    }

    $studentCreateMissing = !is_file($root . '/web/student/announcements/create.php')
        && !is_file($root . '/web/student/announcements/edit.php')
        && !is_file($root . '/web/public/student/announcements/create.php')
        && !is_file($root . '/web/lecturer/announcements/create.php')
        && !is_file($root . '/web/lecturer/announcements/edit.php');
    $studentIndex = (string) file_get_contents($root . '/web/student/announcements/index.php');
    $lecturerIndex = (string) file_get_contents($root . '/web/lecturer/announcements/index.php');
    if (
        $studentCreateMissing
        && str_contains($studentIndex, "require_role('STUDENT')")
        && str_contains($lecturerIndex, "require_role('LECTURER')")
        && str_contains($studentIndex, 'feed.php')
        && str_contains($lecturerIndex, 'feed.php')
    ) {
        pass('S Student/Lecturer still cannot manage announcements');
    } else {
        fail('S Read-only student/lecturer routes');
    }

    $idsAfter = $pdo->query('SELECT announcement_id FROM announcements ORDER BY announcement_id')->fetchAll(PDO::FETCH_COLUMN);
    $missingOriginal = array_diff($idsBefore, $idsAfter);
    if ($missingOriginal === []) {
        pass('T No existing announcement data is lost');
    } else {
        fail('T Existing announcement ids missing after tests');
    }
} finally {
    set_app_now_override(null);
    if ($cleanupIds !== []) {
        $placeholders = implode(',', array_fill(0, count($cleanupIds), '?'));
        $delete = $pdo->prepare('DELETE FROM announcements WHERE announcement_id IN (' . $placeholders . ')');
        $delete->execute($cleanupIds);
    }
}

if ($failed) {
    fwrite(STDERR, "Announcements v1.1 tests failed.\n");
    exit(1);
}

echo "All announcements tests A–T passed.\n";
exit(0);
