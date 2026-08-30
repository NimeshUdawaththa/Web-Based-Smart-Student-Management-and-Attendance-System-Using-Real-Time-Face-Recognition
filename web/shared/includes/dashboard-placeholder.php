<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}

$user = current_user();
?>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-2"><?= e($dashboardTitle ?? 'Dashboard') ?></h1>
                <p class="text-muted mb-4">Placeholder dashboard. Application features are not enabled yet.</p>
                <dl class="row mb-4">
                    <dt class="col-sm-4">Role</dt>
                    <dd class="col-sm-8"><?= e(role_label($user['role'] ?? '')) ?></dd>
                    <dt class="col-sm-4">Username</dt>
                    <dd class="col-sm-8 mb-0"><?= e($user['username'] ?? '') ?></dd>
                </dl>
                <form method="post" action="<?= e(app_url('logout.php')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-primary">Logout</button>
                </form>
            </div>
        </div>
    </div>
</div>
