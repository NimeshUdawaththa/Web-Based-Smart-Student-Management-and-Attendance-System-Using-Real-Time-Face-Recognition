<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_role('LECTURER');

$pageTitle = 'Lecturer Dashboard';
$dashboardTitle = 'Lecturer Dashboard';

require INCLUDES_PATH . '/header.php';
require INCLUDES_PATH . '/dashboard-placeholder.php';
require INCLUDES_PATH . '/footer.php';
