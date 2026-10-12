<h1>Scorecard</h1>

<section class="panel">
    <header class="panel-head"><h2>Add a position</h2></header>
    <form method="post" action="/?page=scorecard" class="stack" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>Ticker
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

<section class="panel">
    <header class="panel-head"><h2>Positions</h2></header>
    <?php if ($positions === []): ?>
        <p class="empty">No positions yet.</p>
    <?php else: ?>
        <div class="table-wrap positions">
            <table>
                <thead>
                <tr>
                    <th>When</th>
                    <th>Market</th>
                    <th>Side</th>
                    <th class="num">Price</th>
                    <th class="num">Contracts</th>
                    <th class="num">Cost</th>
                    <th>Result</th>
                    <th class="num">Payout</th>
                    <th class="num">Profit</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($positions as $position): ?>
                    <?php
                    $score = is_array($position['score'] ?? null) ? $position['score'] : null;
                    $cost = $score['cost'] ?? Scorecard::stake((string) $position['pay_price'], (string) $position['contract_count']);
                    $settled = $score !== null;
                    ?>
                    <tr>
                        <td data-label="When"><?= h(iso_time($position['created_at'], 'America/New_York')) ?></td>
                        <td data-label="Market"><span class="ticker"><?= h($position['ticker']) ?></span></td>
                        <td data-label="Side"><span class="pill <?= $position['outcome'] === 'no' ? 'no' : 'yes' ?>"><?= h(strtoupper((string) $position['outcome'])) ?></span></td>
                        <td class="num" data-label="Price"><?= h(px((string) $position['pay_price'])) ?></td>
                        <td class="num" data-label="Contracts"><?= h((string) $position['contract_count']) ?></td>
                        <td class="num" data-label="Cost"><?= h(money($cost)) ?></td>
                        <td data-label="Result">
                            <?php if ($settled): ?>
                                <span class="pill <?= $score['won'] ? 'yes' : 'no' ?>"><?= $score['won'] ? 'Won' : 'Lost' ?></span>
                                <span class="ticker"><?= h(strtoupper((string) $position['market_result'])) ?></span>
                            <?php else: ?>
                                Open
                                <?php if (!empty($position['check_error'])): ?>
                                    <span class="ticker"><?= h($position['check_error']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="num" data-label="Payout"><?= $settled ? h(money($score['payout'])) : '—' ?></td>
                        <td class="num" data-label="Profit"><?= $settled ? h(money($score['profit'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
