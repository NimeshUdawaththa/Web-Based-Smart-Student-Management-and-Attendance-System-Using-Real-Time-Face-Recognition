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

$campusEventId = positive_int($_GET['id'] ?? null);
if ($campusEventId === null) {
    deny_access();
}

$payload = campus_event_authorized_poster($campusEventId, (string) $user['role']);
if ($payload === null) {
    deny_access();
}

campus_event_send_poster($payload);
