<?php

namespace App\Support\Portfolio;

use App\Models\PortfolioPosition;
use App\Models\PortfolioTransaction;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns the holdings into advice along Heiko Thieme's three-thirds strategy ("Drei-Drittel-Strategie"):
 * the depot health against the ground rules (max 3 % per value, around 30 positions over at least
 * five sectors), the three-thirds buy state per position with the price for the next third, and the
 * sell signals measured against the average buy price (+25 %, +35 %, trailing from +30 %, +100 %).
 *
 * The core {@see self::analyse()} works on plain rows so it can be tested without market data; the
 * {@see self::forUser()} helper assembles those rows from the valued positions and the last buy price.
 */
class StrategyCoach
{
    /** Full position = 3 % of the depot, built from three thirds of 1 % each. */
    public const FULL_POSITION_PERCENT = 3.0;

    /** A single value should not weigh more than this (cluster risk above it). */
    public const MAX_WEIGHT_PERCENT = 3.0;

    public const TARGET_POSITIONS = 30;

    public const MIN_SECTORS = 5;

    /** The next third is bought after a further drop of this much below the last buy. */
    public const NEXT_TRANCHE_DROP_PERCENT = 10.0;

    public const NEXT_TRANCHE_DROP_MAX_PERCENT = 15.0;

    public const SELL_FIRST_THIRD_PERCENT = 25.0;

    public const SELL_SECOND_THIRD_PERCENT = 35.0;

    public const TRAILING_STOP_FROM_PERCENT = 30.0;

    public const SELL_ALL_PERCENT = 100.0;

    /**
     * @param  list<array<string, mixed>>  $rows  valued positions (see {@see Valuation::rows()}); each row
     *                                            needs at least symbol/name/sector, value, cost, gain_percent,
     *                                            weight, price and avg_buy_price (read with fallbacks)
     * @param  array<string, float>  $lastBuyPrices  symbol => last buy price in euros, for the next-third trigger
     * @param  array<string, int>  $buyCounts  symbol => number of buy transactions (one third per buy)
     * @return array{health: array{checks: list<array{key: string, label: string, status: string, detail: string}>, positions: int, sectors: int, top_weight: float, score: int}, positions: list<array<string, mixed>>, signals: list<array<string, mixed>>}
     */
    public static function analyse(array $rows, array $lastBuyPrices = [], array $buyCounts = []): array
    {
        $totalCost = array_sum(array_map(fn (array $row) => (float) ($row['cost'] ?? 0), $rows));
        $positions = [];
        $signals = [];
        $sectors = [];

        foreach ($rows as $row) {
            $symbol = (string) ($row['symbol'] ?? $row['position']->symbol ?? '');
            $name = (string) ($row['name'] ?? $row['position']->name ?? $symbol);
            $sector = $row['sector'] ?? ($row['security']->sector ?? null);
            $weight = (float) ($row['weight'] ?? 0);
            $cost = (float) ($row['cost'] ?? 0);
            $gainPercent = isset($row['gain_percent']) ? (float) $row['gain_percent'] : null;
            $price = isset($row['price']) ? (float) $row['price'] : null;
            $avgBuyPrice = (float) ($row['avg_buy_price'] ?? $row['position']->avg_buy_price ?? 0);

            if ($sector !== null && $sector !== '') {
                $sectors[(string) $sector] = true;
            }

            // Three-thirds state from the number of buys (one third per purchase): a position built in
            // one buy is on its first third, in three or more buys it is full. Manually tracked
            // positions without transactions count as one third.
            $costWeight = $totalCost > 0 ? $cost / $totalCost * 100 : 0.0;
            $thirdsFilled = max(1, min(3, (int) ($buyCounts[$symbol] ?? 1)));
            $clusterRisk = $weight > self::MAX_WEIGHT_PERCENT;

            $nextTranche = null;
            if ($thirdsFilled < 3) {
                $reference = $lastBuyPrices[$symbol] ?? $avgBuyPrice;
                if ($reference > 0) {
                    $trigger = round($reference * (1 - self::NEXT_TRANCHE_DROP_PERCENT / 100), 2);
                    $triggerMax = round($reference * (1 - self::NEXT_TRANCHE_DROP_MAX_PERCENT / 100), 2);
                    $nextTranche = [
                        'third' => $thirdsFilled + 1,
                        'reference_price' => round($reference, 2),
                        'trigger_price' => $trigger,
                        'trigger_price_max' => $triggerMax,
                        'reached' => $price !== null && $price <= $trigger,
                    ];
                }
            }

            $sellSignals = self::sellSignals($gainPercent);

            $positions[] = [
                'symbol' => $symbol,
                'name' => $name,
                'sector' => $sector,
                'weight' => round($weight, 2),
                'cost_weight' => round($costWeight, 2),
                'gain_percent' => $gainPercent,
                'price' => $price,
                'avg_buy_price' => round($avgBuyPrice, 2),
                'thirds_filled' => $thirdsFilled,
                'cluster_risk' => $clusterRisk,
                'next_tranche' => $nextTranche,
                'sell_signals' => $sellSignals,
            ];

            if ($clusterRisk) {
                $signals[] = ['type' => 'cluster', 'symbol' => $symbol, 'name' => $name, 'weight' => round($weight, 2)];
            }

            if ($nextTranche !== null && $nextTranche['reached']) {
                $signals[] = ['type' => 'buy_tranche', 'symbol' => $symbol, 'name' => $name, 'third' => $nextTranche['third'], 'price' => $price];
            }

            foreach ($sellSignals as $signal) {
                if ($signal['triggered']) {
                    $signals[] = ['type' => 'sell', 'symbol' => $symbol, 'name' => $name, 'level' => $signal['key'], 'label' => $signal['label'], 'gain_percent' => $gainPercent];
                }
            }
        }

        return [
            'health' => self::health($positions, count($sectors)),
            'positions' => $positions,
            'signals' => $signals,
        ];
    }

