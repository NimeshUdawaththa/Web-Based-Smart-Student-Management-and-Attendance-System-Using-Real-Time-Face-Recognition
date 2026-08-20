<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/includes/init.php';
require_role('ADMIN');

set_flash(
    'info',
    'Marks Monitor for the legacy marks ledger is retired. Use Coursework Monitor for read-only assessment oversight.'
);
redirect('admin/assignments/index.php');
