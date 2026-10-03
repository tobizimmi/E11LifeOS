<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personal portfolio settings: recommendation strategy, benchmark, tax allowance, Pushover.
 */
#[Fillable(['user_id', 'default_portfolio_id', 'strategy', 'drop_threshold', 'drop_period_days', 'min_stability_score', 'min_market_cap_bn', 'rsi_threshold', 'max_pe_ratio', 'max_debt_equity', 'check_200ma', 'prefer_dividends', 'benchmark_symbol', 'tax_allowance', 'church_tax_rate', 'pushover_user_key', 'pushover_app_token', 'notifications_enabled', 'news_push_enabled', 'news_push_portfolio_only', 'news_push_max_age_hours', 'instagram_push', 'score_weights'])]
class PortfolioSetting extends Model
{
    public const STRATEGIES = ['conservative', 'moderate', 'aggressive', 'custom', 'thieme'];

    protected function casts(): array
    {
        return [
            'drop_threshold' => 'float',
            'min_stability_score' => 'float',
            'min_market_cap_bn' => 'float',
            'rsi_threshold' => 'float',
            'max_pe_ratio' => 'float',
            'max_debt_equity' => 'float',
            'check_200ma' => 'boolean',
            'prefer_dividends' => 'boolean',
            'tax_allowance' => 'float',
            'church_tax_rate' => 'float',
            'pushover_user_key' => 'encrypted',
            'pushover_app_token' => 'encrypted',
            'notifications_enabled' => 'boolean',
            'news_push_enabled' => 'boolean',
            'news_push_max_age_hours' => 'integer',
            'news_push_portfolio_only' => 'boolean',
            'instagram_push' => 'boolean',
            'score_weights' => 'array',
        ];
    }

    public static function for(User $user): self
    {
        return self::firstOrNew(['user_id' => $user->id]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
