<?php

declare(strict_types=1);

final class KalshiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly mixed $payload = null,
    ) {
        parent::__construct($message);
    }
}

/**
 * Talks only to Kalshi's demo trade API. Production hosts are rejected.
 */
final class KalshiClient
{
    public const DEMO_HOSTS = [
        'external-api.demo.kalshi.co',
        'demo-api.kalshi.co',
    ];

    private string $origin;
    private string $prefix = '/trade-api/v2';

    public function __construct(
        string $baseUrl,
        private ?string $keyId = null,
        private ?string $privateKeyPem = null,
        private ?PDO $db = null,
        private ?int $userId = null,
    ) {
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? '';
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $extra = isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']);
        $allowed = $scheme === 'https'
            && in_array($host, self::DEMO_HOSTS, true)
            && $path === $this->prefix
            && !$extra;
        if (!$allowed) {
            throw new RuntimeException('This desk only connects to the Kalshi demo trade API.');
        }
        $this->origin = 'https://' . $host;
    }

    public function __destruct()
    {
        if (is_string($this->privateKeyPem) && $this->privateKeyPem !== '') {
            sodium_memzero($this->privateKeyPem);
        }
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function publicGet(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, null, false);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if ($this->keyId === null || $this->privateKeyPem === null || $this->privateKeyPem === '') {
            throw new RuntimeException('Add a Kalshi demo API key before requesting account data.');
        }

        return $this->send($method, $path, $query, $body, true);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $query, ?array $body, bool $authenticated): array
    {
        $method = strtoupper($method);
        $this->assertPath($path);
        $query = array_filter($query, static fn ($value) => $value !== null && $value !== '');
        $queryString = $query === [] ? '' : ('?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $url = $this->origin . $this->prefix . $path . $queryString;
        $signPath = $this->prefix . $path;

        $headers = ['Accept: application/json'];
        if ($authenticated) {
            $timestamp = (string) (int) floor(microtime(true) * 1000);
            $signature = Signer::sign((string) $this->privateKeyPem, $timestamp, $method, $signPath);
            $headers[] = 'KALSHI-ACCESS-KEY: ' . $this->keyId;
            $headers[] = 'KALSHI-ACCESS-TIMESTAMP: ' . $timestamp;
            $headers[] = 'KALSHI-ACCESS-SIGNATURE: ' . $signature;
        }

        $encodedBody = null;
        if ($body !== null) {
            $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($encodedBody === false) {
                throw new RuntimeException('Could not encode the request.');
            }
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not start the HTTP client.');
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'KalshiPaperDesk/1.0',
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        }
        if ($encodedBody !== null) {
            $options[CURLOPT_POSTFIELDS] = $encodedBody;
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            if ($authenticated) {
                $this->log($method, $path, null, $curlError);
            }
            throw new KalshiException('Could not reach the Kalshi demo API. ' . $curlError);
        }

        $decoded = json_decode($raw, true);
        $ok = $status >= 200 && $status < 300;
        if ($authenticated) {
            $this->log($method, $path, $status, $ok ? null : $this->summarize($status, $raw, $decoded));
        }
        if (!$ok) {
            throw new KalshiException($this->summarize($status, $raw, $decoded), $status, $decoded);
        }
        if ($decoded === null) {
            return [];
        }
        if (!is_array($decoded)) {
            throw new KalshiException('Kalshi demo returned a response this desk could not read.', $status);
        }

        return $decoded;
    }

    private function assertPath(string $path): void
    {
        if (!preg_match('#^/[A-Za-z0-9_./{}-]{1,180}$#', $path) || str_contains($path, '..')) {
            throw new InvalidArgumentException('Refusing to call that API path.');
        }
    }

    private function summarize(int $status, string $raw, mixed $decoded): string
    {
        $parts = [];
        if (is_array($decoded)) {
            foreach (['message', 'error', 'code', 'details'] as $key) {
                if (!isset($decoded[$key]) || is_array($decoded[$key])) {
                    continue;
                }
                $parts[] = (string) $decoded[$key];
            }
        }
        $detail = $parts !== [] ? implode(' — ', array_unique($parts)) : trim($raw);
        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;
        if (strlen($detail) > 400) {
            $detail = substr($detail, 0, 400) . '…';
        }
        $message = 'Kalshi demo returned HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.');
        if ($status === 401) {
            $message .= ' Create the key while logged into demo.kalshi.co. Live-exchange keys do not work here.';
        }

        return $message;
    }

    private function log(string $method, string $path, ?int $status, ?string $error): void
    {
        if ($this->db === null || $this->userId === null) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO api_log (user_id, method, path, http_status, error_message) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->userId,
            $method,
            substr($path, 0, 255),
            $status,
            $error !== null ? substr($error, 0, 500) : null,
        ]);
    }
}
