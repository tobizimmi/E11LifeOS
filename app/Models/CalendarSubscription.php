<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An external calendar (ICS link, e.g. a shared Proton, Google or iCloud calendar). Private ones
 * are only shown to the member who added them, shared ones to the whole household. The link
 * usually contains a secret, so it is stored encrypted and never shown in full again.
 */
#[Fillable(['user_id', 'household_id', 'name', 'url', 'visibility', 'fetched_at', 'error'])]
#[Hidden(['url'])]
class CalendarSubscription extends Model
{
    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'fetched_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The member's own calendars and the ones other members share with the household.
     */
    public function scopeVisibleTo(Builder $query, User $user, ?int $householdId): void
    {
        $query->where(fn (Builder $query) => $query->where('user_id', $user->id)
            ->when($householdId, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query->where('household_id', $householdId)->where('visibility', Item::VISIBILITY_SHARED))));
    }

    public function isPrivate(): bool
    {
        return $this->visibility !== Item::VISIBILITY_SHARED;
    }
}
