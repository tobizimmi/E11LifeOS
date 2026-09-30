<?php

namespace App\Models;

use Database\Factories\InvestmentThesisFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Why I own a security (Peter Lynch's "two-minute story"): thesis, competitive advantage, risks,
 * a price to buy below, the conditions to sell and a date to check it again.
 *
 * @property int $id
 * @property int $user_id
 * @property string $symbol
 * @property ?string $stock_type
 * @property ?string $thesis
 * @property ?string $moat
 * @property ?string $risks
 * @property ?string $sell_if
 * @property ?float $buy_below
 * @property ?Carbon $review_on
 * @property ?Carbon $updated_at
 */
#[Fillable(['user_id', 'symbol', 'stock_type', 'thesis', 'moat', 'risks', 'sell_if', 'buy_below', 'review_on'])]
class InvestmentThesis extends Model
{
    /** @use HasFactory<InvestmentThesisFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'thesis' => 'encrypted',
            'moat' => 'encrypted',
            'risks' => 'encrypted',
            'sell_if' => 'encrypted',
            'buy_below' => 'float',
            'review_on' => 'date',
        ];
    }

    /**
     * Stock categories after Peter Lynch.
     *
     * @return array<string, string>
     */
    public static function stockTypes(): array
    {
        return [
            'slow_grower' => __('Slow grower'),
            'stalwart' => __('Stalwart'),
            'fast_grower' => __('Fast grower'),
            'cyclical' => __('Cyclical'),
            'turnaround' => __('Turnaround'),
            'asset_play' => __('Asset play'),
            'etf' => __('ETF / fund'),
        ];
    }

    public function isDueForReview(): bool
    {
        return $this->review_on !== null && $this->review_on->lte(today());
    }
}
