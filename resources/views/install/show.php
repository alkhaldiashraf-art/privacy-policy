<?php

use App\Core\Csrf;
use App\Core\View;

?>
<h2>Install SIUGOALS</h2>
<p class="text-muted">
    This runs the database migrations against the credentials in your <code>.env</code> file.
    It can only be run once — this endpoint locks itself immediately after a successful install.
</p>

<?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="post" action="/install">
    <?= Csrf::field() ?>
    <button type="submit" class="btn btn-primary btn-block">Run installation</button>
</form>
