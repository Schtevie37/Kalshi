<h1>Fills</h1>
<p class="lede">Recent fills on the Kalshi demo account.</p>
<?php if (!$has_key): ?>
    <p class="banner warn">Add a <a href="/?page=settings">demo API key</a> to load fills.</p>
<?php elseif (!empty($error)): ?>
    <p class="banner warn"><?= h($error) ?></p>
<?php elseif ($fills === []): ?>
    <p class="empty">No fills returned.</p>
<?php else: ?>
    <div class="table-wrap cards">
        <table>
            <thead>
            <tr>
                <th>Market</th>
                <th>Outcome</th>
                <th class="num">Count</th>
                <th class="num">Yes price</th>
                <th>Role</th>
                <th>Time</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($fills as $row): ?>
                <?php
                $tickerValue = pick($row, ['ticker', 'market_ticker']);
                $taker = $row['is_taker'] ?? null;
                if ($taker === true || $taker === 1 || $taker === '1' || $taker === 'true') {
                    $role = 'Taker';
                } elseif ($taker === false || $taker === 0 || $taker === '0' || $taker === 'false') {
                    $role = 'Maker';
                } else {
                    $role = '—';
                }
                ?>
                <tr>
                    <td data-label="Market"><?php if ($tickerValue !== '—'): ?><a href="/?page=market&amp;ticker=<?= h(rawurlencode($tickerValue)) ?>"><?= h($tickerValue) ?></a><?php else: ?>—<?php endif; ?></td>
                    <td data-label="Outcome"><?= h(pick($row, ['outcome_side', 'side'])) ?></td>
                    <td class="num" data-label="Count"><?= h(pick($row, ['count_fp', 'count'])) ?></td>
                    <td class="num" data-label="Yes price"><?= h(px(pick($row, ['yes_price_dollars', 'price'], ''))) ?></td>
                    <td data-label="Role"><?= h($role) ?></td>
                    <td data-label="Time"><?= h(iso_time(pick($row, ['created_time', 'ts'], '') ?: null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <details class="raw"><summary>Response fields</summary><pre><?= h((string) json_encode($fills, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></details>
<?php endif; ?>
