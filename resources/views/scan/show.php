<?php

use App\Core\View;

$severityLabels = ['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'info' => 'Info'];
$counts = array_fill_keys(array_keys($severityLabels), 0);
foreach ($findings as $f) {
    $counts[$f['severity']] = ($counts[$f['severity']] ?? 0) + 1;
}
?>
<div class="topbar">
    <h1>Scan report</h1>
    <a class="btn btn-outline" href="/projects/<?= (int) $project['id'] ?>/scan">New scan</a>
</div>

<div class="card">
    <p class="text-muted">
        <?= View::e(ucfirst($scan['type'])) ?> scan of
        <strong><?= View::e($scan['type'] === 'url' ? $scan['target_url'] : $scan['source_label']) ?></strong>
        — <?= View::e($scan['created_at']) ?>
    </p>

    <?php if ($scan['status'] === 'failed'): ?>
        <div class="alert alert-error">Scan failed: <?= View::e($scan['error_message']) ?></div>
    <?php else: ?>
        <?php
        $scoreValue = (int) $scan['score'];
        $scoreTier = $scoreValue >= 80 ? 'good' : ($scoreValue >= 50 ? 'warn' : 'bad');
        ?>
        <div class="score-row">
            <div class="score-gauge score-<?= $scoreTier ?>">
                <div class="score-number"><?= $scoreValue ?></div>
                <div class="score-label">/ 100</div>
            </div>
            <div class="severity-counts">
                <?php foreach ($severityLabels as $sev => $label): ?>
                    <span class="badge badge-severity-<?= $sev ?>"><?= $counts[$sev] ?> <?= $label ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <p><?= View::e($scan['summary']) ?></p>

        <?php if (!empty($scan['comprehensive_fix_prompt'])): ?>
            <div class="finding-card copy-group">
                <div class="finding-head">
                    <h3>Comprehensive fix prompt</h3>
                    <span class="badge badge-source-<?= View::e($scan['comprehensive_fix_prompt_source']) ?>">
                        <?= $scan['comprehensive_fix_prompt_source'] === 'ai' ? 'AI-generated' : 'Template' ?>
                    </span>
                </div>
                <p class="text-muted">Paste this into your AI coding assistant to fix every finding below in one pass.</p>
                <textarea class="fix-prompt-text" readonly rows="6"><?= View::e($scan['comprehensive_fix_prompt']) ?></textarea>
                <button type="button" class="btn btn-primary copy-btn">Copy comprehensive prompt</button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($scan['status'] !== 'failed'): ?>
    <?php if ($findings === []): ?>
        <div class="card empty-state">
            <h3>No issues found</h3>
            <p>This scan didn't turn up any findings in the categories we checked.</p>
        </div>
    <?php else: ?>
        <?php foreach ($findings as $finding): ?>
            <div class="card finding-card copy-group">
                <div class="finding-head">
                    <span class="badge badge-severity-<?= View::e($finding['severity']) ?>"><?= View::e(ucfirst($finding['severity'])) ?></span>
                    <h3><?= View::e($finding['title']) ?></h3>
                    <span class="finding-category"><?= View::e($finding['category']) ?></span>
                </div>
                <?php if (!empty($finding['file_path'])): ?>
                    <p class="text-muted finding-location">
                        <?= View::e($finding['file_path']) ?><?= $finding['line_number'] ? ':' . (int) $finding['line_number'] : '' ?>
                    </p>
                <?php endif; ?>
                <p><?= View::e($finding['description']) ?></p>
                <?php if (!empty($finding['recommendation'])): ?>
                    <p class="text-muted"><strong>Recommendation:</strong> <?= View::e($finding['recommendation']) ?></p>
                <?php endif; ?>

                <?php if (!empty($finding['fix_prompt'])): ?>
                    <div class="finding-head">
                        <span class="badge badge-source-<?= View::e($finding['fix_prompt_source']) ?>">
                            <?= $finding['fix_prompt_source'] === 'ai' ? 'AI-generated fix prompt' : 'Template fix prompt' ?>
                        </span>
                    </div>
                    <textarea class="fix-prompt-text" readonly rows="3"><?= View::e($finding['fix_prompt']) ?></textarea>
                    <button type="button" class="btn btn-outline copy-btn">Copy fix prompt</button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<script src="/assets/js/scan.js"></script>
