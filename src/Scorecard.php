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
        $ticker = self::tickerFromInput($tickerRaw);
        $outcome = strtolower(trim($outcomeRaw));
        if ($outcome !== 'yes' && $outcome !== 'no') {
            throw new InvalidArgumentException('Choose YES or NO.');
        }

        $pay = Pricing::parse(self::priceInDollars($payPriceRaw), Pricing::PRICE_SCALE, 'Price');
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

    public static function tickerFromInput(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $raw)) {
            $parts = parse_url($raw);
            if (!is_array($parts)) {
                throw new InvalidArgumentException('That market link could not be read.');
            }
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host !== 'kalshi.com' && $host !== 'www.kalshi.com') {
                throw new InvalidArgumentException('Paste a kalshi.com link, or the market ticker.');
            }
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);
            $fromQuery = '';
            foreach ($query as $key => $value) {
                if (!is_string($key) || strtolower($key) !== 'marketticker' || !is_string($value)) {
                    continue;
                }
                $fromQuery = trim($value);
                break;
            }
            if ($fromQuery !== '') {
                $raw = $fromQuery;
            } else {
                $path = trim((string) ($parts['path'] ?? ''), '/');
                $segments = $path === '' ? [] : explode('/', $path);
                $raw = (string) ($segments[array_key_last($segments)] ?? '');
            }
        }

        return strtoupper(ticker($raw));
    }

    /**
     * Kalshi's site quotes a whole number as cents. A decimal stays in dollars, so 10 and 0.10 are the same price.
     */
    private static function priceInDollars(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{1,2}$/', $raw) && (int) $raw >= 1) {
            return '0.' . str_pad($raw, 2, '0', STR_PAD_LEFT);
        }

        return $raw;
    }

    public static function marketLink(string $ticker): string
    {
        $ticker = strtoupper(trim($ticker));
        if (str_starts_with($ticker, 'KXMVE')) {
            return 'https://kalshi.com/combos?marketTicker=' . rawurlencode($ticker);
        }
        $split = strrpos($ticker, '-');
        $event = $split === false ? $ticker : substr($ticker, 0, $split);

        return 'https://kalshi.com/events/' . rawurlencode($event) . '?contract=' . rawurlencode($ticker);
    }

    /**
     * An event page can list several contracts. One contract is used as-is.
     *
     * @param array<int, array<string, mixed>> $markets
     * @return array<string, mixed>
     */
    public static function onlyMarket(array $markets): array
    {
        $markets = array_values(array_filter($markets, 'is_array'));
        if (count($markets) === 1) {
            return $markets[0];
        }
        $choices = [];
        foreach ($markets as $market) {
            $symbol = strtoupper(trim((string) ($market['ticker'] ?? '')));
            if ($symbol === '') {
                continue;
            }
            $label = trim((string) ($market['yes_sub_title'] ?? ''));
            $choices[] = $label !== '' ? $symbol . ' (' . $label . ')' : $symbol;
        }
        if ($choices === []) {
            throw new InvalidArgumentException('That link did not match a Kalshi market.');
        }

        throw new InvalidArgumentException('That page lists more than one contract. Enter ' . implode(', ', $choices) . '.');
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

    /**
     * @param array<string, mixed> $market
     */
    public static function marketTitle(array $market): ?string
    {
        $title = $market['title'] ?? null;
        if (!is_string($title)) {
            return null;
        }
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        if ($title === '') {
            return null;
        }
        if (mb_strlen($title) > 255) {
            $title = rtrim(mb_substr($title, 0, 254)) . '…';
        }

        return $title;
    }

    /**
     * The live bid for the side the position holds. A missing bid falls back to the last trade.
     *
     * @param array<string, mixed> $market
     */
    public static function currentPrice(array $market, string $outcome): ?string
    {
        $outcome = strtolower(trim($outcome));
        if ($outcome !== 'yes' && $outcome !== 'no') {
            return null;
        }
        $bid = self::quote($market[$outcome === 'yes' ? 'yes_bid_dollars' : 'no_bid_dollars'] ?? null);
        if ($bid !== null) {
            return $bid;
        }
        $last = self::quote($market['last_price_dollars'] ?? null);
        if ($last === null) {
            return null;
        }
        if ($outcome === 'yes') {
            return $last;
        }
        $no = Pricing::DOLLAR - Pricing::parse($last, Pricing::PRICE_SCALE, 'Price');
        if ($no <= 0 || $no >= Pricing::DOLLAR) {
            return null;
        }

        return Pricing::format($no, Pricing::PRICE_SCALE);
    }

    private static function quote(mixed $raw): ?string
    {
        if (!is_string($raw) && !is_numeric($raw)) {
            return null;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        try {
            $units = Pricing::parse($raw, Pricing::PRICE_SCALE, 'Price');
        } catch (InvalidArgumentException) {
            return null;
        }
        if ($units <= 0 || $units >= Pricing::DOLLAR) {
            return null;
        }

        return Pricing::format($units, Pricing::PRICE_SCALE);
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

    /** The game or event day written in a ticker, such as 26OCT11. A year-only ticker uses January 1. */
    public static function eventDate(string $ticker): string
    {
        if (preg_match('/(\d{2})(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)(\d{2})/i', $ticker, $match) === 1) {
            $year = 2000 + (int) $match[1];
            $months = [
                'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
                'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
            ];
            $month = $months[strtoupper($match[2])];
            $day = (int) $match[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        if (preg_match('/(?:^|-)(20\d{2})(?:-|$)/', strtoupper($ticker), $match) === 1) {
            return $match[1] . '-01-01';
        }

        return '';
    }

    public static function comparePositions(mixed $left, mixed $right): int
    {
        $leftRow = is_array($left);
        $rightRow = is_array($right);
        if ($leftRow !== $rightRow) {
            return $leftRow ? -1 : 1;
        }
        if (!$leftRow || !is_array($right)) {
            return 0;
        }
        $leftOpen = self::isOpen($left);
        $rightOpen = self::isOpen($right);
        if ($leftOpen !== $rightOpen) {
            return $leftOpen ? -1 : 1;
        }
        if (!$leftOpen) {
            $byClosed = strcmp(self::whenClosed($right), self::whenClosed($left));
            if ($byClosed !== 0) {
                return $byClosed;
            }
        }
        $byDate = strcmp(self::eventDate((string) ($right['ticker'] ?? '')), self::eventDate((string) ($left['ticker'] ?? '')));
        if ($byDate !== 0) {
            return $byDate;
        }

        return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
    }

    /**
     * Recent is every open position, plus anything that settled today, yesterday, or the day before in Eastern time.
     *
     * @param array<string, mixed> $position
     */
    public static function showsInRecent(array $position, ?DateTimeImmutable $now = null): bool
    {
        if (self::isOpen($position)) {
            return true;
        }
        $closed = self::whenClosed($position);
        if ($closed === '') {
            return false;
        }
        $zone = new DateTimeZone('America/New_York');
        try {
            $settled = new DateTimeImmutable($closed, new DateTimeZone('UTC'));
            $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
        } catch (Exception) {
            return false;
        }
        $settledDay = $settled->setTimezone($zone)->setTime(0, 0);
        $oldest = $now->setTime(0, 0)->modify('-2 days');

        return $settledDay >= $oldest;
    }

    /**
     * @param array<string, mixed> $position
     */
    private static function whenClosed(array $position): string
    {
        return trim((string) ($position['settled_at'] ?? ''));
    }

    /**
     * @param array<string, mixed> $position
     */
    private static function isOpen(array $position): bool
    {
        $result = strtolower(trim((string) ($position['market_result'] ?? '')));

        return $result !== 'yes' && $result !== 'no';
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
