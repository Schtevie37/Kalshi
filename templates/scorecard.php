<h1>Scorecard</h1>

<section class="panel">
    <header class="panel-head"><h2>Add a position</h2></header>
    <form method="post" action="/?page=scorecard" class="stack" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>Ticker or Kalshi link
            <input name="ticker" required spellcheck="false" autocomplete="off" value="">
        </label>
        <label>Side
            <select name="outcome" required>
                <option value="yes">Yes</option>
                <option value="no">No</option>
            </select>
        </label>
        <label>Price you paid
            <input name="pay_price" required inputmode="decimal" spellcheck="false" autocomplete="off" value="">
        </label>
        <label>Contracts
            <input name="contract_count" required inputmode="decimal" spellcheck="false" autocomplete="off" value="1">
        </label>
        <button type="submit">Save position</button>
    </form>
</section>

<?php
$summary = (isset($totals) && is_array($totals)) ? $totals : null;
if ($summary === null && $positions !== []) {
    try {
        $summary = Scorecard::totals($positions);
    } catch (Throwable) {
        $summary = null;
    }
}
$asPercent = static function (mixed $value): string {
    if (!is_string($value) || !preg_match('/^-?\d+\.\d$/', $value)) {
        return '—';
    }
    $negative = str_starts_with($value, '-');

    return ($negative ? '−' : '') . ltrim($value, '-') . '%';
};
?>
<?php if (is_array($summary)): ?>
    <section class="stats">
        <article>
            <p>Orders</p>
            <strong><?= h((string) $summary['count']) ?></strong>
        </article>
        <article>
            <p>Record</p>
            <strong><?= $summary['settled'] > 0 ? h($summary['wins'] . '–' . $summary['losses']) : '—' ?></strong>
        </article>
        <article>
            <p>Success</p>
            <strong><?= h($asPercent($summary['success_rate'])) ?></strong>
        </article>
        <article>
            <p>Placed</p>
            <strong><?= h(money($summary['placed'])) ?></strong>
        </article>
        <article>
            <p>Still open</p>
            <strong><?= h(money($summary['open_stake'])) ?></strong>
        </article>
        <article>
            <p>Paid out</p>
            <strong><?= h(money($summary['payout'])) ?></strong>
        </article>
        <article>
            <p>Earned</p>
            <strong><?= h(money($summary['profit'])) ?></strong>
        </article>
        <article>
            <p>Average return</p>
            <strong><?= h($asPercent($summary['average_return'])) ?></strong>
        </article>
    </section>
<?php endif; ?>

<?php $showOlder = ($show ?? '') === 'older'; ?>
<section class="panel positions-panel">
    <header class="panel-head">
        <div>
            <h2>Positions</h2>
            <p class="fine"><?= $showOlder ? 'Closed more than three days ago.' : 'Open positions, and ones that settled in the last three days.' ?></p>
        </div>
        <nav class="tabs" aria-label="Positions">
            <a class="<?= $showOlder ? '' : 'active' ?>" href="/?page=scorecard">Recent</a>
            <a class="<?= $showOlder ? 'active' : '' ?>" href="/?page=scorecard&amp;show=older">Older</a>
        </nav>
    </header>
    <?php if ($positions === []): ?>
        <p class="empty"><?php if ((int) ($position_count ?? 0) === 0): ?>No positions yet.<?php elseif ($showOlder): ?>No closed positions older than three days.<?php else: ?>Nothing open or settled in the last three days.<?php endif; ?></p>
    <?php else: ?>
        <div class="table-wrap positions">
            <table>
                <thead>
                <tr>
                    <th class="market">Market</th>
                    <th>Result</th>
                    <th class="num">Profit</th>
                    <th>Side</th>
                    <th class="num">Price</th>
                    <th class="num">Current</th>
                    <th class="num">Contracts</th>
                    <th class="num">Cost</th>
                    <th class="num">Payout</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($positions as $position): ?>
                    <?php
                    $score = is_array($position['score'] ?? null) ? $position['score'] : null;
                    $cost = $score['cost'] ?? Scorecard::stake((string) $position['pay_price'], (string) $position['contract_count']);
                    $settled = $score !== null;
                    ?>
                    <tr class="<?= $settled ? 'is-settled' : 'is-open' ?><?= !empty($position['check_error']) ? ' has-error' : '' ?>">
                        <td class="market" data-label="Market">
                            <div class="market-box">
                                <div class="market-sizer" aria-hidden="true">Will the Republican party win the governorship in Texas</div>
                                <div class="market-scroll">
                                    <div class="market-track">
                                        <?php if (!empty($position['market_title'])): ?><span class="market-name"><?= h($position['market_title']) ?></span><?php endif; ?>
                                        <a class="ticker" href="<?= h(Scorecard::marketLink((string) $position['ticker'])) ?>" target="_blank" rel="noopener noreferrer"><?= h($position['ticker']) ?></a>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="cell-result" data-label="Result">
                            <?php if ($settled): ?>
                                <span class="pill <?= $score['won'] ? 'yes' : 'no' ?>"><?= $score['won'] ? 'Won' : 'Lost' ?></span>
                            <?php else: ?>
                                Open
                                <?php if (!empty($position['check_error'])): ?>
                                    <span class="ticker"><?= h($position['check_error']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="num cell-profit" data-label="Profit"><?= $settled ? h(money($score['profit'])) : '—' ?></td>
                        <td class="cell-side" data-label="Side"><span class="pill <?= $position['outcome'] === 'no' ? 'no' : 'yes' ?>"><?= h(strtoupper((string) $position['outcome'])) ?></span></td>
                        <td class="num cell-price" data-label="Price"><?= h(px((string) $position['pay_price'])) ?></td>
                        <td class="num cell-current" data-label="Current"><?= is_string($position['current_price'] ?? null) ? h(px($position['current_price'])) : '—' ?></td>
                        <td class="num cell-count" data-label="Contracts"><?= h((string) $position['contract_count']) ?></td>
                        <td class="num cell-cost" data-label="Cost"><?= h(money($cost)) ?></td>
                        <td class="num cell-payout" data-label="Payout"><?= $settled ? h(money($score['payout'])) : '—' ?></td>
                        <td class="cell-remove" data-label="">
                            <form method="post" action="/?page=scorecard" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <?php if ($showOlder): ?><input type="hidden" name="show" value="older"><?php endif; ?>
                                <input type="hidden" name="id" value="<?= h((string) $position['id']) ?>">
                                <button type="submit" class="quiet">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
