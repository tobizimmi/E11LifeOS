<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\Item;
use App\Models\Link;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Tasks and pages that belong together: a task links to pages (and the other way round), and
 * tasks can be created right on a page. Links work in both directions.
 */
class TaskPages
{
    /**
     * Ids of the items linked to the item, whichever side the link was made on.
     *
     * @return list<int>
     */
    private static function linkedIds(Item $item): array
    {
        return Link::where(fn ($query) => $query->where(['from_type' => Links::ITEM, 'from_id' => $item->id])->where('to_type', Links::ITEM))
            ->orWhere(fn ($query) => $query->where(['to_type' => Links::ITEM, 'to_id' => $item->id])->where('from_type', Links::ITEM))
            ->get()
            ->map(fn (Link $link) => $link->from_id === $item->id && $link->from_type === Links::ITEM ? $link->to_id : $link->from_id)
            ->unique()->values()->all();
    }

    /**
     * Pages linked to the task that the viewer may see.
     *
     * @return EloquentCollection<int, Item>
     */
    public static function pagesOf(Item $task, User $viewer): EloquentCollection
    {
        return Item::query()->forHousehold($task->household_id)->visibleTo($viewer)
            ->where('type', Item::TYPE_NOTE)->whereIn('id', self::linkedIds($task))->orderBy('title')->get();
    }

    /**
     * Tasks linked to the page that the viewer may see (open ones first).
     *
     * @return EloquentCollection<int, Item>
     */
    public static function tasksOf(Item $page, User $viewer): EloquentCollection
    {
        $collection = Collection::tasksFor($page->household_id);

        return Item::query()->forHousehold($page->household_id)->visibleTo($viewer)
            ->where('collection_id', $collection->id)->whereIn('id', self::linkedIds($page))
            ->orderBy('done')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Creates a task on a page: linked to it and in the page's space.
     */
    public static function createOnPage(Item $page, User $author, string $text): ?Item
    {
        $task = QuickCapture::task($author, $page->household_id, $text);

        if (! $task) {
            return null;
        }

        $task->forceFill(['space_id' => $page->space_id])->save();
        Links::addManual(Links::ITEM, $page->id, Links::ITEM, $task->id, $page->household_id, $author->id);

        return $task;
    }
}
