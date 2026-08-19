<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — camera process control (Tests A–G, I).
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/test_camera_control.php
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
require_once dirname(__DIR__, 2) . '/web/shared/includes/camera.php';

$failed = false;

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

function count_live_recognition_processes(): int
{
    $powershell = camera_powershell_exe();
    if ($powershell === '') {
        return 0;
    }
    $script = <<<'PS'
$n = 0
Get-CimInstance -ClassName Win32_Process | ForEach-Object {
    $cmd = [string]$_.CommandLine
    if ($cmd -match 'live_recognition') { $n++ }
}
Write-Output $n
PS;
    $cmd = escapeshellarg($powershell)
        . ' -NoProfile -NonInteractive -Command '
        . escapeshellarg($script);
    $output = [];
    exec($cmd, $output);

    return (int) trim(implode('', $output));
}

echo 'timezone=' . APP_TIMEZONE . PHP_EOL;
echo 'FACE_CAMERA_INDEX=' . face_camera_index() . PHP_EOL;
echo 'python=' . camera_python_exe() . PHP_EOL;
echo 'proc_open=' . (function_exists('proc_open') ? 'yes' : 'no') . PHP_EOL;

if (!user_can_manage_camera('ADMIN') || !user_can_manage_camera('ACADEMIC_STAFF')) {
    fail('I ADMIN/ACADEMIC_STAFF must be allowed');
} elseif (user_can_manage_camera('STUDENT') || user_can_manage_camera('LECTURER')) {
    fail('I STUDENT/LECTURER must not be allowed');
} else {
    pass('I role helpers allow Academic Staff/Admin only');
}

camera_stop();
$statusA = camera_public_status();
echo 'testA status=' . ($statusA['status'] ?? '') . ' pid=' . json_encode($statusA['pid'] ?? null) . PHP_EOL;
if (($statusA['status'] ?? '') === 'OFFLINE' && ($statusA['pid'] ?? null) === null) {
    pass('A initial state OFFLINE');
} else {
    fail('A expected OFFLINE with no PID');
}

$startB = camera_start();
$statusB = camera_public_status();
echo 'testB start=' . json_encode($startB) . ' status=' . json_encode($statusB) . PHP_EOL;
$beforeCount = count_live_recognition_processes();
if (
    !empty($startB['ok'])
    && in_array($statusB['status'] ?? '', ['ONLINE', 'STARTING'], true)
    && ($statusB['pid'] ?? null) !== null
) {
    pass('B Start created one recognition process');
} else {
    fail('B expected ONLINE/STARTING with PID');
}

$startC = camera_start();
$afterCount = count_live_recognition_processes();
echo 'testC start=' . json_encode($startC) . ' processes=' . $afterCount . PHP_EOL;
if (!empty($startC['already']) && $afterCount <= max(1, $beforeCount)) {
    pass('C duplicate Start did not spawn a second process');
} else {
    fail('C expected Camera already running and one process');
}

$entry = camera_set_mode('ENTRY');
$exit = camera_set_mode('EXIT');
$mode = camera_current_mode();
echo 'testDE entry=' . json_encode($entry) . ' exit=' . json_encode($exit) . ' mode=' . $mode . PHP_EOL;
if (!empty($entry['ok']) && !empty($exit['ok']) && $mode === 'EXIT') {
    pass('D/E mode ENTRY then EXIT without starting a second camera');
} else {
    fail('D/E expected mode switching on the same process');
}

$stopF = camera_stop();
$statusF = camera_public_status();
echo 'testF stop=' . json_encode($stopF) . ' status=' . ($statusF['status'] ?? '') . PHP_EOL;
if (!empty($stopF['ok']) && ($statusF['status'] ?? '') === 'OFFLINE' && count_live_recognition_processes() === 0) {
    pass('F Stop terminated recognition and set OFFLINE');
} else {
    fail('F expected OFFLINE and no live_recognition process');
}

$startG = camera_start();
$statusG = camera_public_status();
$stopG = camera_stop();
echo 'testG start=' . json_encode($startG) . ' mid=' . ($statusG['status'] ?? '') . ' stop=' . json_encode($stopG) . PHP_EOL;
if (!empty($startG['ok']) && !empty($stopG['ok']) && camera_public_status()['status'] === 'OFFLINE') {
    pass('G restart then stop works');
} else {
    fail('G expected start then stop to work');
}

camera_set_mode('ENTRY');
echo 'configured_index=' . face_camera_index() . ' (H invalid-index is a browser/.env check; index is not accepted from the web form)' . PHP_EOL;

exit($failed ? 1 : 0);
