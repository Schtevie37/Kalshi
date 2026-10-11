<?php
$flashes = $flashes ?? [];
$title = $title ?? 'Paper desk';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · Paper desk</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="auth-body">
<main class="auth-card">
    <p class="kicker">Kalshi demo only</p>
    <h1>Paper desk</h1>
    <p class="lede">Browse demo markets and send paper orders with mock funds. This desk never calls the live exchange.</p>
    <?php foreach ($flashes as $flash): ?>
        <?php if (!is_array($flash)) { continue; } ?>
        <p class="banner <?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'warn' ?>"><?= h($flash['message'] ?? '') ?></p>
    <?php endforeach; ?>
    <?= $content ?>
</main>
</body>
</html>
