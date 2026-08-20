<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$pageTitle = $pageTitle ?? APP_NAME;
$user = current_user();
$homeUrl = $user !== null
    ? app_url(role_dashboard_path($user['role']))
    : app_url('login.php');
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= e(asset_url('css/app.css')) ?>" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column min-vh-100">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand" href="<?= e($homeUrl) ?>"><?= e(APP_NAME) ?></a>
        <?php if ($user !== null): ?>
            <div class="d-flex align-items-center gap-3">
                <span class="text-white-50 small"><?= e($user['username']) ?></span>
                <form method="post" action="<?= e(app_url('logout.php')) ?>" class="mb-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-light btn-sm">Logout</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</nav>
<main class="container py-4 flex-grow-1 app-main">
<?php if ($flash !== null): ?>
    <?php
    $flashClass = match ($flash['type']) {
        'success' => 'alert-success',
        'error' => 'alert-danger',
        default => 'alert-info',
    };
    ?>
    <div class="alert <?= e($flashClass) ?>" role="alert">
        <?= e($flash['message']) ?>
    </div>
<?php endif; ?>
