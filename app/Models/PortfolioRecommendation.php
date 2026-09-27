<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buy candidate found by the recommendation engine with the user's strategy.
 */
#[Fillable(['user_id', 'symbol', 'name', 'price', 'currency', 'period_high', 'drop_percent', 'rsi', 'stability', 'score', 'rating', 'below_ma200', 'has_dividend', 'reasons', 'data', 'computed_at'])]
class PortfolioRecommendation extends Model
{
    public const RATINGS = ['strong_buy', 'moderate_buy', 'watchlist_buy'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'period_high' => 'float',
            'drop_percent' => 'float',
            'rsi' => 'float',
            'stability' => 'float',
            'score' => 'float',
            'below_ma200' => 'boolean',
            'has_dividend' => 'boolean',
            'reasons' => 'array',
            'data' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
