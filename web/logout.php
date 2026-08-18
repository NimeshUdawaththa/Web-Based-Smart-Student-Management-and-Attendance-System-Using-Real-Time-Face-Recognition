<?php

declare(strict_types=1);

require_once __DIR__ . '/shared/includes/init.php';

send_auth_headers();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !verify_csrf()) {
    if (is_authenticated()) {
        set_flash('error', 'Unable to sign out. Please try again.');
        redirect(role_dashboard_path(current_user()['role']));
    }

    redirect('login.php');
}

logout_user();
start_secure_session();
set_flash('success', 'You have been signed out.');
redirect('login.php');
