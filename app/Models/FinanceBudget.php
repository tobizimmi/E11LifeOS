<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monthly spending limit of a user for one finance category.
 */
#[Fillable(['user_id', 'household_id', 'category', 'monthly_limit', 'warned_month', 'warned_level'])]
class FinanceBudget extends Model
{
    protected function casts(): array
    {
        return [
            'monthly_limit' => 'float',
            'warned_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
