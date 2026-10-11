<?php if (!empty($error)): ?>
    <p class="banner warn"><?= h($error) ?></p>
<?php endif; ?>
<form method="post" action="/?page=register" class="stack">
    <?= csrf_field() ?>
    <label>Email
        <input type="email" name="email" required autocomplete="username" value="<?= h($email ?? '') ?>">
    </label>
    <label>Password
        <input type="password" name="password" required minlength="10" autocomplete="new-password">
    </label>
    <label>Confirm password
        <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
    </label>
    <button type="submit">Create account</button>
</form>
<p class="fine">Already registered? <a href="/?page=login">Sign in</a>.</p>
