<?php

use App\Core\Config;
use App\Core\View;

$appUrl = Config::appUrl();
$snippet = '<script src="' . $appUrl . '/assets/js/sdk.js" data-key="' . $sdkPublicKey . '"></script>';
$totalPages = max(1, (int) ceil($total / $perPage));
?>
<div class="topbar">
    <h1>Sessions</h1>
</div>

<div class="card copy-group">
    <h3>Connect the runtime SDK</h3>
    <p class="text-muted">Paste this before <code>&lt;/body&gt;</code> on the pages you want to monitor.</p>
    <textarea class="fix-prompt-text" readonly rows="2"><?= View::e($snippet) ?></textarea>
    <button type="button" class="btn btn-outline copy-btn">Copy snippet</button>
</div>

<div class="card">
    <form method="get" action="/projects/<?= (int) $project['id'] ?>/sessions" class="sessions-filter-bar">
        <select name="range" class="filter-select">
            <option value="24h" <?= $range === '24h' ? 'selected' : '' ?>>Last 24 hours</option>
            <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>Last 7 days</option>
            <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>Last 30 days</option>
            <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>All time</option>
        </select>
        <input type="text" name="q" placeholder="Search identity or session ID..." value="<?= View::e($search) ?>">
        <button type="submit" class="btn btn-outline">Filter</button>
    </form>

    <?php if ($sessions === []): ?>
        <div class="empty-state">
            <h3>No sessions yet</h3>
            <p>Once the snippet above is live on your site, sessions will start appearing here.</p>
        </div>
    <?php else: ?>
        <table class="scan-history sessions-table">
            <thead>
                <tr>
                    <th>User / Started</th>
                    <th>Environment</th>
                    <th>Country</th>
                    <th>Duration</th>
                    <th>Activity</th>
                    <th>Errors</th>
                    <th>Signals</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $s):
                $label = $s['identity'] ?: $s['session_uid'];
                $avatarSeed = array_sum(array_map('ord', str_split(substr($label, 0, 8)))) % 10;
                $durationSeconds = max(0, strtotime($s['last_seen_at']) - strtotime($s['started_at']));
                $activityLevel = min(10, (int) $s['error_count'] + (int) $s['signal_count']);
            ?>
                <tr>
                    <td>
                        <a href="/projects/<?= (int) $project['id'] ?>/sessions/<?= (int) $s['id'] ?>" class="session-row-link">
                            <span class="avatar-dot">#<?= $avatarSeed ?></span>
                            <span>
                                <strong><?= View::e(substr($label, 0, 24)) ?></strong><br>
                                <span class="text-muted"><?= View::e($s['started_at']) ?></span>
                            </span>
                        </a>
                    </td>
                    <td><?= View::e($s['os'] ?? 'Unknown') ?><br><span class="text-muted"><?= View::e($s['browser'] ?? 'Unknown') ?></span></td>
                    <td><?= View::flag($s['country_code']) ?></td>
                    <td><?= (int) floor($durationSeconds / 60) ?>m <?= $durationSeconds % 60 ?>s</td>
                    <td><span class="activity-bar activity-bar-<?= $activityLevel ?>"></span></td>
                    <td><?= $s['error_count'] > 0 ? (int) $s['error_count'] : '—' ?></td>
                    <td><?= $s['signal_count'] > 0 ? (int) $s['signal_count'] : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="pagination">
            <span class="text-muted"><?= $total ?> session(s) — page <?= $page ?> of <?= $totalPages ?></span>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                    <a class="btn btn-outline" href="?range=<?= View::e($range) ?>&q=<?= urlencode($search) ?>&page=<?= $page - 1 ?>">Previous</a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-outline" href="?range=<?= View::e($range) ?>&q=<?= urlencode($search) ?>&page=<?= $page + 1 ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="/assets/js/scan.js"></script>
