<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_any_role(['LECTURER']);
$studentRoutePrefix = 'lecturer/students';
$readOnly = true;
require WEB_PATH . '/shared/pages/students/index.php';
