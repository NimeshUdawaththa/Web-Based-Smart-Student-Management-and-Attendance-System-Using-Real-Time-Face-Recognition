<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

/** @var string $academicRoutePrefix */
$academicRoutePrefix = $academicRoutePrefix ?? 'lecturer';

$user = current_user();
if ($user === null) {
    deny_access();
}

$kind = (string) ($_GET['type'] ?? '');
$id = positive_int($_GET['id'] ?? null);
if ($id === null) {
    deny_access();
}

$lecturerId = null;
$studentId = null;
if ($user['role'] === 'LECTURER') {
    $profile = current_lecturer_profile();
    $lecturerId = $profile !== null ? (int) $profile['lecturer_id'] : 0;
}
if ($user['role'] === 'STUDENT') {
    $profile = current_student_profile();
    $studentId = $profile !== null ? (int) $profile['student_id'] : 0;
}

$payload = assignment_authorized_download($kind, $id, $user['role'], $lecturerId, $studentId);
if ($payload === null) {
    deny_access();
}

assignment_send_download($payload);
