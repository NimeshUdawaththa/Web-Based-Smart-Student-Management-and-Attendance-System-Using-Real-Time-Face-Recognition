<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_admin();
$studentRoutePrefix = 'admin/students';
require WEB_PATH . '/shared/pages/students/register.php';
