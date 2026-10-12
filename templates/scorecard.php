<h1>Scorecard</h1>
<p class="lede">Record a live-market position you already have. This page reads the public result later and does not send an order or your demo key.</p>

<section class="panel">
    <header class="panel-head"><h2>Add a position</h2></header>
    <form method="post" action="/?page=scorecard" class="stack" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>Ticker
            <input name="ticker" required spellcheck="false" autocomplete="off" placeholder="KXNFLTD-26OCT11BALATL-ATLBROBINSON7-1" value="">
        </label>
        <label>Side
            <select name="outcome" required>
                <option value="yes">Yes</option>
                <option value="no">No</option>
            </select>
        </label>
        <label>Price you paid
            <input name="pay_price" required inputmode="decimal" spellcheck="false" autocomplete="off" placeholder="0.40">
        </label>
        <label>Contracts
            <input name="contract_count" required inputmode="decimal" spellcheck="false" autocomplete="off" value="1">
        </label>
        <button type="submit">Save position</button>
        <p class="fine">Use dollars, so 40 cents is 0.40. Ten Yes contracts at 0.40 cost $4. A Yes result pays $10. A No result pays $0. Fees are left out.</p>
    </form>
</section>

<section class="panel">
    <header class="panel-head"><h2>Positions</h2></header>
    <?php if ($positions === []): ?>
        <p class="empty">No positions yet.</p>
    <?php else: ?>
        <div class="table-wrap">
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
                        <td><?= h(iso_time($position['created_at'])) ?></td>
                        <td><span class="ticker"><?= h($position['ticker']) ?></span></td>
                        <td><span class="pill <?= $position['outcome'] === 'no' ? 'no' : 'yes' ?>"><?= h(strtoupper((string) $position['outcome'])) ?></span></td>
                        <td class="num"><?= h(px((string) $position['pay_price'])) ?></td>
                        <td class="num"><?= h((string) $position['contract_count']) ?></td>
                        <td class="num"><?= h(money($cost)) ?></td>
                        <td>
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
                        <td class="num"><?= $settled ? h(money($score['payout'])) : '—' ?></td>
                        <td class="num"><?= $settled ? h(money($score['profit'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="fine">An open row is checked again the next time you open this page. A won or lost row is not checked again.</p>
    <?php endif; ?>
</section>
