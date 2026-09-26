<?php

namespace App\Support\Portfolio;

use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSnapshot;
use App\Models\Security;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Values positions in euros with the latest quotes and sums them up.
 */
class Valuation
{
    public function __construct(private MarketData $market) {}

    /**
     * @param  Collection<int, PortfolioPosition>  $positions
     * @return list<array{position: PortfolioPosition, security: ?Security, price: ?float, value: float, cost: float, gain: float, gain_percent: ?float, day_change: float, day_change_percent: ?float, weight: float}>
     */
    public function rows(Collection $positions, bool $refresh = false): array
    {
        $quotes = $this->market->quotes($positions->pluck('symbol')->all(), $refresh);
        $rows = [];

        foreach ($positions as $position) {
            $security = $quotes[$position->symbol] ?? null;
            $price = $security?->last_price ? $this->market->toEuro($security->last_price, $security->currency) : null;
            $previous = $security?->previous_close ? $this->market->toEuro($security->previous_close, $security->currency) : null;
            $cost = $this->market->toEuro($position->costBasis(), $position->currency);
            // Without a quote the position counts at cost, so totals stay meaningful.
            $value = $price !== null ? round($price * $position->quantity, 2) : $cost;
            $dayChange = $price !== null && $previous ? round(($price - $previous) * $position->quantity, 2) : 0.0;

            $rows[] = [
                'position' => $position,
                'security' => $security,
                'price' => $price !== null ? round($price, 4) : null,
                'value' => $value,
                'cost' => round($cost, 2),
                'gain' => round($value - $cost, 2),
                'gain_percent' => $cost > 0 ? round(($value - $cost) / $cost * 100, 2) : null,
                'day_change' => $dayChange,
                'day_change_percent' => $previous ? round(($price - $previous) / $previous * 100, 2) : null,
                'weight' => 0.0,
            ];
        }

        $total = array_sum(array_column($rows, 'value'));

        foreach ($rows as &$row) {
            $row['weight'] = $total > 0 ? round($row['value'] / $total * 100, 2) : 0.0;
        }

        unset($row);
        usort($rows, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{value: float, cost: float, gain: float, gain_percent: ?float, day_change: float, day_change_percent: ?float, count: int}
     */
    public function summary(array $rows): array
    {
        $value = round(array_sum(array_column($rows, 'value')), 2);
        $cost = round(array_sum(array_column($rows, 'cost')), 2);
        $dayChange = round(array_sum(array_column($rows, 'day_change')), 2);

        return [
            'value' => $value,
            'cost' => $cost,
            'gain' => round($value - $cost, 2),
            'gain_percent' => $cost > 0 ? round(($value - $cost) / $cost * 100, 2) : null,
            'day_change' => $dayChange,
            'day_change_percent' => $value - $dayChange > 0 ? round($dayChange / ($value - $dayChange) * 100, 2) : null,
            'count' => count($rows),
        ];
    }

    /**
     * Records the current value (at most every $throttleMinutes).
     */
    public function snapshot(Portfolio $portfolio, int $throttleMinutes = 6): ?PortfolioSnapshot
    {
        $last = $portfolio->snapshots()->reorder('taken_at', 'desc')->first();

        if ($last && $last->taken_at->gt(now()->subMinutes($throttleMinutes))) {
            return null;
        }

        $summary = $this->summary($this->rows($portfolio->positions()->get()));

        if ($summary['count'] === 0 && ! $last) {
            return null;
        }

        return $portfolio->snapshots()->create(['taken_at' => now(), 'total_value' => $summary['value'], 'total_cost' => $summary['cost']]);
    }

    /**
     * Value over time of several portfolios: all snapshots for a week, else the last one per day.
     *
     * @param  list<int>  $portfolioIds
     * @return list<array{at: string, value: float, cost: float}>
     */
    public function chart(array $portfolioIds, Carbon $from, bool $intraday = false): array
    {
        $snapshots = PortfolioSnapshot::whereIn('portfolio_id', $portfolioIds)->where('taken_at', '>=', $from)->orderBy('taken_at')->get();

        if ($snapshots->isEmpty()) {
            return [];
        }

        // Per portfolio the latest value in each bucket, then summed over portfolios.
        $bucket = fn (PortfolioSnapshot $snapshot) => $intraday ? $snapshot->taken_at->format('Y-m-d H:i') : $snapshot->taken_at->toDateString();
        $latest = [];
        $points = [];

        foreach ($snapshots->groupBy($bucket) as $key => $group) {
            foreach ($group as $snapshot) {
                $latest[$snapshot->portfolio_id] = $snapshot;
            }

            $points[] = [
                'at' => (string) $key,
                'value' => round(collect($latest)->sum('total_value'), 2),
                'cost' => round(collect($latest)->sum('total_cost'), 2),
            ];
        }

        return $points;
    }
}
