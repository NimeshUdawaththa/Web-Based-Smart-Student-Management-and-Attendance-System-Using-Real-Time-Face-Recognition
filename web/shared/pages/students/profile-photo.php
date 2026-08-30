<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = current_user();
if ($user === null) {
    deny_access();
}

$studentId = positive_int($_GET['id'] ?? null);
if ($studentId === null) {
    http_response_code(404);
    exit('Not found');
}

$payload = student_authorized_profile_photo($studentId, $user);
if ($payload === null) {
    http_response_code(404);
    exit('Not found');
}

student_send_profile_photo($payload);
