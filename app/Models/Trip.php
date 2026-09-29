<?php

namespace App\Models;

use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A holiday or business trip of the household with bookings, costs and a packing list.
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'title', 'destination', 'starts_on', 'ends_on', 'budget', 'travelers', 'packing_templates', 'notes'])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(fn (Trip $trip) => ExternalLink::where('linkable_type', 'trip')->where('linkable_id', $trip->id)->get()->each->delete());
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'budget' => 'float',
            'packing_templates' => 'array',
            'notes' => 'encrypted',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(TripBooking::class)->orderByRaw('starts_at IS NULL')->orderBy('starts_at')->orderBy('id');
    }

    public function packingItems(): HasMany
    {
        return $this->hasMany(TripPackingItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Shared trips of the household and the member's private ones.
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id));
    }

    public function nights(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on);
    }

    public function isPast(): bool
    {
        return $this->ends_on->lt(today());
    }

    public function isOngoing(): bool
    {
        return ! $this->starts_on->gt(today()) && ! $this->isPast();
    }
}
