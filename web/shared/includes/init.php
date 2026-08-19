<?php

declare(strict_types=1);

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/config/env.php';
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/shared/includes/auth.php';
require_once dirname(__DIR__, 2) . '/shared/includes/management.php';
require_once dirname(__DIR__, 2) . '/shared/includes/academic.php';
require_once dirname(__DIR__, 2) . '/shared/includes/attendance.php';
require_once dirname(__DIR__, 2) . '/shared/includes/attendance-reports.php';
require_once dirname(__DIR__, 2) . '/shared/includes/assignments.php';
require_once dirname(__DIR__, 2) . '/shared/includes/camera.php';

start_secure_session();
