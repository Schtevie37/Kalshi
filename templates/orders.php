<h1>Orders</h1>
<p class="lede">Orders on the Kalshi demo account.</p>
<nav class="tabs">
    <?php foreach (['resting' => 'Resting', 'executed' => 'Executed', 'canceled' => 'Canceled', 'all' => 'All'] as $key => $label): ?>
        <a class="<?= $status === $key ? 'active' : '' ?>" href="/?page=orders&amp;status=<?= h($key) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (!$has_key): ?>
    <p class="banner warn">Add a <a href="/?page=settings">demo API key</a> to load orders.</p>
<?php elseif (!empty($error)): ?>
    <p class="banner warn"><?= h($error) ?></p>
<?php elseif ($orders === []): ?>
    <p class="empty">No orders in this view.</p>
<?php else: ?>
    <?php $rows = $orders; require __DIR__ . '/_orders_table.php'; ?>
    <details class="raw"><summary>Response fields</summary><pre><?= h((string) json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details>
<?php endif; ?>
