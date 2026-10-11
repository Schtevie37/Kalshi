<?php

declare(strict_types=1);

final class Trading
{
    /**
     * @param array{book_side: string, outcome: string, api_price: string, pay_price: string, count: string} $priced
     * @return array<string, mixed>
     */
    public static function payload(array $priced, string $ticker, string $timeInForce, bool $postOnly, string $clientOrderId): array
    {
        $allowed = ['good_till_canceled', 'immediate_or_cancel', 'fill_or_kill'];
        if (!in_array($timeInForce, $allowed, true)) {
            throw new InvalidArgumentException('Choose a time in force the demo exchange accepts.');
        }
        if ($postOnly && $timeInForce !== 'good_till_canceled') {
            throw new InvalidArgumentException('Post-only orders have to be good till canceled.');
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientOrderId)) {
            throw new InvalidArgumentException('The order ticket id was not valid. Reload the market and try again.');
        }

        return [
            'ticker' => $ticker,
            'client_order_id' => strtolower($clientOrderId),
            'side' => $priced['book_side'],
            'count' => $priced['count'],
            'price' => $priced['api_price'],
            'time_in_force' => $timeInForce,
            'self_trade_prevention_type' => 'taker_at_cross',
            'post_only' => $postOnly,
        ];
    }
}
