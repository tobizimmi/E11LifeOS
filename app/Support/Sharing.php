<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\Place;
use App\Models\Recipe;
use App\Models\Share;
use App\Models\ShoppingList;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The contents that can be shared with groups beyond the household, how to find them for a user,
 * where they open and what group members may do with them.
 */
class Sharing
{
    public const TRIP = 'trip';

    public const SPLIT = 'split';

    public const PAGE = 'page';

    public const RECIPE = 'recipe';

    public const SHOPPING = 'shopping';

    public const PLACE = 'place';

    public const COLLECTION = 'collection';

    public const TYPES = [self::TRIP, self::SPLIT, self::PAGE, self::RECIPE, self::SHOPPING, self::PLACE, self::COLLECTION];

    /**
     * A content the user may see: through the household or a group.
     *
     * @return Trip|ExpenseGroup|Item|Recipe|ShoppingList|Place|Collection|null
     */
    public static function find(string $type, int $id, User $user, int $householdId): ?Model
    {
        return match ($type) {
            self::TRIP => Trip::query()->visibleTo($user, $householdId)->find($id),
            self::SPLIT => ExpenseGroup::query()->visibleTo($user, $householdId)->find($id),
            self::RECIPE => Recipe::query()->visibleTo($user, $householdId)->find($id),
            self::PLACE => Place::query()->visibleTo($user, $householdId)->find($id),
            self::PAGE => Item::query()->ofType(Item::TYPE_NOTE)
                ->where(fn (Builder $query) => $query->where(fn (Builder $query) => $query->forHousehold($householdId)->visibleTo($user))
                    ->orWhere(fn (Builder $query) => $query->sharedWith($user)))
                ->find($id),
            self::SHOPPING => ShoppingList::query()
                ->where(fn (Builder $query) => $query->where('household_id', $householdId)->orWhereIn('id', ShoppingList::sharedIdsFor($user)))
                ->find($id),
            // The household's system collections (tasks) are not shared.
            self::COLLECTION => Collection::query()->whereNull('system_key')
                ->where(fn (Builder $query) => $query->where(fn (Builder $query) => $query->visibleTo($user, $householdId))->orWhereIn('id', Collection::sharedIdsFor($user)))
                ->find($id),
            default => null,
        };
    }

    public static function typeOf(Model $model): ?string
    {
        return match (true) {
            $model instanceof Trip => self::TRIP,
            $model instanceof ExpenseGroup => self::SPLIT,
            $model instanceof Item => self::PAGE,
            $model instanceof Recipe => self::RECIPE,
            $model instanceof ShoppingList => self::SHOPPING,
            $model instanceof Place => self::PLACE,
            $model instanceof Collection => self::COLLECTION,
            default => null,
        };
    }

    /**
     * @return array{icon: string, label: string}
     */
    public static function describe(Model $model): array
    {
        return match (true) {
            $model instanceof Trip => ['icon' => '✈️', 'label' => __('Journey')],
            $model instanceof ExpenseGroup => ['icon' => $model->icon ?: '🧾', 'label' => __('Cost split')],
            $model instanceof Recipe => ['icon' => '🍲', 'label' => __('Recipe')],
            $model instanceof ShoppingList => ['icon' => $model->icon ?: '🛒', 'label' => __('Shopping list')],
            $model instanceof Place => ['icon' => Place::categories()[$model->category]['icon'] ?? '📍', 'label' => __('Place')],
            $model instanceof Collection => ['icon' => $model->icon ?: '🗂️', 'label' => __('Collection')],
            $model instanceof Item => ['icon' => $model->icon ?: Item::DEFAULT_PAGE_ICON, 'label' => __('Page')],
            default => ['icon' => '•', 'label' => ''],
        };
    }

    public static function titleOf(Model $model): string
    {
        return (string) ($model instanceof ShoppingList || $model instanceof Place || $model instanceof Collection ? $model->name : $model->getAttribute('title'));
    }

    /**
     * Contents of another household open in a view made for sharing where the normal page would
     * mix in the own household's data.
     */
    public static function url(Model $model, int $householdId): string
    {
        $own = (int) $model->getAttribute('household_id') === $householdId;

        return match (true) {
            $model instanceof Trip => route('trips.show', $model),
            $model instanceof ExpenseGroup => route('splits.show', $model),
            $model instanceof Recipe => route('recipes.show', $model),
            $model instanceof Place => route('places.index', ['q' => $model->name]),
            $model instanceof ShoppingList => $own ? route('shopping-list.index', ['list' => $model->id]) : route('groups.shopping', $model),
            $model instanceof Collection => $own ? route('collections.show', $model) : route('groups.collection', $model),
            default => $own ? route('pages.index', ['item' => $model->getKey()]) : route('groups.page', $model),
        };
    }

    /**
     * What members of a group may do with a content of this kind.
     */
    public static function permissions(string $type): string
    {
        return match ($type) {
            self::RECIPE => __('Group members can view and cook it, put it on their shopping list or save a copy, but not change or delete it.'),
            self::SHOPPING => __('Group members can view the list, tick items off and add new ones.'),
            self::COLLECTION => __('Group members can view the shared entries, add new ones and tick them off, but not change the collection itself.'),
            default => __('Group members can view and edit it, but not delete it or share it further.'),
        };
    }

    /**
     * Shares whose content still exists and is one of the shareable kinds.
     *
     * @param  iterable<Share>  $shares
     * @return list<Share>
     */
    public static function existing(iterable $shares): array
    {
        $result = [];

        foreach ($shares as $share) {
            if ($share->shareable !== null && self::typeOf($share->shareable) !== null) {
                $result[] = $share;
            }
        }

        return $result;
    }
}
