<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('LECTURER');

set_flash(
    'info',
    'Assessment Results have moved into Coursework & Assessments. Use Assignments, Presentations, Exams and Practicals there.'
);
redirect('lecturer/assignments/index.php');
