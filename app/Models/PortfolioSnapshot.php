<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Value and cost of a portfolio at one point in time (for the value chart).
 */
#[Fillable(['portfolio_id', 'taken_at', 'total_value', 'total_cost'])]
class PortfolioSnapshot extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'total_value' => 'float',
            'total_cost' => 'float',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}
