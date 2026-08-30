<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — create course_modules, backfill from modules.course_id,
 * and prepare global unique module codes.
 *
 * Does not drop modules.course_id.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/migrate_course_modules.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/web/config/env.php';
require_once dirname(__DIR__, 2) . '/web/config/config.php';
require_once dirname(__DIR__, 2) . '/web/config/database.php';
require_once dirname(__DIR__, 2) . '/web/shared/includes/academic.php';

$created = ensure_course_modules_table();
echo $created ? "created course_modules\n" : "course_modules already present\n";

$backfilled = backfill_course_modules_from_legacy_course_id();
echo "backfilled {$backfilled} course_modules row(s)\n";

ensure_modules_catalogue_ready();
echo "catalogue unique module_code ready\n";

$moduleCount = (int) db()->query('SELECT COUNT(*) FROM modules')->fetchColumn();
$linkCount = (int) db()->query('SELECT COUNT(*) FROM course_modules')->fetchColumn();
echo "modules={$moduleCount} course_modules={$linkCount}\n";
echo "migration complete\n";
