<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$page = $_GET['page'] ?? 'home';
if (!is_string($page) || !preg_match('/^[a-z_]{1,32}$/', $page)) {
    $page = 'home';
}

dispatch($page);
