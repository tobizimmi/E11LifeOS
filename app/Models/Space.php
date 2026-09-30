<?php

namespace App\Models;

use Database\Factories\SpaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workspace ("Work", "Home", "Club") that groups pages and tasks.
 *
 * @property int $id
 * @property int $household_id
 * @property ?int $created_by
 * @property string $visibility
 * @property string $name
 * @property string $icon
 * @property string $color
 * @property int $position
 */
#[Fillable(['household_id', 'created_by', 'visibility', 'name', 'icon', 'color', 'position'])]
class Space extends Model
{
    /** @use HasFactory<SpaceFactory> */
    use HasFactory;

    public const ICONS = ['🗂️', '🏠', '💼', '👨‍👩‍👧', '⚽', '🎓', '💰', '✈️', '🏡', '🎨', '🛠️', '❤️'];

    public const COLORS = ['indigo', 'blue', 'green', 'amber', 'red', 'purple', 'pink', 'gray'];

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function isPrivate(): bool
    {
        return $this->visibility === Item::VISIBILITY_PRIVATE;
    }

    /**
     * Shared spaces of the household and the member's private ones.
     *
     * @param  Builder<Space>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, int $householdId): void
    {
        $query->where('household_id', $householdId)
            ->where(fn (Builder $query) => $query->where('visibility', Item::VISIBILITY_SHARED)->orWhere('created_by', $user->id))
            ->orderBy('position')->orderBy('name');
    }
}
