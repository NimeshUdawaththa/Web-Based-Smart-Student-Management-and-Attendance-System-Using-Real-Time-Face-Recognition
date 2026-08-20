<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('LECTURER');

$lecturer = current_lecturer_profile();
if ($lecturer === null) {
    set_flash('error', 'No lecturer profile is linked to this account.');
    redirect('lecturer/dashboard.php');
}

$studentRoutePrefix = 'lecturer/students';
$restrictLecturerId = (int) $lecturer['lecturer_id'];
require WEB_PATH . '/shared/pages/students/view.php';
