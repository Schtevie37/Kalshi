<?php if (!empty($error) || !is_array($market)): ?>
    <h1>Market</h1>
    <p class="banner warn"><?= h($error ?: 'That market is not available.') ?></p>
    <p><a href="/?page=markets">Back to markets</a></p>
<?php else: ?>
    <?php
    $yesLevels = [];
    $noLevels = [];
    foreach ($book['yes_dollars'] as $level) {
        if (is_array($level) && isset($level[0], $level[1])) {
            $yesLevels[] = [(string) $level[0], (string) $level[1]];
        }
    }
    foreach ($book['no_dollars'] as $level) {
        if (is_array($level) && isset($level[0], $level[1])) {
            $noLevels[] = [(string) $level[0], (string) $level[1]];
        }
    }
    $yesLevels = array_reverse($yesLevels);
    $noLevels = array_reverse($noLevels);
    $preview = null;
    if (($ticket['price'] ?? '') !== '') {
        try {
            $preview = Pricing::order((string) $ticket['outcome'], (string) $ticket['price'], (string) ($ticket['count'] ?: '1'), $market);
        } catch (InvalidArgumentException) {
            $preview = null;
        }
    }
    ?>
    <p class="kicker"><?= h($market['event_ticker'] ?? '') ?></p>
    <h1><?= h($market['title'] ?? $market['ticker']) ?></h1>
    <p class="lede"><?= h($market['yes_sub_title'] ?? 'YES') ?> / <?= h($market['no_sub_title'] ?? 'NO') ?></p>

    <section class="stats">
        <article>
            <p>Yes bid</p>
            <strong><?= h(px((string) ($market['yes_bid_dollars'] ?? ''))) ?></strong>
        </article>
        <article>
            <p>Yes ask</p>
            <strong><?= h(px((string) ($market['yes_ask_dollars'] ?? ''))) ?></strong>
        </article>
        <article>
            <p>Last</p>
            <strong><?= h(px((string) ($market['last_price_dollars'] ?? ''))) ?></strong>
        </article>
        <article>
            <p>Volume</p>
            <strong><?= h((string) ($market['volume_fp'] ?? '—')) ?></strong>
        </article>
    </section>

    <div class="split">
        <div>
            <?php if (!empty($form_error)): ?>
                <p class="banner warn"><?= h($form_error) ?></p>
            <?php endif; ?>
            <section class="panel">
                <header class="panel-head"><h2>Order book</h2></header>
                <div class="books">
                    <div>
                        <h3>Yes bids</h3>
                        <?php if ($yesLevels === []): ?>
                            <p class="empty">No yes bids.</p>
                        <?php else: ?>
                            <table>
                                <tbody>
                                <?php foreach (array_slice($yesLevels, 0, 8) as $level): ?>
                                    <tr><td class="num"><?= h(px($level[0])) ?></td><td class="num"><?= h($level[1]) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3>No bids</h3>
                        <?php if ($noLevels === []): ?>
                            <p class="empty">No no bids.</p>
                        <?php else: ?>
                            <table>
                                <tbody>
                                <?php foreach (array_slice($noLevels, 0, 8) as $level): ?>
                                    <tr><td class="num"><?= h(px($level[0])) ?></td><td class="num"><?= h($level[1]) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                <p class="fine">Bids only. The best yes ask is one dollar minus the best no bid.</p>
            </section>
            <?php if (!empty($market['rules_primary'])): ?>
                <details class="panel rules">
                    <summary>Rules</summary>
                    <p><?= h($market['rules_primary']) ?></p>
                    <?php if (!empty($market['rules_secondary'])): ?>
                        <p><?= h($market['rules_secondary']) ?></p>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
            <p class="fine">Closes <?= h(iso_time($market['close_time'] ?? null)) ?> · Status <?= h((string) ($market['status'] ?? '')) ?></p>
        </div>
        <aside class="ticket">
            <h2>Paper order</h2>
            <p class="fine">The price is what you pay per contract. Buying NO is sent on the yes book at one dollar minus that price.</p>
            <?php if ($preview !== null): ?>
                <p class="banner ok">Pays <?= h(px($preview['pay_price'])) ?> per contract. Sent as a <?= h($preview['book_side']) ?> at <?= h(px($preview['api_price'])) ?>.</p>
            <?php endif; ?>
            <form method="post" action="/?page=order" class="stack">
                <?= csrf_field() ?>
                <input type="hidden" name="ticker" value="<?= h($market['ticker']) ?>">
                <input type="hidden" name="client_order_id" value="<?= h($ticket['client_order_id']) ?>">
                <label>Contract
                    <select name="outcome">
                        <option value="yes" <?= ($ticket['outcome'] ?? '') === 'yes' ? 'selected' : '' ?>>Buy YES</option>
                        <option value="no" <?= ($ticket['outcome'] ?? '') === 'no' ? 'selected' : '' ?>>Buy NO</option>
                    </select>
                </label>
                <label>Limit price (dollars)
                    <input name="price" inputmode="decimal" required placeholder="0.55" value="<?= h($ticket['price'] ?? '') ?>">
                </label>
                <label>Contracts
                    <input name="count" inputmode="decimal" required value="<?= h($ticket['count'] ?? '1') ?>">
                </label>
                <label>Time in force
                    <select name="time_in_force">
                        <?php
                        $forces = [
                            'good_till_canceled' => 'Good till canceled',
                            'immediate_or_cancel' => 'Immediate or cancel',
                            'fill_or_kill' => 'Fill or kill',
                        ];
                        foreach ($forces as $value => $label):
                        ?>
                            <option value="<?= h($value) ?>" <?= ($ticket['time_in_force'] ?? '') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="check">
                    <input type="checkbox" name="post_only" value="1" <?= !empty($ticket['post_only']) ? 'checked' : '' ?>>
                    Post only
                </label>
                <label class="check">
                    <input type="checkbox" name="confirm" value="1" required>
                    Send this order to the Kalshi demo exchange
                </label>
                <?php if ($has_key): ?>
                    <button type="submit">Place demo order</button>
                <?php else: ?>
                    <p class="fine">Add a demo API key before sending an order.</p>
                    <a class="button" href="/?page=settings">Add a demo key</a>
                <?php endif; ?>
            </form>
        </aside>
    </div>
<?php endif; ?>
