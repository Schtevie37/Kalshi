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

function iso_time(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    if (is_int($value) || (is_string($value) && preg_match('/^\d{10,13}$/', $value))) {
        $seconds = (int) $value;
        if ($seconds > 20000000000) {
            $seconds = (int) floor($seconds / 1000);
        }

        return gmdate('M j, Y H:i', $seconds) . ' UTC';
    }
    try {
        return (new DateTimeImmutable((string) $value))->setTimezone(new DateTimeZone('UTC'))->format('M j, Y H:i') . ' UTC';
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
