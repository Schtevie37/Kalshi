<div class="table-wrap cards">
    <table>
        <thead>
        <tr>
            <th>Market</th>
            <th>Outcome</th>
            <th class="num">Price</th>
            <th class="num">Remaining</th>
            <th class="num">Filled</th>
            <th>Status</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <?php
            $tickerValue = pick($row, ['ticker', 'market_ticker']);
            $orderId = pick($row, ['order_id'], '');
            $status = strtolower(pick($row, ['status'], ''));
            $outcome = pick($row, ['outcome_side', 'side']);
            ?>
            <tr>
                <td data-label="Market"><?php if ($tickerValue !== '—'): ?><a href="/?page=market&amp;ticker=<?= h(rawurlencode($tickerValue)) ?>"><?= h($tickerValue) ?></a><?php else: ?>—<?php endif; ?></td>
                <td data-label="Outcome"><span class="pill <?= strtolower($outcome) === 'no' ? 'no' : 'yes' ?>"><?= h($outcome) ?></span></td>
                <td class="num" data-label="Price"><?= h(px(pick($row, ['yes_price_dollars', 'price', 'no_price_dollars'], ''))) ?></td>
                <td class="num" data-label="Remaining"><?= h(pick($row, ['remaining_count_fp', 'remaining_count'])) ?></td>
                <td class="num" data-label="Filled"><?= h(pick($row, ['fill_count_fp', 'fill_count'])) ?></td>
                <td data-label="Status"><?= h(pick($row, ['status'])) ?></td>
                <td data-label="">
                    <?php if ($status === 'resting' && $orderId !== '' && $tickerValue !== '—'): ?>
                        <form method="post" action="/?page=cancel" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= h($orderId) ?>">
                            <input type="hidden" name="ticker" value="<?= h($tickerValue) ?>">
                            <button type="submit" class="quiet">Cancel</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
