<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

const AUTH_ROLES = ['ADMIN', 'ACADEMIC_STAFF', 'LECTURER', 'STUDENT'];

function start_secure_session(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }

    session_name('ssm_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(?string $token = null): bool
{
    $token ??= isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : '';

    return hash_equals(csrf_token(), $token);
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * @return array{type: string, message: string}|null
 */
function get_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    if (!is_array($flash) || !isset($flash['type'], $flash['message'])) {
        return null;
    }

    return [
        'type' => (string) $flash['type'],
        'message' => (string) $flash['message'],
    ];
}

function is_authenticated(): bool
{
    return isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role']);
}

/**
 * @return array{user_id: int, username: string, role: string}|null
 */
function current_user(): ?array
{
    if (!is_authenticated()) {
        return null;
    }

    return [
        'user_id' => (int) $_SESSION['user_id'],
        'username' => (string) $_SESSION['username'],
        'role' => (string) $_SESSION['role'],
    ];
}

function role_label(string $role): string
{
    return match ($role) {
        'ADMIN' => 'Admin',
        'ACADEMIC_STAFF' => 'Academic Staff',
        'LECTURER' => 'Lecturer',
        'STUDENT' => 'Student',
        default => $role,
    };
}

function role_dashboard_path(string $role): string
{
    return match ($role) {
        'ADMIN' => 'admin/dashboard.php',
        'ACADEMIC_STAFF' => 'academic-staff/dashboard.php',
        'LECTURER' => 'lecturer/dashboard.php',
        'STUDENT' => 'student/dashboard.php',
        default => 'login.php',
    };
}

function send_auth_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function logout_user(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?: '',
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function attempt_login(string $identifier, string $password): bool
{
    $statement = db()->prepare(
        'SELECT user_id, username, password_hash, role, status
         FROM users
         WHERE username = :username OR email = :email
         LIMIT 1'
    );
    $statement->execute([
        'username' => $identifier,
        'email' => $identifier,
    ]);

    $user = $statement->fetch();

    if ($user === false || !is_string($user['password_hash'])) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    if ($user['status'] !== 'ACTIVE') {
        return false;
    }

    if (!in_array($user['role'], AUTH_ROLES, true)) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];

    return true;
}

function require_login(): void
{
    send_auth_headers();

    if (!is_authenticated()) {
        set_flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }

    try {
        $statement = db()->prepare(
            'SELECT user_id, username, role, status
             FROM users
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => (int) $_SESSION['user_id'],
        ]);
        $user = $statement->fetch();
    } catch (Throwable $exception) {
        error_log('Session user lookup failed: ' . $exception->getMessage());
        logout_user();
        start_secure_session();
        set_flash('error', 'Unable to verify your session. Please sign in again.');
        redirect('login.php');
    }

    if (
        $user === false
        || $user['status'] !== 'ACTIVE'
        || !in_array($user['role'], AUTH_ROLES, true)
    ) {
        logout_user();
        start_secure_session();
        set_flash('error', 'Please sign in to continue.');
        redirect('login.php');
    }

    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
}

function require_role(string $role): void
{
    require_any_role([$role]);
}

/**
 * @param list<string> $roles
 */
function require_any_role(array $roles): void
{
    require_login();

    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }

    if (!in_array($user['role'], $roles, true)) {
        set_flash('error', 'You do not have permission to access that page.');
        redirect(role_dashboard_path($user['role']));
    }
}

function require_admin(): void
{
    require_any_role(['ADMIN']);
}

function require_student_manager(): void
{
    require_any_role(['ADMIN', 'ACADEMIC_STAFF']);
}

function deny_access(): never
{
    $user = current_user();
    set_flash('error', 'You do not have permission to access that page.');
    redirect(role_dashboard_path($user['role'] ?? 'STUDENT'));
}
