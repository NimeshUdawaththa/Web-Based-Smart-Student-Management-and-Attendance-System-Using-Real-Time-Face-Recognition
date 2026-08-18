<?php

declare(strict_types=1);

/**
 * Internal face check-in bridge.
 * Flask posts a recognized student_id and camera mode (ENTRY or EXIT).
 * PHP first synchronizes timetable session states, records early door
 * ENTRY/EXIT as pending, promotes pending insides at scheduled start,
 * then records official IN/OUT/re-entry according to presence state.
 *
 * HTTP: POST /api/academic/recognition-check-in.php
 * CLI:  php recognition-check-in.php '{"student_id":3,"confidence":80,"camera_id":"webcam-0"}'
 */

require_once dirname(__DIR__, 2) . '/shared/includes/internal-init.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

if (PHP_SAPI === 'cli') {
    $raw = $argv[1] ?? file_get_contents('php://stdin');
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($data) && isset($data['token']) && is_string($data['token']) && $data['token'] !== '') {
        $cliToken = $data['token'];
    } else {
        $cliToken = (string) (env('INTERNAL_API_TOKEN') ?? '');
    }
    $_SERVER['HTTP_X_INTERNAL_TOKEN'] = $cliToken;
} else {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['result' => 'ERROR', 'error' => 'POST required']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $data = is_string($raw) ? json_decode($raw, true) : null;
}

require_internal_access();

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['result' => 'ERROR', 'error' => 'Invalid JSON']);
    exit;
}

$studentId = positive_int($data['student_id'] ?? null);
if ($studentId === null) {
    http_response_code(400);
    echo json_encode(['result' => 'INVALID_STUDENT']);
    exit;
}

$confidence = filter_var($data['confidence'] ?? 0, FILTER_VALIDATE_FLOAT);
if ($confidence === false) {
    $confidence = 0.0;
}

$cameraId = isset($data['camera_id']) && is_string($data['camera_id'])
    ? $data['camera_id']
    : null;

$cameraMode = isset($data['camera_mode']) && is_string($data['camera_mode'])
    ? $data['camera_mode']
    : null;

try {
    $result = record_face_attendance_event($studentId, (float) $confidence, $cameraId, $cameraMode);
} catch (Throwable $exception) {
    error_log('recognition-check-in failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['result' => 'ERROR']);
    exit;
}

http_response_code(200);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
