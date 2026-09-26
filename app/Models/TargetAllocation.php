<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Target weight of a security across all portfolios of a user (rebalancing).
 */
#[Fillable(['user_id', 'symbol', 'name', 'target_percent'])]
class TargetAllocation extends Model
{
    protected function casts(): array
    {
        return ['target_percent' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
