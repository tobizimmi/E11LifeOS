<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current holding of a security in a portfolio, calculated from the transactions (average cost
 * method) or entered by hand.
 */
#[Fillable(['portfolio_id', 'symbol', 'name', 'isin', 'quantity', 'avg_buy_price', 'cost_basis_override', 'cost_basis_estimated', 'currency', 'asset_type', 'notes', 'labels', 'stop_loss_price', 'take_profit_price', 'stop_loss_notified_at', 'take_profit_notified_at', 'manual'])]
class PortfolioPosition extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'avg_buy_price' => 'float',
            'cost_basis_override' => 'float',
            'cost_basis_estimated' => 'boolean',
            'labels' => 'array',
            'stop_loss_price' => 'float',
            'take_profit_price' => 'float',
            'manual' => 'boolean',
            'stop_loss_notified_at' => 'datetime',
            'take_profit_notified_at' => 'datetime',
        ];
    }

    /**
     * The purchase price per unit used for gains: the hand-entered override when set, otherwise the
     * average calculated from the transactions.
     */
    public function effectiveBuyPrice(): float
    {
        return $this->cost_basis_override ?? $this->avg_buy_price;
    }

    /**
     * Whether the gain is built on a merely estimated purchase price that the member has not yet
     * corrected by hand – a hint to enter the real one.
     */
    public function needsManualCost(): bool
    {
        return $this->cost_basis_estimated && $this->cost_basis_override === null;
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
        return round($this->quantity * $this->effectiveBuyPrice(), 2);
    }
}
