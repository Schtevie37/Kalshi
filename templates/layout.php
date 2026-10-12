<?php
$page = $page ?? '';
$exchange = $exchange ?? null;
$user = $user ?? null;
$flashes = $flashes ?? [];
$title = $title ?? 'Paper desk';
$nav = [
    'scorecard' => 'Scorecard',
    'portfolio' => 'Portfolio',
    'markets' => 'Markets',
    'orders' => 'Orders',
    'fills' => 'Fills',
    'journal' => 'Journal',
    'settings' => 'Demo keys',
];
$trading = !is_array($exchange) || !array_key_exists('trading_active', $exchange) || $exchange['trading_active'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#14110f">
    <title><?= h($title) ?> · Paper desk</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="app">
    <aside class="nav">
        <div class="nav-top">
            <a class="brand" href="/?page=scorecard">
                <span class="brand-mark">P</span>
                <span>
                    <strong>Paper desk</strong>
                    <small>Kalshi demo</small>
                </span>
            </a>
            <label class="nav-toggle">
                <input type="checkbox" aria-label="Menu">
                <span class="nav-burger" aria-hidden="true"></span>
            </label>
        </div>
        <nav>
            <?php foreach ($nav as $key => $label): ?>
                <a class="<?= $page === $key ? 'active' : '' ?>" href="/?page=<?= h($key) ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="nav-foot">
            <p class="desk-note">Orders go to the demo exchange and use mock funds.</p>
            <?php if (is_array($user)): ?>
                <p class="who"><?= h($user['email']) ?></p>
                <a href="/?page=logout">Sign out</a>
            <?php endif; ?>
        </div>
    </aside>
    <div class="main">
        <?php if (!$trading): ?>
            <p class="banner warn">Trading is paused on the Kalshi demo exchange.</p>
        <?php endif; ?>
        <?php foreach ($flashes as $flash): ?>
            <?php if (!is_array($flash)) { continue; } ?>
            <p class="banner <?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'warn' ?>"><?= h($flash['message'] ?? '') ?></p>
        <?php endforeach; ?>
        <?= $content ?>
    </div>
</div>
</body>
</html>
