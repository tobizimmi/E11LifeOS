<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notifies the user once a security rises above or falls below a price.
 */
#[Fillable(['user_id', 'symbol', 'name', 'condition', 'target_price', 'currency', 'triggered_at', 'active'])]
class PriceAlert extends Model
{
    protected function casts(): array
    {
        return [
            'target_price' => 'float',
            'triggered_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
