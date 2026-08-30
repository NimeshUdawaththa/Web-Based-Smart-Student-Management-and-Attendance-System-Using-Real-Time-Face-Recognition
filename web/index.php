<?php

declare(strict_types=1);

require_once __DIR__ . '/shared/includes/init.php';

send_auth_headers();

if (is_authenticated()) {
    $user = current_user();
    if ($user !== null) {
        redirect(role_dashboard_path($user['role']));
    }
}

redirect('login.php');
