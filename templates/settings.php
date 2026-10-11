<h1>Demo keys</h1>
<p class="lede">Create a key while logged into <a href="https://demo.kalshi.co/" rel="noreferrer">demo.kalshi.co</a>, under Account &amp; security, then API Keys. Live keys from kalshi.com will not work, and this desk will not send them anywhere.</p>

<?php if (is_array($saved)): ?>
    <section class="panel saved-key">
        <p class="kicker">Saved key</p>
        <p><strong><?= h($saved['key_id']) ?></strong></p>
        <p class="fine"><?= h($saved['key_type'] === 'ed25519' ? 'Ed25519' : 'RSA') ?> · updated <?= h(iso_time($saved['updated_at'])) ?></p>
        <div class="row">
            <form method="post" action="/?page=settings">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="test">
                <button type="submit">Check demo balance</button>
            </form>
            <form method="post" action="/?page=settings">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="quiet">Remove key</button>
            </form>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <header class="panel-head"><h2><?= is_array($saved) ? 'Replace key' : 'Add a key' ?></h2></header>
    <form method="post" action="/?page=settings" class="stack" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <label>API key id
            <input name="key_id" required spellcheck="false" autocomplete="off" placeholder="a952bcbe-ec3b-4b5b-b8f9-11dae589608c" value="">
        </label>
        <label>Private key PEM
            <textarea name="private_key" required spellcheck="false" autocomplete="off" rows="8" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
        </label>
        <button type="submit">Save encrypted key</button>
        <p class="fine">The private key is encrypted before it is written to MySQL. This page will not show it again.</p>
    </form>
</section>
