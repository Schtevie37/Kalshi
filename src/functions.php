<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect_to(string $page, array $query = []): never
{
    $query = ['page' => $page] + $query;
    header('Location: /?' . http_build_query($query), true, 303);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);

    return is_array($flashes) ? $flashes : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function require_csrf(): void
{
    $sent = $_POST['_csrf'] ?? '';
    $known = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || !is_string($known) || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(400);
        echo 'The form token did not match this session. Reload the page and try again.';
        exit;
    }
}

function uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function is_uuid(string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
}

function ticker(string $value): string
{
    $value = trim($value);
    if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/', $value)) {
        throw new InvalidArgumentException('That market ticker is not valid.');
    }

    return $value;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = app_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        (int) $config['db_port'],
        $config['db_name']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function current_user(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, email, created_at FROM users WHERE id = ?');
    $stmt->execute([(int) $id]);
    $user = $stmt->fetch();

    return $user === false ? null : $user;
}

function require_user(): array
{
    $user = current_user();
    if ($user === null) {
        flash('error', 'Sign in to open the paper desk.');
        redirect_to('login');
    }

    return $user;
}

/** Browsers keep a cookie for at most 400 days. Each visit slides that window forward. */
function remember_lifetime(): int
{
    return 400 * 24 * 60 * 60;
}

/**
 * @return array{selector: string, verifier: string}|null
 */
function parse_remember_token(string $value): ?array
{
    if (!preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/', $value)) {
        return null;
    }
    [$selector, $verifierHex] = explode('.', $value, 2);
    $verifier = hex2bin($verifierHex);
    if ($verifier === false || strlen($verifier) !== 32) {
        return null;
    }

    return ['selector' => $selector, 'verifier' => $verifier];
}

function remember_token_hash(string $verifier): string
{
    return hash('sha256', $verifier);
}

