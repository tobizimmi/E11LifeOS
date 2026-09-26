<?php

namespace App\Models;

use Database\Factories\PortfolioTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buy, sale, dividend, split, fee or transfer in a portfolio.
 */
#[Fillable(['portfolio_id', 'symbol', 'name', 'isin', 'wkn', 'type', 'quantity', 'price', 'fees', 'taxes', 'currency', 'exchange_rate', 'executed_on', 'broker', 'notes', 'import_source'])]
class PortfolioTransaction extends Model
{
    /** @use HasFactory<PortfolioTransactionFactory> */
    use HasFactory;

    public const TYPES = ['buy', 'sell', 'dividend', 'split', 'fee', 'transfer_in', 'transfer_out'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'price' => 'float',
            'fees' => 'float',
            'taxes' => 'float',
            'exchange_rate' => 'float',
            'executed_on' => 'date',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    /**
     * Money moved: negative for purchases and fees, positive for sales and dividends (net of costs).
     */
    public function cashFlow(): float
    {
        return round(match ($this->type) {
            'buy' => -($this->quantity * $this->price + $this->fees),
            'sell' => $this->quantity * $this->price - $this->fees - $this->taxes,
            'dividend' => ($this->quantity > 0 ? $this->quantity * $this->price : $this->price) - $this->taxes - $this->fees,
            'fee' => -($this->fees ?: $this->price),
            default => 0.0,
        }, 2);
    }
}
