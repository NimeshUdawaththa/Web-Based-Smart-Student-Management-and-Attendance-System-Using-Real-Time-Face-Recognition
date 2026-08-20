<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — create batch_modules if missing.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/migrate_batch_modules.php
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

$created = ensure_batch_modules_table();
echo $created ? "created batch_modules\n" : "batch_modules already present\n";
echo "migration complete\n";