function login_tokens_ready(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    db()->exec(
        'CREATE TABLE IF NOT EXISTS login_tokens (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id INT UNSIGNED NOT NULL,
          selector CHAR(32) NOT NULL,
          token_hash CHAR(64) NOT NULL,
          expires_at INT UNSIGNED NOT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY login_tokens_selector (selector),
          KEY login_tokens_user_id (user_id),
          CONSTRAINT login_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ready = true;
}

function remember_login(int $userId): void
{
    $existing = remember_cookie_parts();
    login_tokens_ready();
    if ($existing !== null) {
        $delete = db()->prepare('DELETE FROM login_tokens WHERE selector = ?');
        $delete->execute([$existing['selector']]);
    }
    $selector = bin2hex(random_bytes(16));
    $verifier = random_bytes(32);
    $until = time() + remember_lifetime();
    $insert = db()->prepare('INSERT INTO login_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)');
    $insert->execute([$userId, $selector, remember_token_hash($verifier), $until]);
    send_remember_cookie($selector . '.' . bin2hex($verifier), $until);
}

function resume_remembered_login(): void
{
    $raw = $_COOKIE['kalshipaper_keep'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return;
    }
    $parts = parse_remember_token($raw);
    if ($parts === null) {
        clear_remember_cookie();
        return;
    }
    try {
        login_tokens_ready();
        $stmt = db()->prepare('SELECT id, user_id, token_hash, expires_at FROM login_tokens WHERE selector = ?');
        $stmt->execute([$parts['selector']]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        return;
    }
    $valid = is_array($row)
        && (int) $row['expires_at'] >= time()
        && hash_equals((string) $row['token_hash'], remember_token_hash($parts['verifier']));
    if (!$valid) {
        if (is_array($row)) {
            $delete = db()->prepare('DELETE FROM login_tokens WHERE id = ?');
            $delete->execute([(int) $row['id']]);
        }
        clear_remember_cookie();
        return;
    }
    $userId = (int) $row['user_id'];
    $sessionUser = $_SESSION['user_id'] ?? null;
    $sessionUserId = is_int($sessionUser) ? $sessionUser : (is_string($sessionUser) && ctype_digit($sessionUser) ? (int) $sessionUser : null);
    if ($sessionUserId !== null && $sessionUserId !== $userId) {
        return;
    }
    if ($sessionUserId === null) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }
    $until = time() + remember_lifetime();
    $update = db()->prepare('UPDATE login_tokens SET expires_at = ? WHERE id = ?');
    $update->execute([$until, (int) $row['id']]);
    send_remember_cookie($raw, $until);
}

function forget_remembered_login(): void
{
    $parts = remember_cookie_parts();
    if ($parts !== null) {
        try {
            login_tokens_ready();
            $delete = db()->prepare('DELETE FROM login_tokens WHERE selector = ?');
            $delete->execute([$parts['selector']]);
        } catch (Throwable) {
            // The cookie is still cleared below.
        }
    }
    clear_remember_cookie();
}

/**
 * @return array{selector: string, verifier: string}|null
 */
function remember_cookie_parts(): ?array
{
    $raw = $_COOKIE['kalshipaper_keep'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    return parse_remember_token($raw);
}

function send_remember_cookie(string $value, int $expires): void
{
    setcookie('kalshipaper_keep', $value, remember_cookie_options($expires));
    $_COOKIE['kalshipaper_keep'] = $value;
}

function clear_remember_cookie(): void
{
    setcookie('kalshipaper_keep', '', remember_cookie_options(time() - 42000));
    unset($_COOKIE['kalshipaper_keep']);
}

/**
 * @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: string}
 */
function remember_cookie_options(int $expires): array
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function view(string $template, array $data = []): void
{
    $bare = (bool) ($data['bare'] ?? false);
    unset($data['bare']);
    $user = $bare ? null : current_user();
    $flashes = take_flashes();
    $title = (string) ($data['title'] ?? 'Paper desk');
    extract($data, EXTR_SKIP);
    ob_start();
    try {
        require dirname(__DIR__) . '/templates/' . $template . '.php';
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    $content = (string) ob_get_clean();
    require dirname(__DIR__) . '/templates/' . ($bare ? 'auth_layout.php' : 'layout.php');
}

function money(mixed $dollars, mixed $cents = null): string
{
    if (is_string($dollars) && preg_match('/^-?\d+(?:\.\d+)?$/', $dollars)) {
        return grouped_dollars($dollars);
    }
    if (is_int($cents) || (is_string($cents) && preg_match('/^-?\d+$/', $cents))) {
        $value = (int) $cents;
        $negative = $value < 0;
        $value = abs($value);

        return ($negative ? '−' : '') . '$' . number_format(intdiv($value, 100)) . '.' . str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }

    return '—';
}

function px(mixed $dollars): string
{
    if (!is_string($dollars) && !is_numeric($dollars)) {
        return '—';
    }
    $raw = trim((string) $dollars);
    if (!preg_match('/^-?\d+(?:\.\d+)?$/', $raw)) {
        return '—';
    }
    $negative = str_starts_with($raw, '-');
    $raw = ltrim($raw, '-');
    if (!str_contains($raw, '.')) {
        $raw .= '.';
    }
    [$whole, $frac] = explode('.', $raw, 2);
    $frac = substr(str_pad($frac, 4, '0'), 0, 4);

    return ($negative ? '−' : '') . '$' . $whole . '.' . $frac;
}

function grouped_dollars(string $raw): string
{
    $negative = str_starts_with($raw, '-');
    $raw = ltrim($raw, '-');
    if (!str_contains($raw, '.')) {
        $raw .= '.00';
    }
    [$whole, $frac] = explode('.', $raw, 2);
    $frac = substr(str_pad($frac, 2, '0'), 0, 4);
    $frac = rtrim($frac, '0');
    if (strlen($frac) < 2) {
        $frac = str_pad($frac, 2, '0');
    }

    return ($negative ? '−' : '') . '$' . number_format((int) $whole) . '.' . $frac;
}

function iso_time(mixed $value, string $timezone = 'UTC'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        $zone = new DateTimeZone($timezone);
    } catch (Exception) {
        $zone = new DateTimeZone('UTC');
        $timezone = 'UTC';
    }
    $label = $timezone === 'America/New_York' ? 'ET' : $timezone;
    if (is_int($value) || (is_string($value) && preg_match('/^\d{10,13}$/', $value))) {
        $seconds = (int) $value;
        if ($seconds > 20000000000) {
            $seconds = (int) floor($seconds / 1000);
        }

        return (new DateTimeImmutable('@' . $seconds))->setTimezone($zone)->format('M j, Y H:i') . ' ' . $label;
    }
    try {
        return (new DateTimeImmutable((string) $value))->setTimezone($zone)->format('M j, Y H:i') . ' ' . $label;
    } catch (Exception) {
        return (string) $value;
    }
}

function pick(array $row, array $keys, string $default = '—'): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row) || is_array($row[$key]) || $row[$key] === null || $row[$key] === '') {
            continue;
        }

        return (string) $row[$key];
    }

    return $default;
}

