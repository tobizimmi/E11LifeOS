<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PortfolioRecommendation;
use App\Models\PortfolioSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecommendationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->query('limit', 50)));
        $query = PortfolioRecommendation::where('user_id', $this->user()->id);
        $settings = PortfolioSetting::for($this->user());

        return $this->ok([
            'recommendations' => (clone $query)->orderByDesc('score')->limit($limit)->get()->map(fn (PortfolioRecommendation $recommendation) => [
                'symbol' => $recommendation->symbol,
                'name' => $recommendation->name,
                'price' => $recommendation->price,
                'currency' => $recommendation->currency,
                'drop_pct' => $recommendation->drop_percent,
                'rsi' => $recommendation->rsi,
                'stability_score' => $recommendation->stability,
                'score' => $recommendation->score,
                'rating' => $recommendation->rating,
                'reasons' => $recommendation->reasons ?? [],
                'sector' => $recommendation->data['sector'] ?? null,
                'computed_at' => $recommendation->computed_at?->toIso8601String(),
            ]),
            'meta' => [
                'total_found' => $query->count(),
                'from_cache' => true,
                'cache_empty' => ! $query->exists(),
                'strategy' => $settings->strategy ?? 'moderate',
                'drop_threshold' => $settings->drop_threshold ?? 10,
            ],
        ]);
    }
}
