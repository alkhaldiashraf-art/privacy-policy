<?php

use App\Core\View;

?>
<h2>Installed</h2>
<p class="text-muted">Database is ready.</p>

<?php if ($applied === []): ?>
    <p class="text-muted">No pending migrations were found (database was already up to date).</p>
<?php else: ?>
    <p class="text-muted">Applied:</p>
    <ul>
        <?php foreach ($applied as $name): ?>
            <li><?= View::e($name) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<a class="btn btn-primary btn-block" href="/signup">Create your account</a>
