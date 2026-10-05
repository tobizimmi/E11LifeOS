<?php

namespace App\Support\Life;

use App\Models\Collection;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\ItemActivity;
use App\Models\MealPlan;
use App\Models\ShoppingListEntry;

/**
 * Who carries how much of the household ("mental load"): tasks and entries completed, things
 * thought of and planned (tasks, shopping entries, meals created) and open tasks assigned, per
 * member over the last days, with each member's share and a hint when it is lopsided.
 */
class HouseholdLoad
{
    /**
     * Share above which the split counts as lopsided.
     */
    public const LOPSIDED_SHARE = 65;

    /**
     * @return array{days: int, members: list<array{user_id: int, name: string, completed: int, planned: int, open: int, share: int}>, lopsided: ?string}
     */
    public static function for(Household $household, int $days = 30): array
    {
        $since = now()->subDays($days);
        $members = HouseholdMember::where('household_id', $household->id)->with('user:id,name')->get()->filter(fn (HouseholdMember $member) => $member->user !== null);
        $itemIds = Item::where('household_id', $household->id)->select('id');

        $completed = ItemActivity::whereIn('item_id', $itemIds)->where('action', 'completed')->where('created_at', '>=', $since)
            ->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');
        $created = ItemActivity::whereIn('item_id', $itemIds)->where('action', 'created')->where('created_at', '>=', $since)
            ->selectRaw('user_id, count(*) as total')->groupBy('user_id')->pluck('total', 'user_id');
        $shopping = ShoppingListEntry::where('household_id', $household->id)->where('created_at', '>=', $since)
            ->selectRaw('created_by, count(*) as total')->groupBy('created_by')->pluck('total', 'created_by');
        $meals = MealPlan::where('household_id', $household->id)->where('created_at', '>=', $since)
            ->selectRaw('created_by, count(*) as total')->groupBy('created_by')->pluck('total', 'created_by');

        $open = [];
        $tasks = Collection::where('household_id', $household->id)->where('system_key', Collection::SYSTEM_TASKS)->with('properties')->first();
        $assignee = $tasks?->systemProperty('assignee');

        if ($tasks && $assignee) {
            foreach (Item::where('collection_id', $tasks->id)->where('done', false)->get() as $task) {
                $userId = (int) $task->propertyValue($assignee);

                if ($userId > 0) {
                    $open[$userId] = ($open[$userId] ?? 0) + 1;
                }
            }
        }

        $rows = $members->map(fn (HouseholdMember $member) => [
            'user_id' => $member->user_id,
            'name' => $member->user->name,
            'completed' => (int) ($completed[$member->user_id] ?? 0),
            'planned' => (int) ($created[$member->user_id] ?? 0) + (int) ($shopping[$member->user_id] ?? 0) + (int) ($meals[$member->user_id] ?? 0),
            'open' => (int) ($open[$member->user_id] ?? 0),
        ])->values();

        $total = $rows->sum(fn (array $row) => $row['completed'] + $row['planned']);
        $rows = $rows->map(fn (array $row) => $row + ['share' => $total > 0 ? (int) round(($row['completed'] + $row['planned']) / $total * 100) : 0])->sortByDesc('share')->values();
        $top = $rows->first();

        return [
            'days' => $days,
            'members' => $rows->all(),
            'lopsided' => $rows->count() > 1 && $total >= 10 && $top['share'] >= self::LOPSIDED_SHARE ? $top['name'] : null,
        ];
    }
}
