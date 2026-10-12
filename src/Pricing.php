<?php

declare(strict_types=1);

/**
 * Prices are fixed-point dollars. Kalshi's create-order call always quotes the YES book:
 * bid buys YES at that price, ask sells YES at that price (the same exposure as buying NO at $1 minus the price).
 */
final class Pricing
{
    public const PRICE_SCALE = 4;
    public const COUNT_SCALE = 2;
    public const DOLLAR = 10000;

    /**
     * @param array<string, mixed> $market
     * @return array{book_side: string, outcome: string, api_price: string, pay_price: string, count: string}
     */
    public static function order(string $outcome, string $payPriceRaw, string $countRaw, array $market): array
    {
        $outcome = strtolower(trim($outcome));
        if ($outcome !== 'yes' && $outcome !== 'no') {
            throw new InvalidArgumentException('Choose YES or NO.');
        }

        $pay = self::parse($payPriceRaw, self::PRICE_SCALE, 'Limit price');
        if ($pay <= 0 || $pay >= self::DOLLAR) {
            throw new InvalidArgumentException('Limit price must be between $0.0001 and $0.9999.');
        }

        $count = self::parse($countRaw, self::COUNT_SCALE, 'Contracts');
        if ($count <= 0) {
            throw new InvalidArgumentException('Enter at least 0.01 contracts.');
        }
        if ($count > 100000) {
            throw new InvalidArgumentException('Contract count is above the 1,000.00 limit for this desk.');
        }

        $apiPrice = $outcome === 'yes' ? $pay : self::DOLLAR - $pay;
        self::assertOnTick($apiPrice, $market);

        return [
            'book_side' => $outcome === 'yes' ? 'bid' : 'ask',
            'outcome' => $outcome,
            'api_price' => self::format($apiPrice, self::PRICE_SCALE),
            'pay_price' => self::format($pay, self::PRICE_SCALE),
            'count' => self::format($count, self::COUNT_SCALE),
        ];
    }

    /**
     * @param array<string, mixed> $market
     */
    public static function assertOnTick(int $priceUnits, array $market): void
    {
        $ranges = $market['price_ranges'] ?? null;
        if (!is_array($ranges) || $ranges === []) {
            return;
        }

        foreach ($ranges as $range) {
            if (!is_array($range)) {
                continue;
            }
            $start = self::parse((string) ($range['start'] ?? '0'), self::PRICE_SCALE, 'Price range');
            $end = self::parse((string) ($range['end'] ?? '1'), self::PRICE_SCALE, 'Price range');
            $step = self::parse((string) ($range['step'] ?? '0.0001'), self::PRICE_SCALE, 'Price step');
            if ($step <= 0) {
                continue;
            }
            $inRange = $priceUnits >= $start && $priceUnits <= $end;
            $onBoundary = $priceUnits === $end && $end === self::DOLLAR;
            if ($inRange && !$onBoundary && (($priceUnits - $start) % $step) === 0) {
                return;
            }
        }

        throw new InvalidArgumentException('That price is not on this market\'s tick size. Check the price ladder and try a price the exchange accepts.');
    }

    public static function parse(string $raw, int $scale, string $label): int
    {
        $raw = trim($raw);
        if (!preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)$/', $raw)) {
            throw new InvalidArgumentException($label . ' must be a positive number.');
        }
        [$whole, $frac] = array_pad(explode('.', $raw, 2), 2, '');
        if (strlen($frac) > $scale) {
            $extra = substr($frac, $scale);
            if (strspn($extra, '0') !== strlen($extra)) {
                throw new InvalidArgumentException($label . ' supports at most ' . $scale . ' decimal places.');
            }
        }
        $whole = ltrim($whole, '0');
        if ($whole === '') {
            $whole = '0';
        }
        if (strlen($whole) > 7) {
            throw new InvalidArgumentException($label . ' is too large.');
        }
        $frac = substr(str_pad($frac, $scale, '0'), 0, $scale);

        return ((int) $whole * (10 ** $scale)) + (int) $frac;
    }

    public static function format(int $units, int $scale): string
    {
        $negative = $units < 0;
        $units = abs($units);
        $base = 10 ** $scale;
        $whole = intdiv($units, $base);
        $frac = $units % $base;

        return ($negative ? '-' : '') . $whole . '.' . str_pad((string) $frac, $scale, '0', STR_PAD_LEFT);
    }
}
