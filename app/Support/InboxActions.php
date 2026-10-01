<?php

namespace App\Support;

use App\Models\Item;
use App\Models\ShoppingListEntry;
use Illuminate\Support\Facades\DB;

/**
 * Sorting an inbox entry: into a task, a page or onto the shopping list.
 * Shared by the inbox page and the inbox window on the overview.
 */
class InboxActions
{
    /**
     * Turns the entry into an open task at the end of the list.
     */
    public static function toTask(Item $item): void
    {
        $maxPosition = (int) Item::query()->forHousehold($item->household_id)->ofType(Item::TYPE_TODO)->max('position');

        $item->update([
            'type' => Item::TYPE_TODO,
            'content' => null,
            'done' => false,
            'position' => $maxPosition + 1,
        ]);
    }

    /**
     * Turns the entry into an empty page, keeping its visibility.
     */
    public static function toPage(Item $item): void
    {
        // A text that came with the entry (e.g. the body of a mail) stays on the page.
        $item->update(['type' => Item::TYPE_NOTE, 'content' => ['body' => (string) data_get($item->content, 'body', '')]]);
    }

    /**
     * Moves the entry onto the (always shared) shopping list.
     */
    public static function toShoppingList(Item $item, int $userId): void
    {
        DB::transaction(function () use ($item, $userId) {
            ShoppingListEntry::create([
                'household_id' => $item->household_id,
                'label' => $item->title,
                'created_by' => $userId,
            ]);

            $item->delete();
        });
    }
}
