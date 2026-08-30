<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/includes/init.php';

require_camera_manager();
$cameraRoutePrefix = 'academic-staff';
require WEB_PATH . '/shared/pages/camera/index.php';
