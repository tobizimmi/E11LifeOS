<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Portfolio;
use App\Models\PortfolioPosition;
use App\Models\PortfolioSetting;
use App\Models\PortfolioTransaction;
use App\Models\Security;
use App\Models\TargetAllocation;
use App\Support\Portfolio\Analytics;
use App\Support\Portfolio\TaxReport;
use App\Support\Portfolio\Valuation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Evaluations of the portfolios for the app: dividends, taxes, analysis and rebalancing. Portfolios
 * are personal – everything is limited to the signed-in member.
 */
class PortfolioReportsController extends ApiController
{
    public function dividends(Request $request, Analytics $analytics): JsonResponse
    {
        $ids = $this->portfolioIds($request);
        $positions = PortfolioPosition::whereIn('portfolio_id', $ids)->get();
        $held = $positions->groupBy('symbol')->map(fn (Collection $group) => $group->sum('quantity'))->all();
        $dividends = PortfolioTransaction::whereIn('portfolio_id', $ids)->where('type', 'dividend');
        $report = $analytics->dividends((clone $dividends)->get(), $held);
        $cost = $positions->sum(fn (PortfolioPosition $position) => $position->costBasis());

        return $this->ok($report + [
            'yield_on_cost' => $cost > 0 ? round($report['expected'] / $cost * 100, 2) : null,
            'recent' => (clone $dividends)->orderByDesc('executed_on')->limit(20)->get()->map(fn ($transaction) => [
                'id' => $transaction->getKey(),
                'symbol' => $transaction->getAttribute('symbol'),
                'name' => $transaction->getAttribute('name'),
                'date' => Carbon::parse($transaction->getAttribute('executed_on'))->toDateString(),
                'amount' => round((float) $transaction->getAttribute('quantity') * (float) $transaction->getAttribute('price'), 2),
            ])->all(),
        ]);
    }

    public function taxes(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = (int) ($data['year'] ?? now()->year);
        $ids = $this->portfolioIds($request);
        $transactions = PortfolioTransaction::whereIn('portfolio_id', $ids)->where('executed_on', '<=', $year.'-12-31')->get();
        $settings = PortfolioSetting::for($this->user());
        $assetTypes = Security::whereIn('symbol', $transactions->pluck('symbol')->unique())->pluck('asset_type', 'symbol')->all();

        return $this->ok(TaxReport::build($transactions, $year, (float) ($settings->tax_allowance ?? 1000), (float) ($settings->church_tax_rate ?? 0), $assetTypes) + [
            'year' => $year,
            'years' => PortfolioTransaction::whereIn('portfolio_id', $this->portfolioIds($request, false))->pluck('executed_on')->map(fn ($date) => Carbon::parse($date)->year)->push(now()->year, $year)->unique()->sortDesc()->values()->all(),
        ]);
    }

    public function analysis(Request $request, Valuation $valuation, Analytics $analytics): JsonResponse
    {
        $data = $request->validate(['range' => ['nullable', 'in:3m,1y,5y,max'], 'allocation' => ['nullable', 'string', 'max:20']]);
        $range = $data['range'] ?? '1y';
        $ids = $this->portfolioIds($request);
        $rows = $valuation->rows(PortfolioPosition::whereIn('portfolio_id', $ids)->with('portfolio')->get());
        $from = match ($range) {
            '3m' => now()->subMonths(3),
            '5y' => now()->subYears(5),
            'max' => now()->subYears(50),
            default => now()->subYear(),
        };
        $benchmark = PortfolioSetting::for($this->user())->benchmark_symbol ?: '^GDAXI';
        $performance = $analytics->performance($ids->all(), $from, $benchmark);

        return $this->ok([
            'range' => $range,
            'benchmark' => $benchmark,
            'summary' => $valuation->summary($rows),
            'allocation' => $analytics->allocation($rows, $data['allocation'] ?? 'position'),
            'performance' => $performance,
            'risk' => $analytics->risk($performance),
            'movers' => collect($rows)->filter(fn (array $row) => $row['day_change_percent'] !== null)->sortByDesc('day_change_percent')->values()
                ->map(fn (array $row) => ['symbol' => $row['position']->symbol, 'name' => $row['position']->name ?: $row['position']->symbol, 'day_change_percent' => round((float) $row['day_change_percent'], 2)])->all(),
        ]);
    }

    /**
     * The saved target weights and what would have to be bought or sold to reach them.
     */
    public function rebalancing(Request $request, Valuation $valuation, Analytics $analytics): JsonResponse
    {
        $data = $request->validate(['additional' => ['nullable', 'numeric', 'min:0', 'max:100000000'], 'buy_only' => ['nullable', 'boolean']]);
        $rows = $valuation->rows(PortfolioPosition::whereIn('portfolio_id', $this->portfolioIds($request, false))->get());
        $current = collect($rows)->groupBy(fn (array $row) => $row['position']->symbol)
            ->map(fn (Collection $group) => ['name' => $group->first()['position']->name ?: $group->first()['position']->symbol, 'value' => $group->sum('value')])->all();
        $targets = TargetAllocation::where('user_id', $this->user()->id)->get();

        foreach ($targets as $target) {
            $current[$target->symbol] ??= ['name' => $target->name ?: $target->symbol, 'value' => 0.0];
        }

        $additional = (float) ($data['additional'] ?? 0);

        return $this->ok([
            'target_sum' => round((float) $targets->sum('target_percent'), 1),
            'has_targets' => $targets->where('target_percent', '>', 0)->isNotEmpty(),
            'plan' => $analytics->rebalance($current, $targets->pluck('target_percent', 'symbol')->all(), $additional, (bool) ($data['buy_only'] ?? true) && $additional > 0),
        ]);
    }

    /**
     * Saves the target weights (symbol => percent); a weight of 0 removes the target.
     */
    public function saveTargets(Request $request): JsonResponse
    {
        $data = $request->validate(['targets' => ['required', 'array', 'max:100'], 'targets.*' => ['numeric', 'min:0', 'max:100']]);
        abort_if(array_sum($data['targets']) > 100.05, 422, __('The target weights add up to :sum % – at most 100 % are possible.', ['sum' => number_format(array_sum($data['targets']), 1, ',', '.')]));

        $userId = $this->user()->id;
        $names = PortfolioPosition::whereIn('portfolio_id', $this->portfolioIds($request, false))->pluck('name', 'symbol');

        foreach ($data['targets'] as $symbol => $percent) {
            $symbol = strtoupper((string) $symbol);

            if ((float) $percent > 0) {
                TargetAllocation::updateOrCreate(
                    ['user_id' => $userId, 'symbol' => $symbol],
                    ['name' => $names[$symbol] ?? TargetAllocation::where('user_id', $userId)->where('symbol', $symbol)->value('name') ?? $symbol, 'target_percent' => (float) $percent],
                );
            } else {
                TargetAllocation::where('user_id', $userId)->where('symbol', $symbol)->delete();
            }
        }

        return $this->ok(['saved' => count($data['targets'])]);
    }

    /**
     * The member's portfolio ids – all of them, or the one named by `portfolio`.
     *
     * @return Collection<int, int>
     */
    private function portfolioIds(Request $request, bool $filter = true): Collection
    {
        $query = Portfolio::where('user_id', $this->user()->id);
        $id = $filter ? (int) $request->query('portfolio', 0) : 0;

        return $id > 0 ? $query->whereKey($id)->pluck('id') : $query->pluck('id');
    }
}
