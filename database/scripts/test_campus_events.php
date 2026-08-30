<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Campus Events posters / remove event_type Tests A–M.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_campus_events.php
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
require_once dirname(__DIR__, 2) . '/web/shared/includes/campus-events.php';

$failed = false;
$cleanupIds = [];
$tempFiles = [];
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
 * @param list<string> $roles
 * @return array<string, mixed>
 */
function campus_event_payload(string $title, array $roles, string $start, string $end, string $status = 'PUBLISHED', ?string $publishedAt = null): array
{
    return [
        'title' => $title,
        'description' => 'Body',
        'start_datetime' => $start,
        'end_datetime' => $end,
        'location' => 'Main Hall',
        'target_roles' => $roles,
        'status' => $status,
        'published_at' => $publishedAt,
    ];
}

function make_temp_image(string $ext): string
{
    global $tempFiles;
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartams-poster-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $blob = match ($ext) {
        'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=', true),
        'jpg', 'jpeg' => base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDAREAAhEBAxEB/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQAAAP8A/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', true),
        'webp' => base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA', true),
        default => false,
    };
    if ($blob === false || file_put_contents($path, $blob) === false) {
        throw new RuntimeException('Unable to create temp image.' . $ext);
    }
    $tempFiles[] = $path;

    return $path;
}

function poster_upload(string $path, string $name): array
{
    return [
        'name' => $name,
        'type' => 'application/octet-stream',
        'tmp_name' => $path,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($path),
    ];
}

