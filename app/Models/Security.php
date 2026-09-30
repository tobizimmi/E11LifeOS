<?php

namespace App\Models;

use Database\Factories\SecurityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A share, ETF, fund, crypto currency … identified by its Yahoo Finance symbol; shared by all users.
 */
#[Fillable(['symbol', 'name', 'isin', 'wkn', 'exchange', 'asset_type', 'currency', 'sector', 'country', 'market_cap', 'week_52_high', 'week_52_low', 'dividend_yield', 'beta', 'fundamentals', 'fundamentals_updated_at', 'screeners', 'tracked', 'scanned_at', 'last_price', 'previous_close', 'price_updated_at'])]
class Security extends Model
{
    /** @use HasFactory<SecurityFactory> */
    use HasFactory;

    public const ASSET_TYPES = ['stock', 'etf', 'fund', 'crypto', 'bond', 'other'];

    protected function casts(): array
    {
        return [
            'market_cap' => 'integer',
            'week_52_high' => 'float',
            'week_52_low' => 'float',
            'dividend_yield' => 'float',
            'beta' => 'float',
            'last_price' => 'float',
            'previous_close' => 'float',
            'price_updated_at' => 'datetime',
            'fundamentals' => 'array',
            'fundamentals_updated_at' => 'datetime',
            'screeners' => 'array',
            'tracked' => 'boolean',
            'scanned_at' => 'datetime',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SecurityPrice::class, 'symbol', 'symbol')->orderBy('date');
    }

    public function changePercent(): ?float
    {
        return $this->last_price && $this->previous_close ? round(($this->last_price - $this->previous_close) / $this->previous_close * 100, 2) : null;
    }
}
