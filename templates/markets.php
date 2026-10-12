<h1>Markets</h1>
<p class="lede"><?= h($note !== '' ? $note : 'Open markets on the Kalshi demo exchange.') ?></p>

<form method="get" action="/" class="search">
    <input type="hidden" name="page" value="markets">
    <label class="grow">Search
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Ticker, title, or event">
    </label>
    <button type="submit">Search</button>
</form>

<?php if (!empty($error)): ?>
    <p class="banner warn"><?= h($error) ?></p>
<?php elseif ($markets === []): ?>
    <p class="empty">No open markets matched.</p>
<?php else: ?>
    <div class="table-wrap cards">
        <table>
            <thead>
            <tr>
                <th>Market</th>
                <th class="num">Yes bid</th>
                <th class="num">Yes ask</th>
                <th class="num">Last</th>
                <th class="num">Volume</th>
                <th>Closes</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($markets as $market): ?>
                <?php $tickerValue = (string) ($market['ticker'] ?? ''); ?>
                <tr>
                    <td data-label="Market">
                        <a href="/?page=market&amp;ticker=<?= h(rawurlencode($tickerValue)) ?>"><?= h($market['title'] ?? $tickerValue) ?></a>
                        <span class="ticker"><?= h($tickerValue) ?></span>
                    </td>
                    <td class="num" data-label="Yes bid"><?= h(px((string) ($market['yes_bid_dollars'] ?? ''))) ?></td>
                    <td class="num" data-label="Yes ask"><?= h(px((string) ($market['yes_ask_dollars'] ?? ''))) ?></td>
                    <td class="num" data-label="Last"><?= h(px((string) ($market['last_price_dollars'] ?? ''))) ?></td>
                    <td class="num" data-label="Volume"><?= h((string) ($market['volume_fp'] ?? '—')) ?></td>
                    <td data-label="Closes"><?= h(iso_time($market['close_time'] ?? null)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($next_cursor !== ''): ?>
        <p class="pager"><a href="/?<?= h(http_build_query(['page' => 'markets', 'q' => $q, 'cursor' => $next_cursor])) ?>">Next page</a></p>
    <?php endif; ?>
<?php endif; ?>
