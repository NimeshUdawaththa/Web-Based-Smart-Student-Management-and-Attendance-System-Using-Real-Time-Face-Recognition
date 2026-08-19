<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('STUDENT');
$academicRoutePrefix = 'student';
require WEB_PATH . '/shared/pages/announcements/feed.php';
