<?php

declare(strict_types=1);

require_once __DIR__ . '/shared/includes/init.php';

send_auth_headers();

if (is_authenticated()) {
    redirect(role_dashboard_path(current_user()['role']));
}

$identifier = (string) ($_SESSION['login_identifier'] ?? '');
unset($_SESSION['login_identifier']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $_SESSION['login_identifier'] = $identifier;

    if (!verify_csrf()) {
        set_flash('error', 'Unable to sign in. Please try again.');
        redirect('login.php');
    }

    if ($identifier === '' || $password === '') {
        set_flash('error', 'Enter your username or email and password.');
        redirect('login.php');
    }

    try {
        $signedIn = attempt_login($identifier, $password);
    } catch (Throwable $exception) {
        error_log('Login failed: ' . $exception->getMessage());
        set_flash('error', 'Unable to sign in. Please try again.');
        redirect('login.php');
    }

    if ($signedIn) {
        unset($_SESSION['login_identifier']);
        redirect(role_dashboard_path(current_user()['role']));
    }

    set_flash('error', 'Invalid username or password.');
    redirect('login.php');
}

$pageTitle = 'Sign in';
require INCLUDES_PATH . '/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-2">Sign in</h1>
                <p class="text-muted mb-4">Use your username or email address.</p>

                <form method="post" action="<?= e(app_url('login.php')) ?>" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="identifier" class="form-label">Username or email</label>
                        <input
                            type="text"
                            class="form-control"
                            id="identifier"
                            name="identifier"
                            value="<?= e($identifier) ?>"
                            autocomplete="username"
                            required
                            autofocus
                        >
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                            required
                        >
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Sign in</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require INCLUDES_PATH . '/footer.php';