$pdo = db();
$existingIds = $pdo->query('SELECT campus_event_id FROM campus_events ORDER BY campus_event_id')->fetchAll(PDO::FETCH_COLUMN);
$unrelated = [
    'announcements' => (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn(),
    'schedules' => (int) $pdo->query('SELECT COUNT(*) FROM schedules')->fetchColumn(),
    'lecture_sessions' => (int) $pdo->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn(),
    'attendance_events' => (int) $pdo->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn(),
];

migrate_campus_events_schema();

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
$startSoon = $now->modify('+2 hours')->format('Y-m-d H:i:s');
$endSoon = $now->modify('+4 hours')->format('Y-m-d H:i:s');
$publishedNow = $now->modify('-1 hour')->format('Y-m-d H:i:s');

try {
    $plainId = create_campus_event($staffUserId, campus_event_payload(
        'No poster ' . $suffix,
        ['STUDENT'],
        $startSoon,
        $endSoon,
        'PUBLISHED',
        $publishedNow
    ));
    $cleanupIds[] = $plainId;
    $plain = get_campus_event($plainId);
    if ($plain !== null && empty($plain['poster_path'])) {
        pass('A Create event without poster → works');
    } else {
        fail('A Event without poster');
    }

    $pngPath = make_temp_image('png');
    $jpgPath = make_temp_image('jpg');
    $storedPng = campus_event_store_poster(poster_upload($pngPath, 'party.png'));
    $storedJpg = campus_event_store_poster(poster_upload($jpgPath, 'party.jpg'));
    $webpPath = make_temp_image('webp');
    $storedWebp = campus_event_store_poster(poster_upload($webpPath, 'party.webp'));
    $pngId = create_campus_event($staffUserId, array_merge(
        campus_event_payload('PNG poster ' . $suffix, ['STUDENT'], $startSoon, $endSoon, 'PUBLISHED', $publishedNow),
        ['poster_path' => $storedPng['relative_path']]
    ));
    $cleanupIds[] = $pngId;
    $jpgId = create_campus_event($staffUserId, array_merge(
        campus_event_payload('JPG poster ' . $suffix, ['STUDENT'], $startSoon, $endSoon, 'PUBLISHED', $publishedNow),
        ['poster_path' => $storedJpg['relative_path']]
    ));
    $cleanupIds[] = $jpgId;
    $webpId = create_campus_event($staffUserId, array_merge(
        campus_event_payload('WEBP poster ' . $suffix, ['STUDENT'], $startSoon, $endSoon, 'PUBLISHED', $publishedNow),
        ['poster_path' => $storedWebp['relative_path']]
    ));
    $cleanupIds[] = $webpId;
    $pngRow = get_campus_event($pngId);
    $jpgRow = get_campus_event($jpgId);
    $webpRow = get_campus_event($webpId);
    if (
        $pngRow !== null && campus_event_resolve_poster_path((string) $pngRow['poster_path']) !== null
        && $jpgRow !== null && campus_event_resolve_poster_path((string) $jpgRow['poster_path']) !== null
        && $webpRow !== null && campus_event_resolve_poster_path((string) $webpRow['poster_path']) !== null
    ) {
        pass('B Create event with JPG/PNG/WEBP → works');
    } else {
        fail('B Image poster create');
    }

    $auth = campus_event_authorized_poster($pngId, 'STUDENT');
    if ($auth !== null && is_file($auth['absolute_path']) && $auth['mime'] === 'image/png') {
        pass('C Poster displays on authorized Student event');
    } else {
        fail('C Authorized student poster');
    }

    $lecturerOnlyId = create_campus_event($staffUserId, array_merge(
        campus_event_payload('Lecturer poster ' . $suffix, ['LECTURER'], $startSoon, $endSoon, 'PUBLISHED', $publishedNow),
        ['poster_path' => $storedJpg['relative_path']]
    ));
    $cleanupIds[] = $lecturerOnlyId;
    if (campus_event_authorized_poster($lecturerOnlyId, 'STUDENT') === null
        && campus_event_authorized_poster($lecturerOnlyId, 'LECTURER') !== null
    ) {
        pass('D Untargeted Student cannot open poster endpoint');
    } else {
        fail('D Untargeted poster access');
    }

    $phpRejected = false;
    try {
        campus_event_store_poster(poster_upload($pngPath, 'shell.php'));
    } catch (InvalidArgumentException) {
        $phpRejected = true;
    }
    $doubleRejected = false;
    try {
        campus_event_store_poster(poster_upload($pngPath, 'shell.php.png'));
    } catch (InvalidArgumentException) {
        $doubleRejected = true;
    }
    $svgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartams-svg-' . bin2hex(random_bytes(4)) . '.svg';
    file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    $tempFiles[] = $svgPath;
    $svgRejected = false;
    try {
        campus_event_store_poster(poster_upload($svgPath, 'poster.svg'));
    } catch (InvalidArgumentException) {
        $svgRejected = true;
    }
    if ($phpRejected && $doubleRejected && $svgRejected) {
        pass('E PHP/SVG/script file rejected');
    } else {
        fail('E Dangerous file rejection');
    }

    $invalidPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smartams-fake-' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($invalidPath, 'not an image');
    $tempFiles[] = $invalidPath;
    $invalidRejected = false;
    try {
        campus_event_store_poster(poster_upload($invalidPath, 'fake.jpg'));
    } catch (InvalidArgumentException) {
        $invalidRejected = true;
    }
    $oversizeRejected = false;
    $over = poster_upload($pngPath, 'big.png');
    $over['size'] = EVENT_POSTER_MAX_BYTES + 1;
    try {
        campus_event_store_poster($over);
    } catch (InvalidArgumentException) {
        $oversizeRejected = true;
    }
    if ($invalidRejected && $oversizeRejected) {
        pass('F Oversized/invalid image rejected');
    } else {
        fail('F Invalid/oversize poster');
    }

    $oldPath = (string) $pngRow['poster_path'];
    $replacement = campus_event_store_poster(poster_upload($jpgPath, 'new.jpg'));
    update_campus_event($pngId, array_merge(
        campus_event_payload((string) $pngRow['title'], ['STUDENT'], $startSoon, $endSoon, 'PUBLISHED', $publishedNow),
        ['poster_path' => $replacement['relative_path'], 'description' => (string) ($pngRow['description'] ?? '')]
    ));
    campus_event_delete_poster($oldPath);
    $replaced = get_campus_event($pngId);
    if (
        $replaced !== null
        && (string) $replaced['poster_path'] === $replacement['relative_path']
        && campus_event_resolve_poster_path($oldPath) === null
        && campus_event_resolve_poster_path((string) $replaced['poster_path']) !== null
    ) {
        pass('G Replace poster → new image visible, old file safely removed');
    } else {
        fail('G Poster replace');
    }

    $keepPath = (string) $jpgRow['poster_path'];
    update_campus_event($jpgId, campus_event_payload(
        (string) $jpgRow['title'],
        ['STUDENT'],
        $startSoon,
        $endSoon,
        'PUBLISHED',
        $publishedNow
    ));
    $kept = get_campus_event($jpgId);
    if ($kept !== null && (string) $kept['poster_path'] === $keepPath) {
        pass('H Edit without uploading new poster → old poster remains');
    } else {
        fail('H Keep existing poster');
    }

    $uiFiles = [
        $root . '/web/shared/pages/campus-events/form.php',
        $root . '/web/shared/pages/campus-events/feed.php',
        $root . '/web/shared/pages/campus-events/feed-view.php',
        $root . '/web/shared/pages/campus-events/manage-index.php',
        $root . '/web/shared/pages/campus-events/manage-view.php',
        $root . '/web/student/dashboard.php',
        $root . '/web/lecturer/dashboard.php',
    ];
    $typeInUi = false;
    foreach ($uiFiles as $file) {
        $contents = (string) file_get_contents($file);
        if (str_contains($contents, 'event_type') || str_contains($contents, 'Event Type') || str_contains($contents, 'campus_event_type_label')) {
            $typeInUi = true;
        }
    }
    if (!$typeInUi && !function_exists('campus_event_types') && !function_exists('campus_event_type_label')) {
        pass('I Event Type no longer appears anywhere in UI');
    } else {
        fail('I Event Type still present');
    }

    if (!campus_events_column_exists('event_type')) {
        pass('J campus_events.event_type removed from schema/local DB');
    } else {
        fail('J event_type column still exists');
    }

    $nullable = $pdo->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'campus_events'
           AND COLUMN_NAME = 'poster_path'"
    )->fetchColumn();
    if (campus_events_column_exists('poster_path') && $nullable === 'YES') {
        pass('K campus_events.poster_path exists and is nullable');
    } else {
        fail('K poster_path column');
    }

    $afterIds = $pdo->query('SELECT campus_event_id FROM campus_events ORDER BY campus_event_id')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($existingIds, $afterIds);
    if ($missing === []) {
        pass('L Existing event rows survive migration');
    } else {
        fail('L Existing campus events missing');
    }

    $unrelatedAfter = [
        'announcements' => (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn(),
        'schedules' => (int) $pdo->query('SELECT COUNT(*) FROM schedules')->fetchColumn(),
        'lecture_sessions' => (int) $pdo->query('SELECT COUNT(*) FROM lecture_sessions')->fetchColumn(),
        'attendance_events' => (int) $pdo->query('SELECT COUNT(*) FROM attendance_events')->fetchColumn(),
    ];
    if ($unrelated === $unrelatedAfter) {
        pass('M Announcements/timetable/attendance unaffected');
    } else {
        fail('M Unrelated tables changed');
    }
} finally {
    set_app_now_override(null);
    foreach ($cleanupIds as $id) {
        $row = get_campus_event((int) $id);
        if ($row !== null && !empty($row['poster_path'])) {
            campus_event_delete_poster((string) $row['poster_path']);
        }
    }
    if ($cleanupIds !== []) {
        $placeholders = implode(',', array_fill(0, count($cleanupIds), '?'));
        $delete = $pdo->prepare('DELETE FROM campus_events WHERE campus_event_id IN (' . $placeholders . ')');
        $delete->execute($cleanupIds);
    }
    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

if ($failed) {
    fwrite(STDERR, "Campus event poster tests failed.\n");
    exit(1);
}

echo "All campus event poster tests A–M passed.\n";
exit(0);
