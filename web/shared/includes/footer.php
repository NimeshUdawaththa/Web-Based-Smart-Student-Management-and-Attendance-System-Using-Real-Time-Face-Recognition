<?php

declare(strict_types=1);

if (!defined('APP_STARTED')) {
    http_response_code(403);
    exit('Forbidden');
}
?>
</main>
<footer class="border-top bg-white py-3 mt-auto">
    <div class="container text-center text-muted small">
        &copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
