<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Portfolio;
use App\Models\PriceAlert;
use App\Models\SavingsPlan;
use App\Models\Security;
use App\Support\Portfolio\SavingsPlans;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Price alerts and savings plans for the app. Both are personal, like the portfolios.
 */
class InvestingController extends ApiController
{
    public function alerts(): JsonResponse
    {
        $alerts = PriceAlert::where('user_id', $this->user()->id)->orderByRaw('triggered_at IS NOT NULL')->orderBy('symbol')->get();
        $prices = Security::whereIn('symbol', $alerts->pluck('symbol')->unique())->get()->keyBy('symbol');

        return $this->ok($alerts->map(fn (PriceAlert $alert) => $this->alertData($alert, $prices->get($alert->symbol)))->all());
    }

    public function storeAlert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.\-^=]+$/'],
            'name' => ['nullable', 'string', 'max:255'],
            'condition' => ['required', 'in:above,below'],
            'target_price' => ['required', 'numeric', 'gt:0', 'max:100000000'],
        ]);
        $symbol = strtoupper($data['symbol']);

        $alert = PriceAlert::create([
            'user_id' => $this->user()->id,
            'symbol' => $symbol,
            'name' => ($data['name'] ?? null) ?: (Security::where('symbol', $symbol)->value('name') ?? $symbol),
            'condition' => $data['condition'],
            'target_price' => (float) $data['target_price'],
            'currency' => 'EUR',
            'active' => true,
        ]);

        return $this->ok($this->alertData($alert, Security::where('symbol', $symbol)->first()), 201);
    }

    /**
     * Arms a triggered alert again.
     */
    public function rearmAlert(int $alert): JsonResponse
    {
        $model = PriceAlert::where('user_id', $this->user()->id)->find($alert) ?? abort(404);
        $model->update(['triggered_at' => null, 'active' => true]);

        return $this->ok($this->alertData($model, Security::where('symbol', $model->symbol)->first()));
    }

    public function destroyAlert(int $alert): JsonResponse
    {
        (PriceAlert::where('user_id', $this->user()->id)->find($alert) ?? abort(404))->delete();

        return $this->ok();
    }

    public function savingsPlans(): JsonResponse
    {
        $plans = $this->plans()->orderByDesc('active')->orderBy('next_date')->get();

        return $this->ok([
            'frequencies' => SavingsPlan::FREQUENCIES,
            'portfolios' => Portfolio::where('user_id', $this->user()->id)->orderBy('id')->get()->map(fn (Portfolio $portfolio) => ['id' => $portfolio->id, 'name' => $portfolio->name])->all(),
            'monthly' => round((float) $plans->where('active', true)->sum(fn (SavingsPlan $plan) => $this->perMonth($plan)), 2),
            'plans' => $plans->map(fn (SavingsPlan $plan) => $this->planData($plan))->all(),
        ]);
    }

    public function storeSavingsPlan(Request $request): JsonResponse
    {
        $attributes = $this->planAttributes($request);
        $plan = SavingsPlan::create($attributes + ['next_date' => SavingsPlans::firstDate($attributes['frequency'], $attributes['day_of_month']), 'active' => true]);

        return $this->ok($this->planData($plan), 201);
    }

    public function updateSavingsPlan(Request $request, int $plan): JsonResponse
    {
        $model = $this->plans()->find($plan) ?? abort(404);
        $attributes = $this->planAttributes($request);
        $rescheduled = $model->frequency !== $attributes['frequency'] || $model->day_of_month !== $attributes['day_of_month'];
        $model->update($attributes + ($rescheduled ? ['next_date' => SavingsPlans::firstDate($attributes['frequency'], $attributes['day_of_month'])] : []));

        return $this->ok($this->planData($model));
    }

    /**
     * Pauses a plan or resumes it (a date in the past is moved to the next execution).
     */
    public function toggleSavingsPlan(int $plan): JsonResponse
    {
        $model = $this->plans()->find($plan) ?? abort(404);
        $model->update(['active' => ! $model->active] + (! $model->active && $model->next_date->lt(today()) ? ['next_date' => SavingsPlans::firstDate($model->frequency, $model->day_of_month)] : []));

        return $this->ok($this->planData($model));
    }

    public function destroySavingsPlan(int $plan): JsonResponse
    {
        ($this->plans()->find($plan) ?? abort(404))->delete();

        return $this->ok();
    }

    /**
     * @return Builder<SavingsPlan>
     */
    private function plans(): Builder
    {
        return SavingsPlan::whereIn('portfolio_id', Portfolio::where('user_id', $this->user()->id)->select('id'));
    }

    /**
     * @return array{portfolio_id: int, symbol: string, name: string, amount: float, frequency: string, day_of_month: int}
     */
    private function planAttributes(Request $request): array
    {
        $data = $request->validate([
            'portfolio_id' => ['required', 'integer'],
            'symbol' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9.\-^=]+$/'],
            'name' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'frequency' => ['required', Rule::in(SavingsPlan::FREQUENCIES)],
            'day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
        ]);

        $portfolio = Portfolio::where('user_id', $this->user()->id)->find($data['portfolio_id']) ?? abort(404);
        $symbol = strtoupper($data['symbol']);

        return [
            'portfolio_id' => $portfolio->id,
            'symbol' => $symbol,
            'name' => ($data['name'] ?? null) ?: (Security::where('symbol', $symbol)->value('name') ?? $symbol),
            'amount' => (float) $data['amount'],
            'frequency' => $data['frequency'],
            'day_of_month' => (int) $data['day_of_month'],
        ];
    }

    private function perMonth(SavingsPlan $plan): float
    {
        return match ($plan->frequency) {
            'weekly' => $plan->amount * 52 / 12,
            'quarterly' => $plan->amount / 3,
            default => $plan->amount,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function alertData(PriceAlert $alert, ?Security $security): array
    {
        return [
            'id' => $alert->id,
            'symbol' => $alert->symbol,
            'name' => $alert->name,
            'condition' => $alert->condition,
            'target_price' => $alert->target_price,
            'currency' => $alert->currency,
            'active' => (bool) $alert->active,
            'triggered_at' => $alert->triggered_at?->toIso8601String(),
            'current_price' => $security?->last_price !== null ? (float) $security->last_price : null,
            'price_currency' => $security?->currency,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planData(SavingsPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'portfolio_id' => $plan->portfolio_id,
            'portfolio' => Portfolio::where('user_id', $this->user()->id)->whereKey($plan->portfolio_id)->value('name'),
            'symbol' => $plan->symbol,
            'name' => $plan->name,
            'amount' => $plan->amount,
            'frequency' => $plan->frequency,
            'day_of_month' => $plan->day_of_month,
            'next_date' => $plan->next_date->toDateString(),
            'last_executed_on' => $plan->last_executed_on?->toDateString(),
            'active' => (bool) $plan->active,
            'per_month' => round($this->perMonth($plan), 2),
        ];
    }
}
