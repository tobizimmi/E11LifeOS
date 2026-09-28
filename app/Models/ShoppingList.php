<?php

namespace App\Models;

use Database\Factories\ShoppingListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the household's shopping lists (weekly shop, drugstore, party …), optionally tied to
 * a store whose section order it uses, shareable by link.
 */
#[Fillable(['household_id', 'name', 'icon', 'store_id', 'share_token', 'position', 'created_by'])]
class ShoppingList extends Model
{
    /** @use HasFactory<ShoppingListFactory> */
    use HasFactory;

    public const ICONS = ['🛒', '🥦', '🧴', '💊', '🎉', '🏠', '🐾', '👶', '🍷', '🔧'];

    /**
     * The household's first list; created when there is none yet.
     */
    public static function defaultFor(int $householdId): self
    {
        return self::where('household_id', $householdId)->orderBy('position')->orderBy('id')->first()
            ?? self::create(['household_id' => $householdId, 'name' => __('Shopping List'), 'icon' => '🛒']);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(ShoppingStore::class, 'store_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ShoppingListEntry::class);
    }
}
