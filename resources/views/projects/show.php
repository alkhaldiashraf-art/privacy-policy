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
    <h3>Run a scan</h3>
    <p>
        Scan this project's live URL, or upload its source code, to get a real,
        evidence-based report with a ready-to-paste fix prompt for every finding.
    </p>
    <a class="btn btn-primary" href="/projects/<?= (int) $project['id'] ?>/scan">Go to Scan</a>
</div>
