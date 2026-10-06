<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A PSD2 account connection of one user through GoCardless Bank Account Data (ex-Nordigen), for
 * banks that no longer support FinTS. Covers payment accounts (balances + bookings), not depots.
 *
 * @property Carbon|null $consent_expires_at
 * @property Carbon|null $last_synced_at
 * @property list<string>|null $account_ids
 */
#[Fillable(['user_id', 'household_id', 'institution_id', 'institution_name', 'logo', 'reference', 'requisition_id', 'status', 'account_ids', 'consent_expires_at', 'auto_sync', 'last_synced_at', 'sync_status', 'sync_message'])]
#[Hidden(['account_ids'])]
class BankLink extends Model
{
    public const STATUS_CREATED = 'created';

    public const STATUS_LINKED = 'linked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_ERROR = 'error';

    protected function casts(): array
    {
        return [
            'account_ids' => 'encrypted:array',
            'consent_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'auto_sync' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Linked and the PSD2 consent (usually 90 days) has not run out yet.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_LINKED
            && (! $this->consent_expires_at || $this->consent_expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->consent_expires_at !== null && $this->consent_expires_at->isPast());
    }
}
