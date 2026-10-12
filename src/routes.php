<?php

declare(strict_types=1);

function dispatch(string $page): void
{
    try {
        match ($page) {
            'home' => dispatch_home(),
            'login' => dispatch_login(),
            'register' => dispatch_register(),
            'logout' => dispatch_logout(),
            'portfolio' => dispatch_portfolio(),
            'markets' => dispatch_markets(),
            'market' => dispatch_market(),
            'order' => dispatch_order(),
            'cancel' => dispatch_cancel(),
            'orders' => dispatch_orders(),
            'fills' => dispatch_fills(),
            'journal' => dispatch_journal(),
            'scorecard' => dispatch_scorecard(),
            'settings' => dispatch_settings(),
            default => render_missing(),
        };
    } catch (Throwable $e) {
        error_log('kalshi-paper: ' . $e::class . ': ' . $e->getMessage());
        http_response_code(500);
        if ($e instanceof PDOException) {
            $safe = 'The desk could not use MySQL.';
        } elseif ($e instanceof KalshiException || $e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
            $safe = $e->getMessage();
        } else {
            $safe = 'The desk hit an unexpected error.';
        }
        try {
            if (current_user() === null) {
                echo '<!DOCTYPE html><meta charset="utf-8"><title>Desk error</title><p>' . h($safe) . '</p>';
                return;
            }
            view('error', ['title' => 'Something went wrong', 'page' => '', 'message' => $safe]);
        } catch (Throwable) {
            echo '<!DOCTYPE html><meta charset="utf-8"><title>Desk error</title><p>' . h($safe) . '</p>';
        }
    }
}

function dispatch_home(): void
{
    redirect_to(current_user() ? 'scorecard' : 'login');
}

function dispatch_login(): void
{
    if (current_user() !== null) {
        redirect_to('scorecard');
    }
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $locked = (int) ($_SESSION['login_locked_until'] ?? 0);
        if ($locked > time()) {
            $error = 'Too many sign-in attempts. Wait a minute and try again.';
        } else {
            $email = normalize_email($_POST['email'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
                $_SESSION['login_fails'] = (int) ($_SESSION['login_fails'] ?? 0) + 1;
                if ($_SESSION['login_fails'] >= 5) {
                    $_SESSION['login_locked_until'] = time() + 60;
                    $_SESSION['login_fails'] = 0;
                }
                $error = 'That email and password did not match.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $row['id'];
                unset($_SESSION['login_fails'], $_SESSION['login_locked_until']);
                remember_login((int) $row['id']);
                redirect_to('scorecard');
            }
        }
    }
    view('login', ['title' => 'Sign in', 'bare' => true, 'error' => $error, 'email' => (string) ($_POST['email'] ?? '')]);
}

function dispatch_register(): void
{
    if (current_user() !== null) {
        redirect_to('scorecard');
    }
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $email = normalize_email($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($password) < 10) {
            $error = 'Use a password of at least 10 characters.';
        } elseif (!hash_equals($password, $confirm)) {
            $error = 'The two passwords did not match.';
        } else {
            try {
                $stmt = db()->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
                $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                $userId = (int) db()->lastInsertId();
                $_SESSION['user_id'] = $userId;
                remember_login($userId);
                flash('ok', 'Account created. Add a Kalshi demo API key when you want to place paper orders. Market data is available before that.');
                redirect_to('settings');
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    $error = 'An account with that email already exists.';
                } else {
                    throw $e;
                }
            }
        }
    }
    view('register', ['title' => 'Create account', 'bare' => true, 'error' => $error, 'email' => (string) ($_POST['email'] ?? '')]);
}

function dispatch_logout(): void
{
    forget_remembered_login();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    header('Location: /?page=login', true, 303);
    exit;
}

