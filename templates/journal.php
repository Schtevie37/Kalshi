<h1>Journal</h1>
<p class="lede">Orders sent from this desk, plus recent signed calls to the demo API. The private key is not stored in either log.</p>

<section class="panel">
    <header class="panel-head"><h2>Orders from this desk</h2></header>
    <?php if ($entries === []): ?>
        <p class="empty">No orders yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>When</th>
                    <th>Market</th>
                    <th>Side</th>
                    <th class="num">You pay</th>
                    <th class="num">Sent price</th>
                    <th class="num">Count</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?= h(iso_time($entry['created_at'])) ?></td>
                        <td><a href="/?page=market&amp;ticker=<?= h(rawurlencode($entry['ticker'])) ?>"><?= h($entry['ticker']) ?></a></td>
                        <td><?= h(strtoupper($entry['outcome'])) ?> · <?= h($entry['book_side']) ?></td>
                        <td class="num"><?= h(px((string) $entry['pay_price'])) ?></td>
                        <td class="num"><?= h(px((string) $entry['api_price'])) ?></td>
                        <td class="num"><?= h((string) $entry['contract_count']) ?></td>
                        <td>
                            <?= h($entry['status']) ?>
                            <?php if (!empty($entry['error_message'])): ?>
                                <span class="ticker"><?= h($entry['error_message']) ?></span>
                            <?php elseif (!empty($entry['kalshi_order_id'])): ?>
                                <span class="ticker"><?= h($entry['kalshi_order_id']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <header class="panel-head"><h2>Signed API calls</h2></header>
    <?php if ($calls === []): ?>
        <p class="empty">No signed calls yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>When</th><th>Call</th><th class="num">HTTP</th><th>Note</th></tr>
                </thead>
                <tbody>
                <?php foreach ($calls as $call): ?>
                    <tr>
                        <td><?= h(iso_time($call['created_at'])) ?></td>
                        <td><?= h($call['method']) ?> <?= h($call['path']) ?></td>
                        <td class="num"><?= h((string) ($call['http_status'] ?? '—')) ?></td>
                        <td><?= h((string) ($call['error_message'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
