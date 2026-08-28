<!DOCTYPE html>
<html lang="en">
<head>
<?php require BASE_PATH . '/resources/views/partials/head.php'; ?>
</head>
<body>
    <div class="guest-shell">
        <div class="guest-card">
            <div class="guest-brand">SIUGOALS</div>
            <?php require BASE_PATH . '/resources/views/partials/flash.php'; ?>
            <?= $content ?>
        </div>
    </div>
</body>
</html>
