<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — Phase 6 drop of modules.course_id.
 *
 * Run only after PHP/JS/tests no longer depend on modules.course_id.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/migrate_drop_modules_course_id.php
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

ensure_course_modules_table();
backfill_course_modules_from_legacy_course_id();
ensure_modules_catalogue_ready();

$dropped = drop_modules_course_id_column();
$meta = modules_legacy_course_id_meta(true);
if ($dropped) {
    echo "dropped modules.course_id\n";
} else {
    echo "modules.course_id already absent\n";
}

if ($meta['exists']) {
    fwrite(STDERR, "modules.course_id is still present.\n");
    exit(1);
}

echo "migration complete\n";
