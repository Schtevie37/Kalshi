<?php

declare(strict_types=1);

/**
 * Reads one public market from the live trade API.
 * This client has no key, no request body, and no method other than GET.
 */
final class LiveMarket
{
    public const ORIGIN = 'https://external-api.kalshi.com';
    public const PREFIX = '/trade-api/v2';

    public static function marketUrl(string $ticker): string
    {
        $ticker = ticker($ticker);

        return self::ORIGIN . self::PREFIX . '/markets/' . rawurlencode($ticker);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(string $ticker): array
    {
        $url = self::marketUrl($ticker);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not start the HTTP client.');
        }
        $options = [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'KalshiPaperDesk/1.0',
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new KalshiException('Could not reach the live Kalshi market. ' . $curlError);
        }

        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            throw new KalshiException(self::summarize($status, $raw, $decoded), $status, $decoded);
        }
        if (!is_array($decoded)) {
            throw new KalshiException('The live market response could not be read.', $status);
        }
        $market = $decoded['market'] ?? null;
        if (!is_array($market)) {
            throw new KalshiException('The live market response did not include a market.', $status);
        }

        return $market;
    }

    private static function summarize(int $status, string $raw, mixed $decoded): string
    {
        $parts = [];
        if (is_array($decoded)) {
            foreach (['message', 'error', 'code'] as $key) {
                if (!isset($decoded[$key]) || is_array($decoded[$key])) {
                    continue;
                }
                $parts[] = (string) $decoded[$key];
            }
        }
        $detail = $parts !== [] ? implode(' — ', array_unique($parts)) : trim($raw);
        $detail = preg_replace('/\s+/', ' ', $detail) ?? $detail;
        if (strlen($detail) > 300) {
            $detail = substr($detail, 0, 300) . '…';
        }

        return 'The live market returned HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '.');
    }
}
