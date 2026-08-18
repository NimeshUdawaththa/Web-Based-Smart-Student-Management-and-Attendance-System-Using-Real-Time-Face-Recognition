<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_admin();
$academicRoutePrefix = 'admin';
$canManage = true;
require WEB_PATH . '/shared/pages/sessions/index.php';
