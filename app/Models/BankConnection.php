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
#[Fillable(['user_id', 'household_id', 'bank', 'bank_name', 'fints_url', 'blz', 'login_name', 'pin', 'tan_mode', 'portfolio_id', 'accounts', 'auto_sync', 'last_synced_at', 'sync_status', 'sync_message', 'pending_state', 'pending_until'])]
#[Hidden(['pin', 'pending_state'])]
class BankConnection extends Model
{
    protected function casts(): array
    {
        return [
            'pin' => 'encrypted',
            'pending_state' => 'encrypted:array',
            'accounts' => 'encrypted:array',
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

    /**
     * Account numbers the user switched off for an operation ("statement" → bookings, "depot" → holdings).
     *
     * @return list<string>
     */
    public function skippedAccounts(string $operation): array
    {
        [$supported, $field] = $operation === 'depot' ? ['holdings', 'use_holdings'] : ['bookings', 'use_bookings'];

        return collect($this->accounts ?? [])->filter(fn (array $account) => ($account[$supported] ?? false) && ! ($account[$field] ?? true))->pluck('number')->map(fn ($number) => (string) $number)->values()->all();
    }

    /**
     * Stores the accounts the bank reported and keeps the user's choices for accounts already known.
     *
     * @param  list<array<string, mixed>>  $reported
     */
    public function rememberAccounts(array $reported): void
    {
        $known = collect($this->accounts ?? [])->keyBy('number');

        $this->accounts = collect($reported)->map(function (array $account) use ($known) {
            $previous = $known->get($account['number']);

            return $account + [
                'use_bookings' => $previous['use_bookings'] ?? $account['bookings'],
                'use_holdings' => $previous['use_holdings'] ?? $account['holdings'],
            ];
        })->values()->all();
        $this->save();
    }

    public function hasPendingTan(): bool
    {
        return $this->pending_state !== null && $this->pending_until?->isFuture();
    }
}
