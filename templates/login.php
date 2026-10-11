<?php if (!empty($error)): ?>
    <p class="banner warn"><?= h($error) ?></p>
<?php endif; ?>
<form method="post" action="/?page=login" class="stack">
    <?= csrf_field() ?>
    <label>Email
        <input type="email" name="email" required autocomplete="username" value="<?= h($email ?? '') ?>">
    </label>
    <label>Password
        <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit">Sign in</button>
</form>
<p class="fine">No account yet? <a href="/?page=register">Create one</a>.</p>
