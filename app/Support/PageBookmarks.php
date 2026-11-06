<?php

namespace App\Support;

use App\Models\Item;
use App\Models\PageUserState;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Favourites and "recently visited" for wiki pages, kept per user in page_user_states.
 *
 * Every list is scoped to pages the user can still see, so a page that was shared and later made
 * private (or deleted) simply drops out of the sidebar.
 */
class PageBookmarks
{
    /**
     * Remember that the user just opened this page (for the "recently visited" list).
     */
    public static function recordVisit(Item $page, User $user): void
    {
        PageUserState::query()->updateOrCreate(
            ['user_id' => $user->id, 'item_id' => $page->id],
            ['visited_at' => now()],
        );
    }

    /**
     * Pin or unpin a page; returns the new favourite state.
     */
    public static function toggleFavorite(Item $page, User $user): bool
    {
        $state = PageUserState::query()->firstOrNew(['user_id' => $user->id, 'item_id' => $page->id]);
        $state->favorite = ! $state->favorite;
        $state->save();

        return $state->favorite;
    }

    /**
     * Ids of the user's favourite pages, for marking the star in lists.
     *
     * @return array<int, int>
     */
    public static function favoriteIds(User $user): array
    {
        return PageUserState::query()
            ->where('user_id', $user->id)
            ->where('favorite', true)
            ->pluck('item_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The user's favourite pages that are still visible, by title.
     *
     * @return Collection<int, Item>
     */
    public static function favorites(User $user, int $householdId): Collection
    {
        $ids = self::favoriteIds($user);

        if ($ids === []) {
            return collect();
        }

        return Item::query()
            ->forHousehold($householdId)
            ->visibleTo($user)
            ->where('type', Item::TYPE_NOTE)
            ->whereIn('id', $ids)
            ->orderByRaw('LOWER(title)')
            ->get(['id', 'title', 'icon']);
    }

    /**
     * The user's most recently opened pages that are still visible, newest first.
     *
     * @return Collection<int, Item>
     */
    public static function recent(User $user, int $householdId, int $limit = 6, ?int $excludeId = null): Collection
    {
        $orderedIds = PageUserState::query()
            ->where('user_id', $user->id)
            ->whereNotNull('visited_at')
            ->when($excludeId !== null, fn ($query) => $query->where('item_id', '!=', $excludeId))
            ->orderByDesc('visited_at')
            ->limit($limit * 2)
            ->pluck('item_id')
            ->all();

        if ($orderedIds === []) {
            return collect();
        }

        $pages = Item::query()
            ->forHousehold($householdId)
            ->visibleTo($user)
            ->where('type', Item::TYPE_NOTE)
            ->whereIn('id', $orderedIds)
            ->get(['id', 'title', 'icon'])
            ->keyBy('id');

        /** @var array<int, Item> $ordered */
        $ordered = [];

        foreach ($orderedIds as $id) {
            $page = $pages->get($id);

            if ($page instanceof Item) {
                $ordered[] = $page;

                if (count($ordered) >= $limit) {
                    break;
                }
            }
        }

        return collect($ordered);
    }
}
