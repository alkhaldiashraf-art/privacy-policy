<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\View;

$user = Auth::user();
$activeNav = $activeNav ?? '';
$project = $project ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php require BASE_PATH . '/resources/views/partials/head.php'; ?>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-brand">SIUGOALS</div>

            <?php if ($project): ?>
                <div class="sidebar-section-label">Project</div>
                <div class="sidebar-project-name"><?= View::e($project['name']) ?></div>
            <?php endif; ?>

            <div class="sidebar-section-label">Account</div>
            <a class="sidebar-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="/dashboard">Projects</a>

            <?php if ($project): ?>
                <div class="sidebar-section-label">Project</div>
                <a class="sidebar-link <?= $activeNav === 'overview' ? 'active' : '' ?>" href="/projects/<?= (int) $project['id'] ?>">Overview</a>
                <a class="sidebar-link <?= $activeNav === 'settings' ? 'active' : '' ?>" href="/projects/<?= (int) $project['id'] ?>/settings">Settings</a>
            <?php endif; ?>

            <div class="sidebar-footer">
                <div><?= View::e($user['name'] ?? '') ?></div>
                <form method="post" action="/logout" class="sidebar-logout-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-outline btn-block">Log out</button>
                </form>
            </div>
        </aside>
        <main class="main">
            <?php require BASE_PATH . '/resources/views/partials/flash.php'; ?>
            <?= $content ?>
        </main>
    </div>
</body>
</html>
