<h1>Portfolio</h1>
<p class="lede">Balances, open positions, and resting orders from your Kalshi demo account.</p>

<?php if (!$has_key): ?>
    <p class="banner warn">No demo API key is saved. You can still browse markets. <a href="/?page=settings">Add a key</a> to see this account.</p>
<?php endif; ?>

<?php foreach ($errors as $message): ?>
    <p class="banner warn"><?= h($message) ?></p>
<?php endforeach; ?>

<?php if (is_array($balance)): ?>
    <section class="stats">
        <article>
            <p>Available</p>
            <strong><?= h(money($balance['balance_dollars'] ?? null, $balance['balance'] ?? null)) ?></strong>
        </article>
        <article>
            <p>Portfolio value</p>
            <strong><?= h(money($balance['portfolio_value_dollars'] ?? null, $balance['portfolio_value'] ?? null)) ?></strong>
        </article>
        <article>
            <p>Updated</p>
            <strong class="small"><?= h(iso_time($balance['updated_ts'] ?? null)) ?></strong>
        </article>
    </section>
<?php endif; ?>

<section class="panel">
    <header class="panel-head">
        <h2>Positions</h2>
    </header>
    <?php if ($positions === []): ?>
        <p class="empty">No unsettled positions returned.</p>
    <?php else: ?>
        <div class="table-wrap cards">
            <table>
                <thead>
                <tr>
                    <th>Market</th>
                    <th class="num">Position</th>
                    <th class="num">Exposure</th>
                    <th class="num">Realized</th>
                    <th class="num">Fees</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($positions as $row): ?>
                    <?php $tickerValue = pick($row, ['ticker', 'market_ticker']); ?>
                    <tr>
                        <td data-label="Market"><?php if ($tickerValue !== '—'): ?><a href="/?page=market&amp;ticker=<?= h(rawurlencode($tickerValue)) ?>"><?= h($tickerValue) ?></a><?php else: ?>—<?php endif; ?></td>
                        <td class="num" data-label="Position"><?= h(pick($row, ['position_fp', 'position'])) ?></td>
                        <td class="num" data-label="Exposure"><?= h(px(pick($row, ['market_exposure_dollars', 'market_exposure'], ''))) ?></td>
                        <td class="num" data-label="Realized"><?= h(px(pick($row, ['realized_pnl_dollars', 'realized_pnl'], ''))) ?></td>
                        <td class="num" data-label="Fees"><?= h(px(pick($row, ['fees_paid_dollars', 'fees_paid'], ''))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="fine">A positive position is YES contracts. A negative position is NO contracts.</p>
        <details class="raw"><summary>Response fields</summary><pre><?= h((string) json_encode($positions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details>
    <?php endif; ?>
</section>

<section class="panel">
    <header class="panel-head">
        <h2>Resting orders</h2>
        <a href="/?page=orders">All orders</a>
    </header>
    <?php if ($orders === []): ?>
        <p class="empty">No resting orders returned.</p>
    <?php else: ?>
        <?php $rows = $orders; require __DIR__ . '/_orders_table.php'; ?>
    <?php endif; ?>
</section>
