<?php

use App\Core\View;

?>
<div class="topbar">
    <h1>Projects</h1>
    <a class="btn btn-primary" href="/projects/create">New project</a>
</div>

<?php if ($projects === []): ?>
    <div class="card empty-state">
        <h3>No projects yet</h3>
        <p>Create a project to start tracking its production readiness.</p>
        <a class="btn btn-primary" href="/projects/create">Create your first project</a>
    </div>
<?php else: ?>
    <div class="project-list">
        <?php foreach ($projects as $project): ?>
            <a class="project-tile" href="/projects/<?= (int) $project['id'] ?>">
                <div class="card">
                    <h3 style="margin-bottom:4px;"><?= View::e($project['name']) ?></h3>
                    <?php if (!empty($project['url'])): ?>
                        <p class="text-muted" style="margin-bottom:8px;"><?= View::e($project['url']) ?></p>
                    <?php endif; ?>
                    <span class="badge badge-<?= View::e($project['role']) ?>"><?= View::e(ucfirst($project['role'])) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
