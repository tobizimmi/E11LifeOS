<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PortfolioSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends ApiController
{
    public function show(): JsonResponse
    {
        return $this->ok($this->present(PortfolioSetting::for($this->user())));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'strategy' => ['sometimes', 'in:'.implode(',', PortfolioSetting::STRATEGIES)],
            'drop_threshold' => ['sometimes', 'numeric', 'min:1', 'max:90'],
            'drop_period_days' => ['sometimes', 'integer', 'min:5', 'max:365'],
            'min_stability_score' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'check_200ma' => ['sometimes', 'boolean'],
            'prefer_dividends' => ['sometimes', 'boolean'],
            'news_push_enabled' => ['sometimes', 'boolean'],
            'instagram_push' => ['sometimes', 'boolean'],
        ]);

        $settings = PortfolioSetting::for($this->user());
        $settings->fill($data)->save();

        return $this->ok($this->present($settings));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PortfolioSetting $settings): array
    {
        return [
            'strategy' => $settings->strategy ?? 'moderate',
            'drop_threshold' => $settings->drop_threshold ?? 10.0,
            'drop_period_days' => $settings->drop_period_days ?? 30,
            'min_stability_score' => $settings->min_stability_score,
            'preferred_currency' => 'EUR',
            'check_200ma' => (bool) $settings->check_200ma,
            'prefer_dividends' => (bool) $settings->prefer_dividends,
            'news_push_enabled' => (bool) $settings->news_push_enabled,
            'instagram_push' => (bool) $settings->instagram_push,
        ];
    }
}
