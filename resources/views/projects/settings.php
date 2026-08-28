<?php

use App\Core\Csrf;
use App\Core\View;
use App\Models\ProjectMember;

$canEdit = ProjectMember::canEdit($project['role']);
?>
<div class="topbar">
    <h1>Settings</h1>
</div>

<div class="card" style="max-width:560px;">
    <h3>General</h3>
    <form method="post" action="/projects/<?= (int) $project['id'] ?>/settings">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="name">Project name</label>
            <input type="text" id="name" name="name" value="<?= View::e($project['name']) ?>" required maxlength="255" <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <div class="field">
            <label for="url">URL</label>
            <input type="url" id="url" name="url" value="<?= View::e($project['url'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <div class="field">
            <label for="tagline">Tagline</label>
            <input type="text" id="tagline" name="tagline" value="<?= View::e($profile['tagline'] ?? '') ?>" maxlength="255" <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <?php if ($canEdit): ?>
            <button type="submit" class="btn btn-primary">Save</button>
        <?php else: ?>
            <p class="field-hint">Your role (<?= View::e($project['role']) ?>) can view but not edit these settings.</p>
        <?php endif; ?>
    </form>
</div>
