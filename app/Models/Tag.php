<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['household_id', 'name'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class);
    }

    public function recipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class);
    }

    /**
     * Normalizes user input ("#Urlaub ", "urlaub") to one canonical tag name.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim(ltrim(trim($name), '#')));
    }

    /**
     * Tags of the household that are attached to at least one item the user may see
     * (optionally of one type), so names used only on someone else's private items never leak.
     *
     * @param  Builder<Tag>  $query
     */
    public function scopeUsedOnItemsVisibleTo(Builder $query, User $user, int $householdId, ?string $type = null): void
    {
        $query->where('household_id', $householdId)
            ->whereHas('items', fn (Builder $items) => $items
                ->forHousehold($householdId)
                ->visibleTo($user)
                ->when($type !== null, fn (Builder $items) => $items->ofType($type)));
    }
}
