<?php

use App\Core\Csrf;
use App\Core\View;

?>
<div class="topbar">
    <h1>New project</h1>
</div>
<div class="card card-narrow">
    <form method="post" action="/projects">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="name">Project name</label>
            <input type="text" id="name" name="name" value="<?= View::e($old['name'] ?? '') ?>" required maxlength="255">
        </div>
        <div class="field">
            <label for="url">URL</label>
            <input type="url" id="url" name="url" value="<?= View::e($old['url'] ?? '') ?>" placeholder="https://example.com">
            <div class="field-hint">Optional for now — you can add it later in Settings.</div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Create project</button>
    </form>
</div>
