<?php

namespace App\Support\Portfolio;

use App\Models\PortfolioSnapshot;
use App\Models\PortfolioTransaction;
use App\Models\SecurityPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Portfolio analysis: allocation, return compared with a benchmark, risk figures, correlation,
 * dividends and rebalancing suggestions.
 */
class Analytics
{
    public function __construct(private MarketData $market) {}

    /**
     * Shares of the value grouped by a key (position, asset type, sector, country, portfolio).
     *
     * @param  list<array<string, mixed>>  $rows  Valuation::rows()
     * @return list<array{label: string, value: float, percent: float}>
     */
    public function allocation(array $rows, string $by): array
    {
        $total = array_sum(array_column($rows, 'value'));

        return collect($rows)
            ->groupBy(fn (array $row) => match ($by) {
                'type' => $row['security']?->asset_type ?? $row['position']->asset_type,
                'sector' => $row['security']?->sector ?: '—',
                'country' => $row['security']?->country ?: '—',
                'portfolio' => $row['position']->portfolio?->name ?? '—',
                'currency' => $row['security']?->currency ?? $row['position']->currency,
                default => $row['position']->name ?: $row['position']->symbol,
            })
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'value' => round($group->sum('value'), 2),
                'percent' => $total > 0 ? round($group->sum('value') / $total * 100, 1) : 0.0,
            ])
            ->sortByDesc('value')->values()->all();
    }

    /**
     * Return in percent over time: the portfolio as gain on the invested amount (so deposits do
     * not count as performance), the benchmark as price change since the first day.
     *
     * @param  list<int>  $portfolioIds
     * @return list<array{label: string, value: float, compare: ?float}>
     */
    public function performance(array $portfolioIds, Carbon $from, ?string $benchmark): array
    {
        $days = PortfolioSnapshot::whereIn('portfolio_id', $portfolioIds)->where('taken_at', '>=', $from)->orderBy('taken_at')->get()
            ->groupBy(fn (PortfolioSnapshot $snapshot) => $snapshot->taken_at->toDateString());
        $latest = [];
        $series = [];

        foreach ($days as $date => $snapshots) {
            foreach ($snapshots as $snapshot) {
                $latest[$snapshot->portfolio_id] = $snapshot;
            }

            $cost = collect($latest)->sum('total_cost');

            if ($cost > 0) {
                $series[$date] = collect($latest)->sum('total_value') / $cost;
            }
        }

        if (count($series) < 2) {
            return [];
        }

        $benchmarkPrices = $benchmark ? $this->market->history($benchmark, Carbon::parse(array_key_first($series)))->keyBy(fn (SecurityPrice $price) => $price->date->toDateString()) : collect();
        $base = reset($series);
        $benchmarkBase = null;
        $lastBenchmark = null;
        $points = [];

        foreach ($series as $date => $ratio) {
            $lastBenchmark = $benchmarkPrices[$date]->close ?? $lastBenchmark;
            $benchmarkBase ??= $lastBenchmark;

            $points[] = [
                'label' => Carbon::parse($date)->format('d.m.y'),
                'value' => round(($ratio / $base - 1) * 100, 2),
                'compare' => $benchmarkBase && $lastBenchmark ? round(($lastBenchmark / $benchmarkBase - 1) * 100, 2) : null,
            ];
        }

        return $points;
    }

    /**
     * Annualised volatility and maximum drawdown of a percent series (performance()).
     *
     * @param  list<array{value: float}>  $points
     * @return array{volatility: ?float, max_drawdown: ?float, best_day: ?float, worst_day: ?float}
     */
    public function risk(array $points): array
    {
        $levels = array_map(fn (array $point) => 1 + $point['value'] / 100, $points);
        $returns = [];

        for ($i = 1; $i < count($levels); $i++) {
            if ($levels[$i - 1] > 0) {
                $returns[] = $levels[$i] / $levels[$i - 1] - 1;
            }
        }

        if (count($returns) < 5) {
            return ['volatility' => null, 'max_drawdown' => null, 'best_day' => null, 'worst_day' => null];
        }

        $mean = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(fn (float $return) => ($return - $mean) ** 2, $returns)) / (count($returns) - 1);
        $peak = $levels[0];
        $drawdown = 0.0;

        foreach ($levels as $level) {
            $peak = max($peak, $level);
            $drawdown = min($drawdown, $peak > 0 ? $level / $peak - 1 : 0);
        }

        return [
            'volatility' => round(sqrt($variance) * sqrt(252) * 100, 1),
            'max_drawdown' => round($drawdown * 100, 1),
            'best_day' => round(max($returns) * 100, 2),
            'worst_day' => round(min($returns) * 100, 2),
        ];
    }

    /**
     * Correlation of the daily returns of up to 12 securities over the last year.
     *
     * @param  list<string>  $symbols
     * @return array{symbols: list<string>, matrix: array<string, array<string, ?float>>}
     */
    public function correlation(array $symbols): array
    {
        $returns = [];

        foreach (array_slice($symbols, 0, 12) as $symbol) {
            $closes = $this->market->history($symbol, now()->subYear())->mapWithKeys(fn (SecurityPrice $price) => [$price->date->toDateString() => $price->close]);

            if ($closes->count() >= 30) {
                $previous = null;
                $series = [];

                foreach ($closes as $date => $close) {
                    if ($previous) {
                        $series[$date] = $close / $previous - 1;
                    }

                    $previous = $close;
                }

                $returns[$symbol] = $series;
            }
        }

        $matrix = [];

        foreach ($returns as $a => $seriesA) {
            foreach ($returns as $b => $seriesB) {
                $matrix[$a][$b] = $a === $b ? 1.0 : $this->pearson($seriesA, $seriesB);
            }
        }

        return ['symbols' => array_keys($returns), 'matrix' => $matrix];
    }

    /**
     * @param  array<string, float>  $a
     * @param  array<string, float>  $b
     */
    private function pearson(array $a, array $b): ?float
    {
        $dates = array_keys(array_intersect_key($a, $b));

        if (count($dates) < 20) {
            return null;
        }

        $x = array_map(fn ($date) => $a[$date], $dates);
        $y = array_map(fn ($date) => $b[$date], $dates);
        $meanX = array_sum($x) / count($x);
        $meanY = array_sum($y) / count($y);
        $covariance = $varianceX = $varianceY = 0.0;

        foreach ($x as $i => $value) {
            $covariance += ($value - $meanX) * ($y[$i] - $meanY);
            $varianceX += ($value - $meanX) ** 2;
            $varianceY += ($y[$i] - $meanY) ** 2;
        }

        return $varianceX > 0 && $varianceY > 0 ? round($covariance / sqrt($varianceX * $varianceY), 2) : null;
    }

    /**
     * Dividends (net of withholding tax and fees, in euros) by year, month and security, plus
     * the trailing twelve months as expectation for the coming year.
     *
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @param  array<string, float>  $heldQuantities  symbol => quantity held now
     * @return array{by_year: array<int, float>, by_month: list<array{label: string, value: float}>, by_security: list<array{symbol: string, name: string, total: float, trailing: float, count: int}>, trailing: float, expected: float}
     */
    public function dividends(Collection $transactions, array $heldQuantities): array
    {
        $dividends = $transactions->where('type', 'dividend')->map(function (PortfolioTransaction $transaction) {
            $rate = $transaction->currency !== 'EUR' && $transaction->exchange_rate > 0 ? $transaction->exchange_rate : 1.0;

            return ['transaction' => $transaction, 'net' => $transaction->cashFlow() / $rate];
        });
        $since = now()->subYear();

        $byMonth = [];

        for ($i = 23; $i >= 0; $i--) {
            $month = now()->startOfMonth()->subMonths($i);
            $byMonth[] = [
                'label' => $month->translatedFormat('M y'),
                'value' => round($dividends->filter(fn (array $row) => $row['transaction']->executed_on->isSameMonth($month))->sum('net'), 2),
            ];
        }

        $bySecurity = $dividends->groupBy(fn (array $row) => $row['transaction']->symbol)->map(fn (Collection $rows, string $symbol) => [
            'symbol' => $symbol,
            'name' => $rows->last()['transaction']->name ?: $symbol,
            'total' => round($rows->sum('net'), 2),
            'trailing' => round($rows->filter(fn (array $row) => $row['transaction']->executed_on->gte($since))->sum('net'), 2),
            'count' => $rows->count(),
        ])->sortByDesc('total')->values()->all();

        $trailing = round($dividends->filter(fn (array $row) => $row['transaction']->executed_on->gte($since))->sum('net'), 2);
        // Expected: trailing dividends of what is still held (sold positions pay nothing).
        $expected = round(collect($bySecurity)->filter(fn (array $row) => ($heldQuantities[$row['symbol']] ?? 0) > 0)->sum('trailing'), 2);

        return [
            'by_year' => $dividends->groupBy(fn (array $row) => $row['transaction']->executed_on->year)->map(fn (Collection $rows) => round($rows->sum('net'), 2))->sortKeys()->all(),
            'by_month' => $byMonth,
            'by_security' => $bySecurity,
            'trailing' => $trailing,
            'expected' => $expected,
        ];
    }

    /**
     * Trades that bring the holdings to the target weights. With $buyOnly only the additional
     * money is distributed (no sales, tax-friendly).
     *
     * @param  array<string, array{name: string, value: float}>  $current  symbol => current value in €
     * @param  array<string, float>  $targets  symbol => target percent
     * @return list<array{symbol: string, name: string, value: float, percent: float, target: float, target_value: float, trade: float}>
     */
    public function rebalance(array $current, array $targets, float $additional = 0.0, bool $buyOnly = false): array
    {
        $total = array_sum(array_column($current, 'value')) + $additional;
        $symbols = array_unique([...array_keys($current), ...array_keys($targets)]);
        $rows = [];

        foreach ($symbols as $symbol) {
            $value = $current[$symbol]['value'] ?? 0.0;
            $target = $targets[$symbol] ?? 0.0;
            $rows[$symbol] = [
                'symbol' => $symbol,
                'name' => $current[$symbol]['name'] ?? $symbol,
                'value' => round($value, 2),
                'percent' => $total - $additional > 0 ? round($value / ($total - $additional) * 100, 1) : 0.0,
                'target' => $target,
                'target_value' => round($total * $target / 100, 2),
                'trade' => round($total * $target / 100 - $value, 2),
            ];
        }

        if ($buyOnly) {
            $gaps = array_filter(array_map(fn (array $row) => max(0, $row['trade']), $rows));
            $sum = array_sum($gaps);

            foreach ($rows as $symbol => &$row) {
                $row['trade'] = $sum > 0 ? round(($gaps[$symbol] ?? 0) / $sum * $additional, 2) : 0.0;
            }

            unset($row);
        }

        return collect($rows)->sortByDesc('target')->values()->all();
    }
}