    /**
     * Sell signals against the average buy price, Thieme-style.
     *
     * @return list<array{key: string, label: string, threshold: float, triggered: bool}>
     */
    private static function sellSignals(?float $gainPercent): array
    {
        $levels = [
            ['key' => 'first_third', 'label' => __('Sell the first third (+:percent %)', ['percent' => (int) self::SELL_FIRST_THIRD_PERCENT]), 'threshold' => self::SELL_FIRST_THIRD_PERCENT],
            ['key' => 'second_third', 'label' => __('Sell the second third (+:percent %)', ['percent' => (int) self::SELL_SECOND_THIRD_PERCENT]), 'threshold' => self::SELL_SECOND_THIRD_PERCENT],
            ['key' => 'trailing', 'label' => __('Let the rest run with a trailing stop (from +:percent %)', ['percent' => (int) self::TRAILING_STOP_FROM_PERCENT]), 'threshold' => self::TRAILING_STOP_FROM_PERCENT],
            ['key' => 'all', 'label' => __('Take the profit at the latest (+:percent %)', ['percent' => (int) self::SELL_ALL_PERCENT]), 'threshold' => self::SELL_ALL_PERCENT],
        ];

        return array_map(fn (array $level) => $level + ['triggered' => $gainPercent !== null && $gainPercent >= $level['threshold']], $levels);
    }

