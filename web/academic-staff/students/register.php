<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_student_manager();
$studentRoutePrefix = 'academic-staff/students';
require WEB_PATH . '/shared/pages/students/register.php';
