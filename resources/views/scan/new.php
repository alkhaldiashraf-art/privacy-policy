<?php

use App\Core\Csrf;
use App\Core\View;

?>
<div class="topbar">
    <h1>Scan</h1>
</div>

<div class="scan-tabs" role="tablist">
    <button type="button" class="scan-tab active" data-tab="url">Scan a URL</button>
    <button type="button" class="scan-tab" data-tab="upload">Upload project code</button>
</div>

<div class="card scan-panel" data-panel="url">
    <h3>Scan the live website</h3>
    <p class="text-muted">
        We'll fetch this URL and run security, performance, SEO, mobile and accessibility
        checks against the real response — nothing simulated.
    </p>
    <form method="post" action="/projects/<?= (int) $project['id'] ?>/scan/url">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="url">Website URL</label>
            <input type="url" id="url" name="url" placeholder="https://example.com"
                value="<?= View::e($project['url'] ?? '') ?>" required maxlength="2048">
            <div class="field-hint">Defaults to this project's saved URL — change it to scan a different address.</div>
        </div>
        <button type="submit" class="btn btn-primary">Run scan</button>
    </form>
</div>

<div class="card scan-panel" data-panel="upload" hidden>
    <h3>Scan uploaded project code</h3>
    <p class="text-muted">
        Upload a .zip of your project (or a single source file). We scan it for security,
        secrets, and code-quality issues, then generate a fix prompt for every finding.
    </p>
    <form method="post" action="/projects/<?= (int) $project['id'] ?>/scan/upload" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="code_file">Project file</label>
            <input type="file" id="code_file" name="code_file" accept=".zip,.php,.js,.jsx,.ts,.tsx,.vue,.py,.html,.htm,.css" required>
            <div class="field-hint">.zip of your project, or a single source file. Max 20 MB.</div>
        </div>
        <button type="submit" class="btn btn-primary">Upload &amp; scan</button>
    </form>
</div>

<?php if (!empty($recentScans)): ?>
<div class="card">
    <h3>Recent scans</h3>
    <table class="scan-history">
        <thead>
            <tr><th>When</th><th>Type</th><th>Source</th><th>Score</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentScans as $s): ?>
            <tr>
                <td><a href="/scans/<?= (int) $s['id'] ?>"><?= View::e($s['created_at']) ?></a></td>
                <td><?= View::e(ucfirst($s['type'])) ?></td>
                <td><?= View::e($s['source_label'] ?? '') ?></td>
                <td><?= $s['score'] !== null ? (int) $s['score'] : '—' ?></td>
                <td><span class="badge badge-status-<?= View::e($s['status']) ?>"><?= View::e(ucfirst($s['status'])) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script src="/assets/js/scan.js"></script>
