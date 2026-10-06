<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notifies the user once a security rises above or falls below a price.
 */
#[Fillable(['user_id', 'symbol', 'name', 'condition', 'target_price', 'gain_percent', 'currency', 'triggered_at', 'active'])]
class PriceAlert extends Model
{
    /**
     * Profit steps a single button sets up for a holding (percent above the average buy price).
     *
     * @var list<int>
     */
    public const GAIN_STEPS = [5, 10, 20, 30];

    protected function casts(): array
    {
        return [
            'target_price' => 'float',
            'gain_percent' => 'integer',
            'triggered_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
