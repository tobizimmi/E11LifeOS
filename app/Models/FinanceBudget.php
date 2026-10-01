<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Monthly spending limit of a user for one finance category. With a rollover start month it works
 * like a pot (YNAB): what is left over carries into the next month, overspending is taken from it.
 *
 * @property ?Carbon $rollover_from
 */
#[Fillable(['user_id', 'household_id', 'category', 'monthly_limit', 'rollover_from', 'warned_month', 'warned_level'])]
class FinanceBudget extends Model
{
    protected function casts(): array
    {
        return [
            'monthly_limit' => 'float',
            'rollover_from' => 'date',
            'warned_level' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
