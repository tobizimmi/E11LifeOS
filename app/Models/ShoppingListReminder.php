<?php

namespace App\Models;

use Database\Factories\ShoppingListReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A free-text reminder pinned above a shopping list ("take the deposit bottles"): stays visible
 * while scrolling and can be ticked off when done.
 *
 * @property ?Carbon $done_at
 */
#[Fillable(['shopping_list_id', 'text', 'done_at', 'position'])]
class ShoppingListReminder extends Model
{
    /** @use HasFactory<ShoppingListReminderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'done_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShoppingList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class, 'shopping_list_id');
    }
}
