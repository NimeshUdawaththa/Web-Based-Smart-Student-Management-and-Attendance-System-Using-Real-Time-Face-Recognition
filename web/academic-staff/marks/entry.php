<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('ACADEMIC_STAFF');

set_flash(
    'info',
    'Legacy marks entry is retired. Academic Staff do not enter assessment marks — use Coursework Monitor for read-only oversight.'
);
redirect('academic-staff/assignments/index.php');
