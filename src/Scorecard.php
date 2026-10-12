<?php

declare(strict_types=1);

/**
 * Records a price and size the user already traded, then scores it when the market result is yes or no.
 * Prices use the same fixed-point dollars as order entry. Fees are not included.
 */
final class Scorecard
{
    /**
     * @return array{ticker: string, outcome: string, pay_price: string, count: string}
     */
    public static function entry(string $tickerRaw, string $outcomeRaw, string $payPriceRaw, string $countRaw): array
    {
        $ticker = strtoupper(ticker($tickerRaw));
        $outcome = strtolower(trim($outcomeRaw));
        if ($outcome !== 'yes' && $outcome !== 'no') {
            throw new InvalidArgumentException('Choose YES or NO.');
        }

        $pay = Pricing::parse($payPriceRaw, Pricing::PRICE_SCALE, 'Price');
        if ($pay <= 0 || $pay >= Pricing::DOLLAR) {
            throw new InvalidArgumentException('Price must be between $0.0001 and $0.9999.');
        }

        $count = Pricing::parse($countRaw, Pricing::COUNT_SCALE, 'Contracts');
        if ($count <= 0) {
            throw new InvalidArgumentException('Enter at least 0.01 contracts.');
        }
        if ($count > 100000) {
            throw new InvalidArgumentException('Contract count is above the 1,000.00 limit for this desk.');
        }

        return [
            'ticker' => $ticker,
            'outcome' => $outcome,
            'pay_price' => Pricing::format($pay, Pricing::PRICE_SCALE),
            'count' => Pricing::format($count, Pricing::COUNT_SCALE),
        ];
    }

    /**
     * @param array<string, mixed> $market
     */
    public static function marketResult(array $market): ?string
    {
        $result = $market['result'] ?? null;
        if (!is_string($result)) {
            return null;
        }
        $result = strtolower(trim($result));

        return $result === 'yes' || $result === 'no' ? $result : null;
    }

    public static function stake(string $payPrice, string $count): string
    {
        $pay = Pricing::parse($payPrice, Pricing::PRICE_SCALE, 'Price');
        $contracts = Pricing::parse($count, Pricing::COUNT_SCALE, 'Contracts');

        return self::micro($pay * $contracts);
    }

    /**
     * A winning contract pays $1. A losing contract pays $0.
     *
     * @return array{won: bool, cost: string, payout: string, profit: string}
     */
    public static function settle(string $outcome, string $payPrice, string $count, string $result): array
    {
        $outcome = strtolower(trim($outcome));
        $result = strtolower(trim($result));
        if (($outcome !== 'yes' && $outcome !== 'no') || ($result !== 'yes' && $result !== 'no')) {
            throw new InvalidArgumentException('Settlement needs a yes or no side and result.');
        }

        $pay = Pricing::parse($payPrice, Pricing::PRICE_SCALE, 'Price');
        $contracts = Pricing::parse($count, Pricing::COUNT_SCALE, 'Contracts');
        $won = $outcome === $result;
        $cost = $pay * $contracts;
        $payout = $won ? $contracts * Pricing::DOLLAR : 0;

        return [
            'won' => $won,
            'cost' => self::micro($cost),
            'payout' => self::micro($payout),
            'profit' => self::micro($payout - $cost),
        ];
    }

    /**
     * Success and return use settled positions only. Open stake stays in placed and open_stake.
     *
     * @param array<int, array<string, mixed>> $positions
     * @return array{count: int, open: int, settled: int, wins: int, losses: int, success_rate: ?string, placed: string, open_stake: string, payout: string, profit: string, average_return: ?string}
     */
    public static function totals(array $positions): array
    {
        $count = 0;
        $open = 0;
        $wins = 0;
        $placed = 0;
        $openStake = 0;
        $settledCost = 0;
        $payout = 0;
        $profit = 0;
        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }
            $pay = Pricing::parse((string) ($position['pay_price'] ?? ''), Pricing::PRICE_SCALE, 'Price');
            $contracts = Pricing::parse((string) ($position['contract_count'] ?? ''), Pricing::COUNT_SCALE, 'Contracts');
            $cost = $pay * $contracts;
            $count++;
            $placed += $cost;
            $result = strtolower(trim((string) ($position['market_result'] ?? '')));
            if ($result !== 'yes' && $result !== 'no') {
                $open++;
                $openStake += $cost;
                continue;
            }
            $won = strtolower(trim((string) ($position['outcome'] ?? ''))) === $result;
            if ($won) {
                $wins++;
            }
            $rowPayout = $won ? $contracts * Pricing::DOLLAR : 0;
            $settledCost += $cost;
            $payout += $rowPayout;
            $profit += $rowPayout - $cost;
        }
        $settled = $count - $open;

        return [
            'count' => $count,
            'open' => $open,
            'settled' => $settled,
            'wins' => $wins,
            'losses' => $settled - $wins,
            'success_rate' => $settled > 0 ? self::ratioPercent($wins, $settled) : null,
            'placed' => self::micro($placed),
            'open_stake' => self::micro($openStake),
            'payout' => self::micro($payout),
            'profit' => self::micro($profit),
            'average_return' => $settledCost > 0 ? self::ratioPercent($profit, $settledCost) : null,
        ];
    }

    private static function micro(int $micro): string
    {
        $negative = $micro < 0;
        $micro = abs($micro);
        $units = intdiv($micro + 50, 100);

        return ($negative ? '-' : '') . Pricing::format($units, Pricing::PRICE_SCALE);
    }

    private static function ratioPercent(int $numerator, int $denominator): string
    {
        $negative = $numerator < 0;
        $numerator = abs($numerator);
        $scaled = intdiv($numerator * 1000 + intdiv($denominator, 2), $denominator);

        return ($negative ? '-' : '') . intdiv($scaled, 10) . '.' . ($scaled % 10);
    }
}
