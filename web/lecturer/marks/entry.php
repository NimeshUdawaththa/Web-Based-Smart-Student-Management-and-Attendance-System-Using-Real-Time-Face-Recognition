<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('LECTURER');

set_flash(
    'info',
    'Legacy Assessment Results entry is retired. Record Exam and Practical marks under Coursework & Assessments → Results.'
);
redirect('lecturer/assignments/index.php');