function demo_client(?array $credentials = null, ?int $userId = null): KalshiClient
{
    $config = app_config();

    return new KalshiClient(
        (string) $config['demo_base_url'],
        $credentials['key_id'] ?? null,
        $credentials['pem'] ?? null,
        $credentials === null ? null : db(),
        $userId,
    );
}

function wipe_posted_key(): void
{
    if (isset($_POST['private_key']) && is_string($_POST['private_key']) && $_POST['private_key'] !== '') {
        sodium_memzero($_POST['private_key']);
    }
}

function credential_summary(int $userId): ?array
{
    $stmt = db()->prepare('SELECT key_id, key_type, updated_at FROM api_credentials WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function has_credentials(int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM api_credentials WHERE user_id = ?');
    $stmt->execute([$userId]);

    return (bool) $stmt->fetchColumn();
}

function load_credentials(int $userId): ?array
{
    $stmt = db()->prepare('SELECT key_id, key_type, private_key_cipher, private_key_nonce, updated_at FROM api_credentials WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $pem = Vault::open($row['private_key_cipher'], $row['private_key_nonce'], (string) app_config()['app_key']);

    return [
        'key_id' => $row['key_id'],
        'key_type' => $row['key_type'],
        'updated_at' => $row['updated_at'],
        'pem' => $pem,
    ];
}

function exchange_status(KalshiClient $client): ?array
{
    $cached = $_SESSION['exchange_status'] ?? null;
    if (is_array($cached) && ($cached['until'] ?? 0) > time() && is_array($cached['data'] ?? null)) {
        return $cached['data'];
    }
    try {
        $data = $client->publicGet('/exchange/status');
    } catch (Throwable) {
        if (is_array($cached) && is_array($cached['data'] ?? null)) {
            return $cached['data'];
        }

        return null;
    }
    $_SESSION['exchange_status'] = ['until' => time() + 20, 'data' => $data];

    return $data;
}

function kalshi_rows(array $payload, array $keys): array
{
    foreach ($keys as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return array_values(array_filter($payload[$key], 'is_array'));
        }
    }

    return [];
}

/**
 * @return array{markets: array<int, array<string, mixed>>, cursor: string, note: string}
 */
function load_markets(KalshiClient $client, string $query, string $cursor): array
{
    if ($cursor !== '' && !preg_match('#^[A-Za-z0-9+/=_-]{1,2000}$#', $cursor)) {
        throw new InvalidArgumentException('That page cursor is not valid.');
    }
    $query = trim($query);
    if ($query !== '' && preg_match('/^[A-Za-z0-9._-]{3,128}$/', $query) && str_contains($query, '-')) {
        try {
            $one = $client->publicGet('/markets/' . rawurlencode($query));
            $market = $one['market'] ?? null;
            if (is_array($market)) {
                return ['markets' => [$market], 'cursor' => '', 'note' => 'Showing the market that matches that ticker.'];
            }
        } catch (KalshiException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    $params = [
        'limit' => 50,
        'status' => 'open',
        'mve_filter' => 'exclude',
    ];
    if ($query === '') {
        if ($cursor !== '') {
            $params['cursor'] = $cursor;
        }
        $page = $client->publicGet('/markets', $params);

        return [
            'markets' => kalshi_rows($page, ['markets']),
            'cursor' => (string) ($page['cursor'] ?? ''),
            'note' => 'Open demo markets, with combo markets left out.',
        ];
    }

    $found = [];
    $pageCursor = $cursor;
    $next = '';
    $needle = strtolower($query);
    for ($i = 0; $i < 4 && count($found) < 50; $i++) {
        $call = $params;
        if ($pageCursor !== '') {
            $call['cursor'] = $pageCursor;
        }
        $page = $client->publicGet('/markets', $call);
        foreach (kalshi_rows($page, ['markets']) as $market) {
            $haystack = strtolower(implode(' ', [
                (string) ($market['title'] ?? ''),
                (string) ($market['ticker'] ?? ''),
                (string) ($market['event_ticker'] ?? ''),
                (string) ($market['yes_sub_title'] ?? ''),
                (string) ($market['no_sub_title'] ?? ''),
            ]));
            if (str_contains($haystack, $needle)) {
                $found[] = $market;
            }
        }
        $pageCursor = (string) ($page['cursor'] ?? '');
        $next = $pageCursor;
        if ($pageCursor === '') {
            break;
        }
    }

    return [
        'markets' => $found,
        'cursor' => $next,
        'note' => 'Matches from open demo markets, with combo markets left out. Search looks through several pages.',
    ];
}
