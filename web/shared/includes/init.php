<?php

declare(strict_types=1);

define('APP_STARTED', true);

require_once dirname(__DIR__, 2) . '/config/env.php';
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/shared/includes/auth.php';

start_secure_session();
