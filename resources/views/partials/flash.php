<?php

use App\Core\Session;
use App\Core\View;

$flashError = Session::flash('error');
$flashSuccess = Session::flash('success');
?>
<?php if ($flashError): ?>
    <div class="alert alert-error"><?= View::e($flashError) ?></div>
<?php endif; ?>
<?php if ($flashSuccess): ?>
    <div class="alert alert-success"><?= View::e($flashSuccess) ?></div>
<?php endif; ?>
