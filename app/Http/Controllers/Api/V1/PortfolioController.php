<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Support\Portfolio\Valuation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioController extends ApiController
{
    public function __construct(private Valuation $valuation) {}

    public function index(): JsonResponse
    {
        return $this->ok($this->user()->portfolios()->get(['id', 'name', 'color', 'broker', 'description']));
    }

    public function positions(Portfolio $portfolio): JsonResponse
    {
        $this->authorizePortfolio($portfolio);

        return $this->ok(array_map(fn (array $row) => [
            'id' => $row['position']->id,
            'portfolio_id' => $portfolio->id,
            'symbol' => $row['position']->symbol,
            'name' => $row['position']->name ?: $row['security']?->name ?: $row['position']->symbol,
            'quantity' => $row['position']->quantity,
            'avg_buy_price' => $row['position']->avg_buy_price,
            'current_price' => $row['price'] ?? $row['position']->avg_buy_price,
            'current_value' => $row['value'],
            'cost_basis' => $row['cost'],
            'gain_loss' => $row['gain'],
            'gain_loss_pct' => $row['gain_percent'] ?? 0.0,
            'change_1d' => $row['day_change'],
            'change_1d_pct' => $row['day_change_percent'] ?? 0.0,
            'weight' => $row['weight'],
            'currency' => 'EUR',
            'quote_currency' => $row['security']?->currency,
            'asset_type' => $row['position']->asset_type,
        ], $this->rows($portfolio)));
    }

    public function summary(Portfolio $portfolio): JsonResponse
    {
        $this->authorizePortfolio($portfolio);
        $rows = $this->rows($portfolio);
        $summary = $this->valuation->summary($rows);
        $performer = fn (array $row) => [
            'symbol' => $row['position']->symbol,
            'name' => $row['position']->name ?: $row['position']->symbol,
            'gain_loss' => $row['gain'],
            'gain_loss_pct' => $row['gain_percent'] ?? 0.0,
            'current_value' => $row['value'],
            'current_price' => $row['price'],
            'currency' => 'EUR',
        ];
        $byGain = collect($rows)->sortByDesc(fn (array $row) => $row['gain_percent'] ?? 0)->values();

        return $this->ok([
            'total_value' => $summary['value'],
            'total_cost' => $summary['cost'],
            'total_gain' => $summary['gain'],
            'total_gain_pct' => $summary['gain_percent'] ?? 0.0,
            'day_change' => $summary['day_change'],
            'day_change_pct' => $summary['day_change_percent'] ?? 0.0,
            'position_count' => $summary['count'],
            'top_3' => $byGain->take(3)->map($performer)->all(),
            'bottom_3' => $byGain->reverse()->take(3)->values()->map($performer)->all(),
        ]);
    }

    public function transactions(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizePortfolio($portfolio);
        $limit = min(200, max(1, (int) $request->query('limit', 20)));
        $query = $portfolio->transactions();

        return $this->ok([
            'transactions' => (clone $query)->orderByDesc('executed_on')->orderByDesc('id')->limit($limit)->get()->map(fn (PortfolioTransaction $transaction) => [
                'id' => $transaction->id,
                'symbol' => $transaction->symbol,
                'name' => $transaction->name,
                'type' => $transaction->type,
                'quantity' => (float) $transaction->quantity,
                'price' => (float) $transaction->price,
                'currency' => $transaction->currency,
                'fees' => (float) $transaction->fees,
                'transaction_date' => $transaction->executed_on?->toDateString(),
            ]),
            'total' => $query->count(),
        ]);
    }

    /**
     * Value over time; range 1w, 1m, 3m, 6m, 1y, 5y or max.
     */
    public function chart(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizePortfolio($portfolio);
        $range = (string) $request->query('range', '1m');
        $from = match ($range) {
            '1w' => now()->subWeek(),
            '3m' => now()->subMonths(3),
            '6m' => now()->subMonths(6),
            '1y' => now()->subYear(),
            '5y' => now()->subYears(5),
            'max' => now()->subYears(50),
            default => now()->subMonth(),
        };

        return $this->ok(array_map(
            fn (array $point) => ['date' => $point['at'], 'value' => $point['value'], 'cost' => $point['cost']],
            $this->valuation->chart([$portfolio->id], $from, intraday: $range === '1w'),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Portfolio $portfolio): array
    {
        return $this->valuation->rows($portfolio->positions()->get());
    }

    private function authorizePortfolio(Portfolio $portfolio): void
    {
        abort_unless($portfolio->user_id === $this->user()->id, 404);
    }
}
