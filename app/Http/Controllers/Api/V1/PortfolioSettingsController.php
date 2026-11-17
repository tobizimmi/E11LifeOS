<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Household;
use App\Models\Portfolio;
use App\Models\PortfolioSetting;
use App\Support\Portfolio\StockScore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal portfolio settings for the app (the web "Settings" page of the portfolio): strategy,
 * thresholds, tax allowance, benchmark, notifications and the weighting of the points system.
 */
class PortfolioSettingsController extends ApiController
{
    public function show(): JsonResponse
    {
        return $this->ok($this->data(PortfolioSetting::for($this->user())));
    }

    public function update(Request $request): JsonResponse
    {
        $this->household();
        $data = $request->validate([
            'default_portfolio_id' => ['sometimes', 'nullable', 'integer'],
            'benchmark_symbol' => ['sometimes', 'string', 'max:20'],
            'tax_allowance' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'church_tax_rate' => ['sometimes', 'in:0,0.08,0.09'],
            'strategy' => ['sometimes', 'in:'.implode(',', PortfolioSetting::STRATEGIES)],
            'drop_threshold' => ['sometimes', 'numeric', 'min:1', 'max:90'],
            'drop_period_days' => ['sometimes', 'integer', 'min:5', 'max:365'],
            'rsi_threshold' => ['sometimes', 'numeric', 'min:5', 'max:95'],
            'min_market_cap_bn' => ['sometimes', 'numeric', 'min:0', 'max:10000'],
            'min_stability_score' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'max_pe_ratio' => ['sometimes', 'numeric', 'min:1', 'max:999'],
            'max_debt_equity' => ['sometimes', 'numeric', 'min:0', 'max:999'],
            'check_200ma' => ['sometimes', 'boolean'],
            'prefer_dividends' => ['sometimes', 'boolean'],
            'pushover_user_key' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pushover_app_token' => ['sometimes', 'nullable', 'string', 'max:50'],
            'news_push_enabled' => ['sometimes', 'boolean'],
            'news_push_portfolio_only' => ['sometimes', 'boolean'],
            'news_push_max_age_hours' => ['sometimes', 'integer', 'in:1,3,6,12,24,48,72,168'],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('default_portfolio_id', $data)) {
            $id = $data['default_portfolio_id'];
            $data['default_portfolio_id'] = $id !== null && Portfolio::query()->where('user_id', $this->user()->id)->whereKey((int) $id)->exists() ? (int) $id : null;
        }
        if (isset($data['benchmark_symbol'])) {
            $data['benchmark_symbol'] = strtoupper((string) $data['benchmark_symbol']);
        }
        if (array_key_exists('pushover_user_key', $data)) {
            $data['pushover_user_key'] = ($data['pushover_user_key'] ?? '') !== '' ? $data['pushover_user_key'] : null;
        }
        // An empty token keeps the saved one (it is never sent back); "remove_pushover_token" deletes it.
        if (($data['pushover_app_token'] ?? '') === '') {
            unset($data['pushover_app_token']);
        }
        if ($request->boolean('remove_pushover_token')) {
            $data['pushover_app_token'] = null;
        }

        $settings = PortfolioSetting::for($this->user());
        $settings->fill($data)->save();

        return $this->ok($this->data($settings));
    }

    /**
     * Weighting per factor (0 = left out … 3), or the "default"/"creative" preset.
     */
    public function scoreWeights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preset' => ['sometimes', 'in:default,creative'],
            'weights' => ['sometimes', 'array'],
            'weights.*' => ['integer', 'min:0', 'max:3'],
        ]);

        $creative = ['pe' => 3, 'index' => 3, 'diversification' => 3, 'dividend' => 3, 'revenue_earnings' => 3, 'high_low' => 3, 'technical' => 2, 'debt' => 2, 'value' => 2, 'growth' => 2, 'reputation' => 2, 'tradability' => 2, 'book' => 1, 'sustainability' => 1];
        $own = isset($validated['preset']) ? ($validated['preset'] === 'creative' ? $creative : []) : (array) ($validated['weights'] ?? []);
        $weights = StockScore::weights($own);

        $settings = PortfolioSetting::for($this->user());
        $settings->fill(['score_weights' => $weights === StockScore::weights() ? null : $weights])->save();

        return $this->ok($this->data($settings));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(PortfolioSetting $settings): array
    {
        $this->household();

        return [
            'default_portfolio_id' => $settings->getAttribute('default_portfolio_id') === null ? null : (int) $settings->getAttribute('default_portfolio_id'),
            'benchmark_symbol' => (string) ($settings->getAttribute('benchmark_symbol') ?? '^GDAXI'),
            'tax_allowance' => (float) ($settings->getAttribute('tax_allowance') ?? 1000),
            'church_tax_rate' => (float) ($settings->getAttribute('church_tax_rate') ?? 0),
            'strategy' => (string) ($settings->getAttribute('strategy') ?? 'moderate'),
            'drop_threshold' => (float) ($settings->getAttribute('drop_threshold') ?? 10),
            'drop_period_days' => (int) ($settings->getAttribute('drop_period_days') ?? 30),
            'rsi_threshold' => (float) ($settings->getAttribute('rsi_threshold') ?? 45),
            'min_market_cap_bn' => (float) ($settings->getAttribute('min_market_cap_bn') ?? 0),
            'min_stability_score' => (float) ($settings->getAttribute('min_stability_score') ?? 40),
            'max_pe_ratio' => (float) ($settings->getAttribute('max_pe_ratio') ?? 999),
            'max_debt_equity' => (float) ($settings->getAttribute('max_debt_equity') ?? 999),
            'check_200ma' => (bool) $settings->getAttribute('check_200ma'),
            'prefer_dividends' => (bool) $settings->getAttribute('prefer_dividends'),
            'pushover_user_key' => $settings->getAttribute('pushover_user_key') === null ? null : (string) $settings->getAttribute('pushover_user_key'),
            'pushover_token_set' => $settings->getAttribute('pushover_app_token') !== null,
            'news_push_enabled' => (bool) $settings->getAttribute('news_push_enabled'),
            'news_push_portfolio_only' => (bool) ($settings->getAttribute('news_push_portfolio_only') ?? true),
            'news_push_max_age_hours' => (int) ($settings->getAttribute('news_push_max_age_hours') ?? 24),
            'notifications_enabled' => (bool) ($settings->getAttribute('notifications_enabled') ?? true),
            'strategies' => PortfolioSetting::STRATEGIES,
            'portfolios' => Portfolio::query()->where('user_id', $this->user()->id)->orderBy('name')->get(['id', 'name'])->map(fn (Portfolio $portfolio): array => ['id' => $portfolio->id, 'name' => $portfolio->name])->all(),
            'score_factors' => collect(StockScore::factors())->map(fn (array $factor, string $key): array => [
                'key' => $key,
                'label' => $factor['label'],
                'hint' => $factor['hint'],
                'default' => $factor['weight'],
                'weight' => StockScore::weights($settings->score_weights)[$key] ?? $factor['weight'],
            ])->values()->all(),
        ];
    }

    private function household(): Household
    {
        return $this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.'));
    }
}
