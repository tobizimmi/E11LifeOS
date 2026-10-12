<?php

namespace App\Support;

use App\Models\Item;
use App\Support\Concerns\AssignsNextPosition;
use Illuminate\Support\Facades\DB;

/**
 * Turns a collection entry or a page into an open task — the same idea as
 * App\Support\InboxActions::toTask(), generalised to items that carry their own collection
 * properties or a body (a page's text, or notes added to a collection entry). Collection
 * properties are dropped: they belong to the old collection's schema, not a task's. The body
 * survives as the task's own notes (read back the same way, via Item::body()), and so does the
 * title.
 */
class ConvertsToTask
{
    use AssignsNextPosition;

    public static function convert(Item $item): void
    {
        DB::transaction(function () use ($item) {
            $body = trim($item->body());

            $item->update([
                'type' => Item::TYPE_TODO,
                'collection_id' => null,
                'parent_id' => null,
                'space_id' => null,
                'properties' => null,
                'content' => $body !== '' ? ['body' => $body] : null,
                'done' => false,
                'position' => self::nextPosition(Item::query()->forHousehold($item->household_id)->ofType(Item::TYPE_TODO)),
            ]);
        });
    }
}
