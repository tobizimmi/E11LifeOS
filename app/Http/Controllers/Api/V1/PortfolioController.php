<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioTransaction;
use App\Support\Portfolio\StrategyCoach;
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

    /**
     * The overview over all portfolios (like the web start page): totals, value over time, every
     * depot, the allocation by asset class and all positions with their weight.
     */
    public function overview(Request $request): JsonResponse
    {
        $range = (string) $request->query('range', '3m');
        $from = match ($range) {
            '1w' => now()->subWeek(),
            '1m' => now()->subMonth(),
            '1y' => now()->subYear(),
            'max' => now()->subYears(50),
            default => now()->subMonths(3),
        };
        $depots = Portfolio::where('user_id', $this->user()->id)->orderBy('id')->get();
        $positions = PortfolioPosition::whereIn('portfolio_id', $depots->modelKeys())->get();
        $rows = $this->valuation->rows($positions);
        $summary = $this->valuation->summary($rows);

        $perDepot = [];
        foreach ($rows as $row) {
            $perDepot[$row['position']->portfolio_id][] = $row;
        }

        $depotCards = [];
        $names = [];
        foreach ($depots as $depot) {
            $names[$depot->id] = $depot->name;
            $depotSummary = $this->valuation->summary($perDepot[$depot->id] ?? []);
            $depotCards[] = [
                'id' => $depot->id,
                'name' => $depot->name,
                'color' => $depot->color,
                'value' => $depotSummary['value'],
                'gain' => $depotSummary['gain'],
                'gain_percent' => $depotSummary['gain_percent'],
                'day_change' => $depotSummary['day_change'],
                'count' => $depotSummary['count'],
            ];
        }

        $classes = [];
        foreach ($rows as $row) {
            $type = (string) ($row['position']->asset_type ?: 'other');
            $classes[$type] = ($classes[$type] ?? 0.0) + $row['value'];
        }
        arsort($classes);

        return $this->ok([
            'summary' => $summary,
            'chart' => array_map(
                fn (array $point) => ['date' => $point['at'], 'value' => $point['value'], 'cost' => $point['cost']],
                $this->valuation->chart($depots->modelKeys(), $from, intraday: $range === '1w'),
            ),
            'depots' => $depotCards,
            'allocation' => collect($classes)->map(fn (float $value, string $type) => [
                'type' => $type,
                'value' => round($value, 2),
                'percent' => $summary['value'] > 0 ? round($value / $summary['value'] * 100, 1) : 0.0,
            ])->values()->all(),
            'positions' => array_map(fn (array $row) => [
                'id' => $row['position']->id,
                'portfolio_id' => $row['position']->portfolio_id,
                'portfolio' => $names[$row['position']->portfolio_id] ?? '',
                'symbol' => $row['position']->symbol,
                'name' => $row['position']->name ?: $row['security']?->name ?: $row['position']->symbol,
                'quantity' => $row['position']->quantity,
                'price' => $row['price'],
                'value' => $row['value'],
                'gain' => $row['gain'],
                'gain_percent' => $row['gain_percent'],
                'day_change_percent' => $row['day_change_percent'],
                'weight' => $row['weight'],
                'needs_cost' => $row['position']->needsManualCost(),
            ], $rows),
        ]);
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
            'avg_buy_price' => $row['position']->effectiveBuyPrice(),
            'manual' => (bool) $row['position']->manual,
            'needs_cost' => $row['position']->needsManualCost(),
            'cost_stale' => (bool) $row['position']->cost_basis_override_stale,
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

    /**
     * Three-thirds strategy coach across all portfolios of the user: depot health, three-thirds buy
     * state and sell signals (see {@see StrategyCoach}).
     */
    public function strategy(): JsonResponse
    {
        return $this->ok(StrategyCoach::forUser($this->user(), $this->valuation));
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

    /**
     * Adds a manually tracked position to a portfolio (e.g. entered offline in the app).
     */
    public function storePosition(Request $request, Portfolio $portfolio): JsonResponse
    {
        $this->authorizePortfolio($portfolio);
        $data = $this->validatePosition($request);

        $position = PortfolioPosition::create($data + ['portfolio_id' => $portfolio->id, 'manual' => true]);

        return $this->ok($this->positionData($position), 201);
    }

    public function updatePosition(Request $request, PortfolioPosition $position): JsonResponse
    {
        $this->authorizePosition($position);
        $data = $this->validatePosition($request, required: false);

        // Positions built from transactions keep their quantity; the purchase price can be corrected by hand
        // (a persistent override that survives the next recalculation, plus optional purchase fees).
        if (! $position->manual) {
            $fees = (float) $request->validate(['fees' => ['nullable', 'numeric', 'min:0']])['fees'];
            $price = array_key_exists('avg_buy_price', $data) && (float) $position->quantity > 0
                ? round((float) $data['avg_buy_price'] + $fees / (float) $position->quantity, 4)
                : $position->cost_basis_override;
            $position->update(['notes' => $data['notes'] ?? $position->notes, 'cost_basis_override' => $price, 'cost_basis_override_stale' => false]);
        } else {
            $position->update($data);
        }

        return $this->ok($this->positionData($position->fresh()));
    }

    public function destroyPosition(PortfolioPosition $position): JsonResponse
    {
        $this->authorizePosition($position);
        $position->delete();

        return $this->ok(['id' => $position->id]);
    }

    /**
     * @return array{symbol?: string, name?: ?string, isin?: ?string, quantity?: float, avg_buy_price?: float, currency?: ?string, asset_type?: ?string, notes?: ?string}
     */
    private function validatePosition(Request $request, bool $required = true): array
    {
        return $request->validate([
            'symbol' => [$required ? 'required' : 'sometimes', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:255'],
            'isin' => ['nullable', 'string', 'max:20'],
            'quantity' => [$required ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'avg_buy_price' => [$required ? 'required' : 'sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'asset_type' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array{id: int, portfolio_id: int, symbol: string, name: ?string, isin: ?string, quantity: float, avg_buy_price: float, currency: ?string, asset_type: ?string, notes: ?string, manual: bool}
     */
    private function positionData(PortfolioPosition $position): array
    {
        return [
            'id' => $position->id,
            'portfolio_id' => (int) $position->portfolio_id,
            'symbol' => $position->symbol,
            'name' => $position->name,
            'isin' => $position->isin,
            'quantity' => (float) $position->quantity,
            'avg_buy_price' => $position->effectiveBuyPrice(),
            'currency' => $position->currency,
            'asset_type' => $position->asset_type,
            'notes' => $position->notes,
            'manual' => (bool) $position->manual,
            'needs_cost' => $position->needsManualCost(),
            'cost_stale' => (bool) $position->cost_basis_override_stale,
        ];
    }

    private function authorizePortfolio(Portfolio $portfolio): void
    {
        abort_unless($portfolio->user_id === $this->user()->id, 404);
    }

    private function authorizePosition(PortfolioPosition $position): void
    {
        abort_unless(
            Portfolio::whereKey($position->portfolio_id)->where('user_id', $this->user()->id)->exists(),
            404
        );
    }
}
