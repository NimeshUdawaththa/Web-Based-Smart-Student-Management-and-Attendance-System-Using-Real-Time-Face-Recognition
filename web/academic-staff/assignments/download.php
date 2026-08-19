<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('ACADEMIC_STAFF');
$academicRoutePrefix = 'academic-staff';
require WEB_PATH . '/shared/pages/assignments/download.php';
