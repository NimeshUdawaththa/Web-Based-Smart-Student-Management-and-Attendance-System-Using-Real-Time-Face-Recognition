<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — create campus_events and campus_event_targets if missing.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/migrate_campus_events.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/auth.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/campus-events.php';

$notes = migrate_campus_events_schema();
if ($notes === []) {
    echo "campus_events already present\n";
} else {
    foreach ($notes as $note) {
        echo $note . PHP_EOL;
    }
}
echo "migration complete\n";