function dispatch_portfolio(): void
{
    $user = require_user();
    $client = demo_client();
    $status = exchange_status($client);
    $balance = null;
    $positions = [];
    $orders = [];
    $errors = [];
    $credentials = null;
    try {
        $credentials = load_credentials((int) $user['id']);
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
    if ($credentials !== null) {
        $authed = demo_client($credentials, (int) $user['id']);
        foreach (
            [
                'balance' => fn () => $authed->request('GET', '/portfolio/balance'),
                'positions' => fn () => $authed->request('GET', '/portfolio/positions', ['limit' => 50]),
                'orders' => fn () => $authed->request('GET', '/portfolio/orders', ['limit' => 20, 'status' => 'resting']),
            ] as $name => $call
        ) {
            try {
                $payload = $call();
                if ($name === 'balance') {
                    $balance = $payload;
                } elseif ($name === 'positions') {
                    $positions = kalshi_rows($payload, ['market_positions', 'positions']);
                } else {
                    $orders = kalshi_rows($payload, ['orders']);
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        sodium_memzero($credentials['pem']);
    }
    view('portfolio', [
        'title' => 'Portfolio',
        'page' => 'portfolio',
        'exchange' => $status,
        'has_key' => $credentials !== null,
        'balance' => $balance,
        'positions' => $positions,
        'orders' => $orders,
        'errors' => $errors,
    ]);
}

function dispatch_markets(): void
{
    require_user();
    $client = demo_client();
    $query = trim((string) ($_GET['q'] ?? ''));
    $cursor = trim((string) ($_GET['cursor'] ?? ''));
    $result = ['markets' => [], 'cursor' => '', 'note' => ''];
    $error = null;
    try {
        $result = load_markets($client, $query, $cursor);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    view('markets', [
        'title' => 'Markets',
        'page' => 'markets',
        'exchange' => exchange_status($client),
        'markets' => $result['markets'],
        'next_cursor' => $result['cursor'],
        'note' => $result['note'],
        'q' => $query,
        'error' => $error,
    ]);
}

function dispatch_market(): void
{
    $user = require_user();
    $error = null;
    $market = null;
    $book = ['yes_dollars' => [], 'no_dollars' => []];
    $tickerValue = trim((string) ($_GET['ticker'] ?? $_POST['ticker'] ?? ''));
    try {
        $tickerValue = ticker($tickerValue);
        $client = demo_client();
        $payload = $client->publicGet('/markets/' . rawurlencode($tickerValue));
        $market = $payload['market'] ?? null;
        if (!is_array($market)) {
            throw new RuntimeException('Kalshi demo did not return that market.');
        }
        $bookPayload = $client->publicGet('/markets/' . rawurlencode($tickerValue) . '/orderbook');
        $fp = $bookPayload['orderbook_fp'] ?? [];
        if (is_array($fp)) {
            $book['yes_dollars'] = is_array($fp['yes_dollars'] ?? null) ? $fp['yes_dollars'] : [];
            $book['no_dollars'] = is_array($fp['no_dollars'] ?? null) ? $fp['no_dollars'] : [];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $market = null;
    }
    $hasKey = false;
    try {
        $hasKey = has_credentials((int) $user['id']);
    } catch (RuntimeException) {
        $hasKey = false;
    }
    view('market', [
        'title' => is_array($market) ? (string) ($market['title'] ?? $tickerValue) : 'Market',
        'page' => 'markets',
        'exchange' => exchange_status(demo_client()),
        'market' => $market,
        'book' => $book,
        'error' => $error,
        'has_key' => $hasKey,
        'ticket' => [
            'client_order_id' => uuid_v4(),
            'outcome' => 'yes',
            'price' => '',
            'count' => '1',
            'time_in_force' => 'good_till_canceled',
            'post_only' => false,
        ],
        'form_error' => null,
    ]);
}

function dispatch_order(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect_to('markets');
    }
    $user = require_user();
    require_csrf();
    $tickerValue = trim((string) ($_POST['ticker'] ?? ''));
    $ticket = [
        'client_order_id' => (string) ($_POST['client_order_id'] ?? ''),
        'outcome' => (string) ($_POST['outcome'] ?? 'yes'),
        'price' => (string) ($_POST['price'] ?? ''),
        'count' => (string) ($_POST['count'] ?? ''),
        'time_in_force' => (string) ($_POST['time_in_force'] ?? 'good_till_canceled'),
        'post_only' => isset($_POST['post_only']),
    ];
    $market = null;
    $book = ['yes_dollars' => [], 'no_dollars' => []];
    $formError = null;
    try {
        if (($_POST['confirm'] ?? '') !== '1') {
            throw new InvalidArgumentException('Confirm that this order should go to the Kalshi demo exchange.');
        }
        $tickerValue = ticker($tickerValue);
        if (!is_uuid($ticket['client_order_id'])) {
            throw new InvalidArgumentException('The order ticket id was not valid. Reload the market and try again.');
        }
        $public = demo_client();
        $payload = $public->publicGet('/markets/' . rawurlencode($tickerValue));
        $market = $payload['market'] ?? null;
        if (!is_array($market)) {
            throw new RuntimeException('Kalshi demo did not return that market.');
        }
        $status = strtolower((string) ($market['status'] ?? ''));
        if (!in_array($status, ['active', 'open'], true)) {
            throw new InvalidArgumentException('That market is not open for orders.');
        }
        $priced = Pricing::order($ticket['outcome'], $ticket['price'], $ticket['count'], $market);
        $body = Trading::payload($priced, $tickerValue, $ticket['time_in_force'], $ticket['post_only'], $ticket['client_order_id']);
        $credentials = load_credentials((int) $user['id']);
        if ($credentials === null) {
            throw new RuntimeException('Add a Kalshi demo API key before placing a paper order.');
        }
        $insert = db()->prepare(
            'INSERT INTO order_journal
                (user_id, client_order_id, ticker, outcome, book_side, pay_price, api_price, contract_count, time_in_force, post_only, status, request_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $insert->execute([
                (int) $user['id'],
                $body['client_order_id'],
                $tickerValue,
                $priced['outcome'],
                $priced['book_side'],
                $priced['pay_price'],
                $priced['api_price'],
                $priced['count'],
                $ticket['time_in_force'],
                $ticket['post_only'] ? 1 : 0,
                'sending',
                json_encode($body, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                throw new InvalidArgumentException('This order ticket was already submitted. Reload the market before sending another order.');
            }
            throw $e;
        }
        $journalId = (int) db()->lastInsertId();
        try {
            $authed = demo_client($credentials, (int) $user['id']);
            $response = $authed->request('POST', '/portfolio/events/orders', [], $body);
            $update = db()->prepare(
                'UPDATE order_journal SET status = ?, kalshi_order_id = ?, http_status = ?, response_json = ?, error_message = NULL WHERE id = ? AND user_id = ?'
            );
            $update->execute([
                'accepted',
                isset($response['order_id']) ? substr((string) $response['order_id'], 0, 64) : null,
                201,
                json_encode($response, JSON_UNESCAPED_SLASHES),
                $journalId,
                (int) $user['id'],
            ]);
            sodium_memzero($credentials['pem']);
            $filled = (string) ($response['fill_count'] ?? '0.00');
            $resting = (string) ($response['remaining_count'] ?? '0.00');
            flash('ok', 'Demo order accepted. Filled ' . $filled . ', still resting ' . $resting . '.');
            redirect_to('orders');
        } catch (Throwable $e) {
            $statusCode = $e instanceof KalshiException ? $e->status : null;
            $fail = db()->prepare(
                'UPDATE order_journal SET status = ?, http_status = ?, error_message = ? WHERE id = ? AND user_id = ?'
            );
            $fail->execute(['rejected', $statusCode, substr($e->getMessage(), 0, 500), $journalId, (int) $user['id']]);
            sodium_memzero($credentials['pem']);
            throw $e;
        }
    } catch (Throwable $e) {
        if (isset($credentials) && is_array($credentials) && is_string($credentials['pem'] ?? null) && $credentials['pem'] !== '') {
            sodium_memzero($credentials['pem']);
        }
        $formError = $e->getMessage();
        if (!str_contains($formError, 'already submitted')) {
            $ticket['client_order_id'] = uuid_v4();
        }
        if ($market === null && $tickerValue !== '') {
            try {
                $tickerValue = ticker($tickerValue);
                $payload = demo_client()->publicGet('/markets/' . rawurlencode($tickerValue));
                $market = is_array($payload['market'] ?? null) ? $payload['market'] : null;
            } catch (Throwable) {
                $market = null;
            }
        }
    }
    if (!is_array($market)) {
        flash('error', $formError ?? 'The order could not be sent.');
        redirect_to('markets');
    }
    try {
        $bookPayload = demo_client()->publicGet('/markets/' . rawurlencode($tickerValue) . '/orderbook');
        $fp = $bookPayload['orderbook_fp'] ?? [];
        if (is_array($fp)) {
            $book['yes_dollars'] = is_array($fp['yes_dollars'] ?? null) ? $fp['yes_dollars'] : [];
            $book['no_dollars'] = is_array($fp['no_dollars'] ?? null) ? $fp['no_dollars'] : [];
        }
    } catch (Throwable) {
        $book = ['yes_dollars' => [], 'no_dollars' => []];
    }
    $hasKey = false;
    try {
        $hasKey = has_credentials((int) $user['id']);
    } catch (RuntimeException) {
        $hasKey = false;
    }
    view('market', [
        'title' => (string) ($market['title'] ?? $tickerValue),
        'page' => 'markets',
        'exchange' => exchange_status(demo_client()),
        'market' => $market,
        'book' => $book,
        'error' => null,
        'has_key' => $hasKey,
        'ticket' => $ticket,
        'form_error' => $formError,
    ]);
}

function dispatch_cancel(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect_to('orders');
    }
    $user = require_user();
    require_csrf();
    try {
        $orderId = trim((string) ($_POST['order_id'] ?? ''));
        $marketTicker = ticker((string) ($_POST['ticker'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $orderId)) {
            throw new InvalidArgumentException('That order id is not valid.');
        }
        $credentials = load_credentials((int) $user['id']);
        if ($credentials === null) {
            throw new RuntimeException('Add a Kalshi demo API key before canceling an order.');
        }
        $client = demo_client($credentials, (int) $user['id']);
        $response = $client->request('DELETE', '/portfolio/events/orders/' . rawurlencode($orderId), [
            'market_ticker' => $marketTicker,
        ]);
        sodium_memzero($credentials['pem']);
        $update = db()->prepare(
            'UPDATE order_journal SET status = ? WHERE user_id = ? AND kalshi_order_id = ?'
        );
        $update->execute(['canceled', (int) $user['id'], $orderId]);
        $reduced = (string) ($response['reduced_by'] ?? '');
        flash('ok', 'Cancel request accepted' . ($reduced !== '' ? ' for ' . $reduced . ' contracts' : '') . '.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect_to('orders');
}

function dispatch_orders(): void
{
    $user = require_user();
    $status = (string) ($_GET['status'] ?? 'resting');
    if (!in_array($status, ['resting', 'executed', 'canceled', 'all'], true)) {
        $status = 'resting';
    }
    $orders = [];
    $error = null;
    $hasKey = false;
    try {
        $credentials = load_credentials((int) $user['id']);
        $hasKey = $credentials !== null;
        if ($credentials !== null) {
            $query = ['limit' => 100];
            if ($status !== 'all') {
                $query['status'] = $status;
            }
            $payload = demo_client($credentials, (int) $user['id'])->request('GET', '/portfolio/orders', $query);
            sodium_memzero($credentials['pem']);
            $orders = kalshi_rows($payload, ['orders']);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    view('orders', [
        'title' => 'Orders',
        'page' => 'orders',
        'exchange' => exchange_status(demo_client()),
        'orders' => $orders,
        'status' => $status,
        'error' => $error,
        'has_key' => $hasKey,
    ]);
}

function dispatch_fills(): void
{
    $user = require_user();
    $fills = [];
    $error = null;
    $hasKey = false;
    try {
        $credentials = load_credentials((int) $user['id']);
        $hasKey = $credentials !== null;
        if ($credentials !== null) {
            $payload = demo_client($credentials, (int) $user['id'])->request('GET', '/portfolio/fills', ['limit' => 100]);
            sodium_memzero($credentials['pem']);
            $fills = kalshi_rows($payload, ['fills']);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    view('fills', [
        'title' => 'Fills',
        'page' => 'fills',
        'exchange' => exchange_status(demo_client()),
        'fills' => $fills,
        'error' => $error,
        'has_key' => $hasKey,
    ]);
}

function dispatch_journal(): void
{
    $user = require_user();
    $orders = db()->prepare(
        'SELECT created_at, ticker, outcome, book_side, pay_price, api_price, contract_count, time_in_force, post_only, status, kalshi_order_id, http_status, error_message
         FROM order_journal WHERE user_id = ? ORDER BY id DESC LIMIT 50'
    );
    $orders->execute([(int) $user['id']]);
    $calls = db()->prepare(
        'SELECT created_at, method, path, http_status, error_message FROM api_log WHERE user_id = ? ORDER BY id DESC LIMIT 40'
    );
    $calls->execute([(int) $user['id']]);
    view('journal', [
        'title' => 'Journal',
        'page' => 'journal',
        'exchange' => exchange_status(demo_client()),
        'entries' => $orders->fetchAll(),
        'calls' => $calls->fetchAll(),
    ]);
}

function dispatch_scorecard(): void
{
    $user = require_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new InvalidArgumentException('That position was not found.');
                }
                $stmt = db()->prepare('DELETE FROM tracked_positions WHERE id = ? AND user_id = ?');
                $stmt->execute([$id, (int) $user['id']]);
                flash('ok', 'Position removed.');
                redirect_to('scorecard');
            }
            if ($action === 'add') {
                $entry = Scorecard::entry(
                    (string) ($_POST['ticker'] ?? ''),
                    (string) ($_POST['outcome'] ?? ''),
                    (string) ($_POST['pay_price'] ?? ''),
                    (string) ($_POST['contract_count'] ?? '')
                );
                $marketTitle = null;
                try {
                    $marketTitle = Scorecard::marketTitle((new LiveMarket())->fetch($entry['ticker']));
                } catch (Throwable) {
                    $marketTitle = null;
                }
                if (tracked_positions_title_ready()) {
                    $stmt = db()->prepare(
                        'INSERT INTO tracked_positions (user_id, ticker, market_title, outcome, pay_price, contract_count)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        (int) $user['id'],
                        $entry['ticker'],
                        $marketTitle,
                        $entry['outcome'],
                        $entry['pay_price'],
                        $entry['count'],
                    ]);
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO tracked_positions (user_id, ticker, outcome, pay_price, contract_count)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        (int) $user['id'],
                        $entry['ticker'],
                        $entry['outcome'],
                        $entry['pay_price'],
                        $entry['count'],
                    ]);
                }
                flash('ok', 'Position saved. Open this page again after the event to see if it won.');
                redirect_to('scorecard');
            }
            throw new InvalidArgumentException('Unknown scorecard action.');
        } catch (Throwable $e) {
            flash('error', $e instanceof PDOException ? 'The desk could not use MySQL.' : $e->getMessage());
            redirect_to('scorecard');
        }
    }

    $hasTitle = tracked_positions_title_ready();
    $stmt = db()->prepare(
        'SELECT id, ticker, ' . ($hasTitle ? 'market_title, ' : '') . 'outcome, pay_price, contract_count, market_result, settled_at, created_at
         FROM tracked_positions WHERE user_id = ? ORDER BY id DESC'
    );
    $stmt->execute([(int) $user['id']]);
    $rows = $stmt->fetchAll();
    if (!is_array($rows)) {
        $rows = [];
    }
    $live = new LiveMarket();
    $update = db()->prepare(
        'UPDATE tracked_positions SET market_result = ?, settled_at = UTC_TIMESTAMP()
         WHERE id = ? AND user_id = ? AND market_result IS NULL'
    );
    $saveTitle = $hasTitle
        ? db()->prepare(
            'UPDATE tracked_positions SET market_title = ?
             WHERE id = ? AND user_id = ? AND market_title IS NULL'
        )
        : null;
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $row['check_error'] = null;
        $row['score'] = null;
        $storedTitle = $row['market_title'] ?? null;
        $row['market_title'] = is_string($storedTitle) && $storedTitle !== '' ? $storedTitle : null;
        $result = $row['market_result'] ?? null;
        $settled = $result === 'yes' || $result === 'no';
        if ($settled) {
            $row['score'] = Scorecard::settle(
                (string) $row['outcome'],
                (string) $row['pay_price'],
                (string) $row['contract_count'],
                (string) $result
            );
        }
        if ($settled && $row['market_title'] !== null) {
            $rows[$index] = $row;
            continue;
        }
        try {
            $market = $live->fetch((string) $row['ticker']);
            $title = Scorecard::marketTitle($market);
            if ($title !== null) {
                $row['market_title'] = $title;
                if ($saveTitle !== null && !is_string($storedTitle)) {
                    $saveTitle->execute([$title, (int) $row['id'], (int) $user['id']]);
                }
            }
            if ($settled) {
                $rows[$index] = $row;
                continue;
            }
            $found = Scorecard::marketResult($market);
            if ($found === null) {
                $rows[$index] = $row;
                continue;
            }
            $update->execute([$found, (int) $row['id'], (int) $user['id']]);
            $row['market_result'] = $found;
            $row['score'] = Scorecard::settle(
                (string) $row['outcome'],
                (string) $row['pay_price'],
                (string) $row['contract_count'],
                $found
            );
        } catch (Throwable $e) {
            if (!$settled) {
                $row['check_error'] = $e->getMessage();
            }
        }
        $rows[$index] = $row;
    }

    view('scorecard', [
        'title' => 'Scorecard',
        'page' => 'scorecard',
        'positions' => $rows,
        'totals' => Scorecard::totals($rows),
    ]);
}

function dispatch_settings(): void
{
    $user = require_user();
    $saved = null;
    try {
        $saved = credential_summary((int) $user['id']);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'delete') {
                $stmt = db()->prepare('DELETE FROM api_credentials WHERE user_id = ?');
                $stmt->execute([(int) $user['id']]);
                flash('ok', 'Demo API key removed from this desk.');
                redirect_to('settings');
            }
            if ($action === 'test') {
                $credentials = load_credentials((int) $user['id']);
                if ($credentials === null) {
                    throw new RuntimeException('Save a demo API key before testing it.');
                }
                $balance = demo_client($credentials, (int) $user['id'])->request('GET', '/portfolio/balance');
                sodium_memzero($credentials['pem']);
                $shown = money($balance['balance_dollars'] ?? null, $balance['balance'] ?? null);
                flash('ok', 'Demo key works. Available balance is ' . $shown . '.');
                redirect_to('settings');
            }
            if ($action === 'save') {
                $keyId = strtolower(trim((string) ($_POST['key_id'] ?? '')));
                $pem = trim((string) ($_POST['private_key'] ?? ''));
                if (!is_uuid($keyId)) {
                    throw new InvalidArgumentException('The API key id should look like a UUID from the Kalshi demo site.');
                }
                $type = Signer::detectType($pem);
                Signer::sign($pem, (string) (int) floor(microtime(true) * 1000), 'GET', '/trade-api/v2/portfolio/balance');
                [$cipher, $nonce] = Vault::seal($pem, (string) app_config()['app_key']);
                sodium_memzero($pem);
                wipe_posted_key();
                $stmt = db()->prepare(
                    'INSERT INTO api_credentials (user_id, key_id, key_type, private_key_cipher, private_key_nonce)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE key_id = VALUES(key_id), key_type = VALUES(key_type), private_key_cipher = VALUES(private_key_cipher), private_key_nonce = VALUES(private_key_nonce)'
                );
                $stmt->execute([(int) $user['id'], $keyId, $type, $cipher, $nonce]);
                flash('ok', 'Demo key saved. It is encrypted in MySQL and will not be shown again.');
                redirect_to('settings');
            }
            throw new InvalidArgumentException('Unknown settings action.');
        } catch (Throwable $e) {
            if (isset($pem) && is_string($pem) && $pem !== '') {
                sodium_memzero($pem);
            }
            wipe_posted_key();
            flash('error', $e->getMessage());
            redirect_to('settings');
        }
    }
    view('settings', [
        'title' => 'Demo keys',
        'page' => 'settings',
        'exchange' => exchange_status(demo_client()),
        'saved' => $saved,
    ]);
}

function render_missing(): void
{
    http_response_code(404);
    if (current_user() === null) {
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Not found</title><p>That page does not exist.</p>';
        return;
    }
    view('error', ['title' => 'Not found', 'message' => 'That page does not exist.']);
}

function normalize_email(mixed $email): string
{
    return strtolower(trim((string) $email));
}
