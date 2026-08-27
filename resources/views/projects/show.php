<?php

use App\Core\View;

?>
<div class="topbar">
    <h1><?= View::e($project['name']) ?></h1>
    <span class="badge badge-<?= View::e($project['role']) ?>"><?= View::e(ucfirst($project['role'])) ?></span>
</div>

<div class="card">
    <?php if (!empty($project['url'])): ?>
        <p class="text-muted"><?= View::e($project['url']) ?></p>
    <?php else: ?>
        <p class="text-muted">No URL set yet.</p>
    <?php endif; ?>
</div>

<div class="card empty-state">
    <h3>Readiness scanning isn't built yet</h3>
    <p>
        This is the architecture foundation for SIUGOALS: accounts, projects, membership
        and settings. The evidence engine, readiness score, and findings drawer are the
        next phase of the build.
    </p>
</div>
