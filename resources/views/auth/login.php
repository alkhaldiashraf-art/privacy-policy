<?php

use App\Core\Csrf;
use App\Core\View;

?>
<h2>Log in</h2>
<form method="post" action="/login">
    <?= Csrf::field() ?>
    <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" required maxlength="255">
    </div>
    <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Log in</button>
</form>
<p class="field-hint" style="margin-top:16px;text-align:center;">
    No account yet? <a href="/signup">Sign up</a>
</p>
