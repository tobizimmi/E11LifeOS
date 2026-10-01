<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing to pack for a trip: on the shared list (no owner) or on a member's personal list,
 * which only that member sees.
 *
 * @property int $id
 * @property int $trip_id
 * @property ?int $owner_id
 * @property string $label
 * @property string $category
 * @property ?string $person
 * @property bool $packed
 * @property int $position
 */
#[Fillable(['trip_id', 'owner_id', 'label', 'category', 'person', 'packed', 'position'])]
class TripPackingItem extends Model
{
    protected function casts(): array
    {
        return ['packed' => 'boolean', 'position' => 'integer'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * The shared list plus the user's own items.
     *
     * @param  Builder<TripPackingItem>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('owner_id')->orWhere('owner_id', $user->id));
    }
}