    /**
     * Health of the depot against the ground rules.
     *
     * @param  list<array<string, mixed>>  $positions
     * @return array{checks: list<array{key: string, label: string, status: string, detail: string}>, positions: int, sectors: int, top_weight: float, score: int}
     */
    private static function health(array $positions, int $sectorCount): array
    {
        $count = count($positions);
        $topWeight = $positions === [] ? 0.0 : max(array_map(fn (array $position) => (float) $position['weight'], $positions));
        $clustered = array_values(array_filter($positions, fn (array $position) => $position['cluster_risk']));

        $checks = [];

        $checks[] = [
            'key' => 'cluster',
            'label' => __('Maximum 3 % per value'),
            'status' => $clustered === [] ? 'ok' : 'bad',
            'detail' => $clustered === []
                ? __('No value weighs more than 3 %.')
                : __(':count values are above 3 %: :names', [
                    'count' => count($clustered),
                    'names' => collect($clustered)->map(fn (array $position) => $position['name'].' ('.number_format((float) $position['weight'], 1, ',', '.').' %)')->implode(', '),
                ]),
        ];

        $checks[] = [
            'key' => 'sectors',
            'label' => __('At least five sectors'),
            'status' => $sectorCount >= self::MIN_SECTORS ? 'ok' : ($sectorCount >= 3 ? 'warn' : 'bad'),
            'detail' => __(':count of at least :min sectors covered.', ['count' => $sectorCount, 'min' => self::MIN_SECTORS]),
        ];

        $checks[] = [
            'key' => 'positions',
            'label' => __('Around 30 positions'),
            'status' => $count >= 20 ? 'ok' : ($count >= 8 ? 'warn' : 'bad'),
            'detail' => __(':count positions (around :target are the goal for broad diversification).', ['count' => $count, 'target' => self::TARGET_POSITIONS]),
        ];

        $score = $checks === [] ? 0 : (int) round(collect($checks)->avg(fn (array $check) => match ($check['status']) {
            'ok' => 100,
            'warn' => 55,
            default => 10,
        }));

        return [
            'checks' => $checks,
            'positions' => $count,
            'sectors' => $sectorCount,
            'top_weight' => round($topWeight, 2),
            'score' => $score,
        ];
    }

    /**
     * Builds the rows and last buy prices for a user and runs the analysis.
     *
     * @return array{health: array<string, mixed>, positions: list<array<string, mixed>>, signals: list<array<string, mixed>>}
     */
    public static function forUser(User $user, Valuation $valuation): array
    {
        $portfolioIds = $user->portfolios()->pluck('id');
        $positions = PortfolioPosition::whereIn('portfolio_id', $portfolioIds)->with('security')->get();

        $rows = collect($valuation->rows($positions))->map(fn (array $row) => $row + [
            'symbol' => $row['position']->symbol,
            'name' => $row['position']->name,
            'sector' => $row['security']?->sector,
            'avg_buy_price' => $row['position']->avg_buy_price,
        ])->all();

        [$lastBuyPrices, $buyCounts] = self::buyHistory($portfolioIds);

        return self::analyse($rows, $lastBuyPrices, $buyCounts);
    }

    /**
     * Per symbol the price of the most recent buy (in euros, for the next-third trigger) and the
     * number of buys (one third per buy).
     *
     * @param  Collection<int, int>  $portfolioIds
     * @return array{0: array<string, float>, 1: array<string, int>}
     */
    private static function buyHistory(Collection $portfolioIds): array
    {
        $buys = PortfolioTransaction::whereIn('portfolio_id', $portfolioIds)
            ->whereIn('type', ['buy', 'transfer_in'])
            ->orderBy('executed_on')
            ->orderBy('id')
            ->get(['symbol', 'price', 'exchange_rate', 'currency']);

        $lastBuyPrices = [];
        $buyCounts = [];

        foreach ($buys as $transaction) {
            $rate = $transaction->currency !== 'EUR' && $transaction->exchange_rate > 0 ? $transaction->exchange_rate : 1.0;
            $lastBuyPrices[$transaction->symbol] = round((float) $transaction->price / $rate, 4);
            $buyCounts[$transaction->symbol] = ($buyCounts[$transaction->symbol] ?? 0) + 1;
        }

        return [$lastBuyPrices, $buyCounts];
    }
}
