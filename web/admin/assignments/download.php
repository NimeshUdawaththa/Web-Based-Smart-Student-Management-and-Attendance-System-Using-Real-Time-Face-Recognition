<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('ADMIN');
$academicRoutePrefix = 'admin';
require WEB_PATH . '/shared/pages/assignments/download.php';
