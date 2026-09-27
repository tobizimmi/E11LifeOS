<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FinTS access to a bank or broker of one user.
 */
#[Fillable(['user_id', 'household_id', 'bank', 'bank_name', 'fints_url', 'blz', 'login_name', 'pin', 'tan_mode', 'portfolio_id', 'auto_sync', 'last_synced_at', 'sync_status', 'sync_message', 'pending_state', 'pending_until'])]
#[Hidden(['pin', 'pending_state'])]
class BankConnection extends Model
{
    protected function casts(): array
    {
        return [
            'pin' => 'encrypted',
            'pending_state' => 'encrypted:array',
            'auto_sync' => 'boolean',
            'last_synced_at' => 'datetime',
            'pending_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function depotPositions(): HasMany
    {
        return $this->hasMany(BankDepotPosition::class)->orderBy('name');
    }

    public function hasPendingTan(): bool
    {
        return $this->pending_state !== null && $this->pending_until?->isFuture();
    }
}
