<?php

use App\Core\Csrf;
use App\Core\View;

$durationSeconds = max(0, strtotime($sdkSession['last_seen_at']) - strtotime($sdkSession['started_at']));
?>
<div class="topbar">
    <h1>Session <?= View::e($sdkSession['session_uid']) ?></h1>
    <a class="btn btn-outline" href="/projects/<?= (int) $project['id'] ?>/sessions">Back to sessions</a>
</div>

<div class="card">
    <div class="session-meta-grid">
        <div><span class="text-muted">Environment</span><br><?= View::e($sdkSession['os'] ?? 'Unknown') ?> · <?= View::e($sdkSession['browser'] ?? 'Unknown') ?></div>
        <div><span class="text-muted">Country</span><br><?= View::flag($sdkSession['country_code']) ?> <?= View::e($sdkSession['country_code'] ?? 'Unknown') ?></div>
        <div><span class="text-muted">Duration</span><br><?= (int) floor($durationSeconds / 60) ?>m <?= $durationSeconds % 60 ?>s</div>
        <div><span class="text-muted">Started</span><br><?= View::e($sdkSession['started_at']) ?></div>
    </div>

    <form method="post" action="/projects/<?= (int) $project['id'] ?>/sessions/<?= (int) $sdkSession['id'] ?>/identity" class="identity-form">
        <?= Csrf::field() ?>
        <div class="field">
            <label for="identity">Identity</label>
            <input type="text" id="identity" name="identity" value="<?= View::e($sdkSession['identity'] ?? '') ?>" placeholder="email or user ID" maxlength="255">
            <div class="field-hint">Link this anonymous session to a known user, or call <code>siugoals.identify(id)</code> from the SDK.</div>
        </div>
        <button type="submit" class="btn btn-outline">Connect identity</button>
    </form>
</div>

<?php
$errors = array_values(array_filter($events, fn ($e) => $e['type'] === 'error'));
$signals = array_values(array_filter($events, fn ($e) => $e['type'] === 'signal'));
?>

<div class="card">
    <h3>Errors (<?= count($errors) ?>)</h3>
    <?php if ($errors === []): ?>
        <p class="text-muted">No runtime errors were captured in this session.</p>
    <?php else: ?>
        <?php foreach ($errors as $error): ?>
            <div class="finding-card copy-group">
                <div class="finding-head">
                    <span class="badge badge-severity-high">Error</span>
                    <h3><?= View::e($error['name'] ?: 'Unhandled runtime error') ?></h3>
                    <span class="finding-category"><?= View::e($error['occurred_at']) ?></span>
                </div>
                <p><?= View::e($error['message']) ?></p>
                <?php if (!empty($error['stack'])): ?>
                    <details>
                        <summary class="text-muted">Stack trace</summary>
                        <pre class="stack-trace"><?= View::e($error['stack']) ?></pre>
                    </details>
                <?php endif; ?>
                <?php if (!empty($error['fix_prompt'])): ?>
                    <div class="finding-head">
                        <span class="badge badge-source-<?= View::e($error['fix_prompt_source']) ?>">
                            <?= $error['fix_prompt_source'] === 'ai' ? 'AI-generated fix prompt' : 'Template fix prompt' ?>
                        </span>
                    </div>
                    <textarea class="fix-prompt-text" readonly rows="3"><?= View::e($error['fix_prompt']) ?></textarea>
                    <button type="button" class="btn btn-outline copy-btn">Copy fix prompt</button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Signals (<?= count($signals) ?>)</h3>
    <?php if ($signals === []): ?>
        <p class="text-muted">No custom signals were tracked in this session.</p>
    <?php else: ?>
        <table class="scan-history">
            <thead><tr><th>Name</th><th>Data</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($signals as $signal): ?>
                <tr>
                    <td><?= View::e($signal['name'] ?? '') ?></td>
                    <td class="text-muted"><?= View::e($signal['message'] ?? '') ?></td>
                    <td class="text-muted"><?= View::e($signal['occurred_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script src="/assets/js/scan.js"></script>
