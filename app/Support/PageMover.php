<?php

namespace App\Support;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Moves pages in the tree (new parent and/or position among the siblings).
 */
class PageMover
{
    /**
     * The parent must be a page the user can see, and a page may not become its own
     * ancestor. Descendants are computed over all household pages, so a cycle cannot
     * be built through someone else's private page either.
     */
    public static function parentIsAllowed(?Item $page, ?int $parentId, User $user, int $householdId): bool
    {
        if ($parentId === null) {
            return true;
        }

        if (! Item::query()->forHousehold($householdId)->ofType(Item::TYPE_NOTE)->visibleTo($user)->whereKey($parentId)->exists()) {
            return false;
        }

        if ($page === null) {
            return true;
        }

        $allPages = new PageTree(
            Item::query()->forHousehold($householdId)->ofType(Item::TYPE_NOTE)->get(['id', 'parent_id', 'title', 'position'])
        );

        return $parentId !== $page->id && ! in_array($parentId, $allPages->descendantIds($page->id), true);
    }

    /**
     * Position for a page added under the parent: pages are sorted by position, then title,
     * so it stays alphabetical until someone arranges the siblings by hand.
     */
    public static function positionForNewChild(int $householdId, ?int $parentId): int
    {
        $max = (int) Item::query()->forHousehold($householdId)->ofType(Item::TYPE_NOTE)->where('parent_id', $parentId)->max('position');

        return $max > 0 ? $max + 1 : 0;
    }

    /**
     * Puts the page under the parent at the given index of the siblings the user sees.
     */
    public static function move(Item $page, ?int $parentId, int $index, User $user): bool
    {
        if (! self::parentIsAllowed($page, $parentId, $user, $page->household_id)) {
            return false;
        }

        $siblings = (new PageTree(
            Item::query()->forHousehold($page->household_id)->ofType(Item::TYPE_NOTE)->visibleTo($user)
                ->where('parent_id', $parentId)->whereKeyNot($page->id)
                ->get(['id', 'parent_id', 'title', 'position'])
        ))->roots();

        array_splice($siblings, max(0, min($index, count($siblings))), 0, [$page]);

        DB::transaction(function () use ($siblings, $page, $parentId) {
            foreach (array_values($siblings) as $position => $sibling) {
                $attributes = ['position' => $position + 1];

                if ($sibling->id === $page->id) {
                    $attributes['parent_id'] = $parentId;
                }

                Item::whereKey($sibling->id)->update($attributes);
            }
        });

        return true;
    }
}
