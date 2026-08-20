<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('ADMIN');

set_flash(
    'info',
    'Legacy marks entry is retired. Admin does not enter assessment marks — use Coursework Monitor for read-only oversight.'
);
redirect('admin/assignments/index.php');
