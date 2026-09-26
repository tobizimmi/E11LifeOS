<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current holding of a security in a portfolio, calculated from the transactions (average cost
 * method) or entered by hand.
 */
#[Fillable(['portfolio_id', 'symbol', 'name', 'isin', 'quantity', 'avg_buy_price', 'currency', 'asset_type', 'notes', 'labels', 'stop_loss_price', 'take_profit_price', 'manual'])]
class PortfolioPosition extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'avg_buy_price' => 'float',
            'labels' => 'array',
            'stop_loss_price' => 'float',
            'take_profit_price' => 'float',
            'manual' => 'boolean',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function security(): BelongsTo
    {
        return $this->belongsTo(Security::class, 'symbol', 'symbol');
    }

    public function costBasis(): float
    {
        return round($this->quantity * $this->avg_buy_price, 2);
    }
}
