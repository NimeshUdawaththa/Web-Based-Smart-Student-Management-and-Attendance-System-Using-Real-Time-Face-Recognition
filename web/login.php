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
$authPage = true;
$authFlashInCard = true;
require INCLUDES_PATH . '/header.php';
?>

<div class="app-auth">
    <div class="app-auth__panel app-auth__panel--brand">
        <div class="app-auth__brand">
            <div class="app-auth__mark" aria-hidden="true">
                <i class="bi bi-mortarboard-fill"></i>
            </div>
            <p class="app-auth__eyebrow"><?= e(APP_NAME) ?></p>
            <h1 class="app-auth__title">Smart Student Management &amp; Attendance System</h1>
            <p class="app-auth__lead">
                Manage students, teaching and attendance from one secure workspace.
            </p>
        </div>
    </div>

    <div class="app-auth__panel app-auth__panel--form">
        <div class="app-auth__card card border-0">
            <div class="card-body">
                <?php $loginFlash = get_flash(); ?>
                <h2 class="app-auth__card-title mb-1">Welcome back</h2>
                <p class="text-muted <?= $loginFlash !== null ? 'mb-3' : 'mb-4' ?>">Sign in with your username or email address.</p>

                <?php if ($loginFlash !== null): ?>
                    <?php
                    $loginFlashClass = match ($loginFlash['type']) {
                        'success' => 'alert-success',
                        'error' => 'alert-danger',
                        default => 'alert-info',
                    };
                    ?>
                    <div class="alert <?= e($loginFlashClass) ?> app-auth__card-flash mb-3" role="alert">
                        <?= e($loginFlash['message']) ?>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?= e(app_url('login.php')) ?>" novalidate>
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="identifier" class="form-label">Username or email</label>
                        <input
                            type="text"
                            class="form-control form-control-lg"
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
                        <div class="app-password-field input-group input-group-lg">
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                autocomplete="current-password"
                                required
                            >
                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                id="toggle-password"
                                aria-label="Show password"
                                aria-controls="password"
                                aria-pressed="false"
                            >
                                <i class="bi bi-eye" aria-hidden="true"></i>
                                <span class="visually-hidden">Show password</span>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">Sign in</button>
                </form>

                <p class="app-auth__footnote text-muted small text-center mb-0 mt-4">
                    Institutional access only.<br>
                    Contact your administrator if you need an account.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const input = document.getElementById('password');
    const button = document.getElementById('toggle-password');
    if (!input || !button) {
        return;
    }

    button.addEventListener('click', function () {
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        button.setAttribute('aria-pressed', showing ? 'false' : 'true');
        button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        const icon = button.querySelector('i');
        const sr = button.querySelector('.visually-hidden');
        if (icon) {
            icon.className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
        }
        if (sr) {
            sr.textContent = showing ? 'Show password' : 'Hide password';
        }
    });
})();
</script>

<?php
require INCLUDES_PATH . '/footer.php';
