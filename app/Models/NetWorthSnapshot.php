<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Net worth of a member on one day (history chart).
 */
#[Fillable(['user_id', 'taken_on', 'assets', 'liabilities', 'breakdown'])]
class NetWorthSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'taken_on' => 'date',
            'assets' => 'float',
            'liabilities' => 'float',
            'breakdown' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function netWorth(): float
    {
        return round($this->assets - $this->liabilities, 2);
    }
}
