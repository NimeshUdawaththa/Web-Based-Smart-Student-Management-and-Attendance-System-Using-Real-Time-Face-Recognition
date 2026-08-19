<?php

declare(strict_types=1);

/**
 * DEVELOPMENT helper — create announcement_targets and backfill from v1 target_role.
 *
 * Usage:
 *   C:\xampp\php\php.exe database/scripts/migrate_announcement_targets.php
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
require_once dirname(__DIR__, 2) . '/web/shared/includes/announcements.php';

$pdo = db();
$createdTable = ensure_announcement_targets_table();
$inserted = backfill_legacy_announcement_targets();

echo $createdTable ? "created announcement_targets\n" : "announcement_targets already present\n";
echo "backfilled {$inserted} target row(s)\n";
echo "migration complete\n";
