<?php

namespace App\Support;

use App\Models\ExpenseGroup;
use App\Models\Item;
use App\Models\Share;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The contents that can be shared with groups beyond the household, how to find them for a user
 * and where they open.
 */
class Sharing
{
    public const TRIP = 'trip';

    public const SPLIT = 'split';

    public const PAGE = 'page';

    public const TYPES = [self::TRIP, self::SPLIT, self::PAGE];

    /**
     * A content the user may see: through the household or a group.
     *
     * @return Trip|ExpenseGroup|Item|null
     */
    public static function find(string $type, int $id, User $user, int $householdId): ?Model
    {
        return match ($type) {
            self::TRIP => Trip::query()->visibleTo($user, $householdId)->find($id),
            self::SPLIT => ExpenseGroup::query()->visibleTo($user, $householdId)->find($id),
            self::PAGE => Item::query()->ofType(Item::TYPE_NOTE)
                ->where(fn (Builder $query) => $query->where(fn (Builder $query) => $query->forHousehold($householdId)->visibleTo($user))
                    ->orWhere(fn (Builder $query) => $query->sharedWith($user)))
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
            default => null,
        };
    }

    /**
     * @param  Trip|ExpenseGroup|Item  $model
     * @return array{icon: string, label: string}
     */
    public static function describe(Model $model): array
    {
        return match (true) {
            $model instanceof Trip => ['icon' => '✈️', 'label' => __('Journey')],
            $model instanceof ExpenseGroup => ['icon' => $model->icon ?: '🧾', 'label' => __('Cost split')],
            default => ['icon' => $model->icon ?: Item::DEFAULT_PAGE_ICON, 'label' => __('Page')],
        };
    }

    /**
     * Pages of another household open in the shared page view, everything else on its own page.
     */
    /**
     * @param  Trip|ExpenseGroup|Item  $model
     */
    public static function url(Model $model, int $householdId): string
    {
        return match (true) {
            $model instanceof Trip => route('trips.show', $model),
            $model instanceof ExpenseGroup => route('splits.show', $model),
            default => $model->household_id === $householdId ? route('pages.index', ['item' => $model->id]) : route('groups.page', $model),
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
