<?php

use App\Core\Csrf;
use App\Core\View;

?>
<h2>Create your account</h2>
<form method="post" action="/signup">
    <?= Csrf::field() ?>
    <div class="field">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= View::e($old['name'] ?? '') ?>" required maxlength="255">
    </div>
    <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" required maxlength="255">
    </div>
    <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required minlength="10">
        <div class="field-hint">At least 10 characters.</div>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Sign up</button>
</form>
<p class="field-hint mt-md text-center">
    Already have an account? <a href="/login">Log in</a>
</p>
