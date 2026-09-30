<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A regular investment (Sparplan) into one security.
 */
#[Fillable(['portfolio_id', 'symbol', 'name', 'amount', 'frequency', 'day_of_month', 'next_date', 'last_executed_on', 'active'])]
class SavingsPlan extends Model
{
    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'next_date' => 'date',
            'last_executed_on' => 'date',
            'active' => 'boolean',
        ];
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }
}
