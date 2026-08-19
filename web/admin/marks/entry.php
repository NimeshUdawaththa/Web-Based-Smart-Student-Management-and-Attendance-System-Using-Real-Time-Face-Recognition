<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('ADMIN');
$academicRoutePrefix = 'admin';
$marksCanEdit = false;
$restrictLecturerId = null;
require WEB_PATH . '/shared/pages/marks/entry.php';
