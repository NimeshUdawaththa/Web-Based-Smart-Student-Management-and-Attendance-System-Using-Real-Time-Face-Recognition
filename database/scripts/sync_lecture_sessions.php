<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — run timetable session state sync once.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/sync_lecture_sessions.php
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

$stats = sync_scheduled_session_states(true);

echo 'timezone=' . APP_TIMEZONE . PHP_EOL;
echo 'now=' . app_now_datetime() . PHP_EOL;
echo 'opened=' . $stats['opened'] . PHP_EOL;
echo 'closed=' . $stats['closed'] . PHP_EOL;
echo 'expired=' . $stats['expired'] . PHP_EOL;
