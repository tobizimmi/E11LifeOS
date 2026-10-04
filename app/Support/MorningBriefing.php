<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\MealPlan;
use App\Models\ShoppingListEntry;
use App\Models\User;
use App\Support\SmartHome\EnergySummary;

/**
 * The daily morning briefing of a member: weather, today's tasks, today's dates from the
 * collections, the meal plan and the shopping list. Only what the member may see.
 */
class MorningBriefing
{
    public const CHANNELS = ['off', 'email', 'push', 'both'];

    /**
     * @return array{date: string, weather: ?string, sections: list<array{icon: string, title: string, lines: list<string>, url: string}>}
     */
    public static function for(User $user): array
    {
        $household = $user->currentHousehold();
        $weather = $user->weather_latitude !== null && $user->weather_longitude !== null
            ? Weather::today($user->weather_latitude, $user->weather_longitude)
            : null;
        $briefing = [
            'date' => today()->translatedFormat('l, j. F'),
            'weather' => $weather ? $user->weather_place.': '.Weather::summary($weather) : null,
            'sections' => [],
        ];

        if (! $household) {
            return $briefing;
        }

        $sections = [self::tasks($user, $household->id), self::dates($user, $household->id)];

        if ($user->canUseAddon(Addons::RECIPES)) {
            $sections[] = self::meals($user, $household->id);
        }

        if ($user->canUseAddon(Addons::SMART_HOME)) {
            $sections[] = ['icon' => '⚡', 'title' => __('Energy yesterday'), 'lines' => EnergySummary::lines($household->id, today()->subDay(), today()->subDay()), 'url' => route('smart-home.index', ['tab' => 'energy'])];
        }

        $openShopping = ShoppingListEntry::where('household_id', $household->id)->where('checked', false)->count();

        if ($openShopping > 0) {
            $sections[] = ['icon' => '🛒', 'title' => __('Shopping List'), 'lines' => [trans_choice(':count item still open|:count items still open', $openShopping)], 'url' => route('shopping-list.index')];
        }

        $briefing['sections'] = array_values(array_filter($sections, fn (array $section) => $section['lines'] !== []));

        return $briefing;
    }

    /**
     * Push text: weather first, then the first line of every section.
     */
    public static function pushBody(array $briefing): string
    {
        return collect([$briefing['weather']])
            ->concat(collect($briefing['sections'])->map(fn (array $section) => $section['icon'].' '.($section['lines'][0] ?? $section['title'])))
            ->filter()->take(5)->implode("\n") ?: __('Nothing due today.');
    }

    /**
     * Own (or unassigned own) open tasks due today, with their time; overdue ones counted.
     *
     * @return array{icon: string, title: string, lines: list<string>, url: string}
     */
    private static function tasks(User $user, int $householdId): array
    {
        $collection = Collection::tasksFor($householdId);
        $dueProperty = $collection->systemProperty('due');
        $assigneeProperty = $collection->systemProperty('assignee');

        if (! $dueProperty) {
            return ['icon' => '✅', 'title' => __('Tasks today'), 'lines' => [], 'url' => route('tasks.index')];
        }

        $today = today()->toDateString();
        $mine = fn (Item $task) => ($assignee = $assigneeProperty ? (string) $task->propertyValue($assigneeProperty) : '') !== ''
            ? $assignee === (string) $user->id
            : $task->created_by === $user->id;
        $open = Item::query()->forHousehold($householdId)->ofType(Item::TYPE_TODO)->visibleTo($user)->where('done', false)->get()
            ->filter($mine)
            ->map(fn (Item $task) => ['task' => $task, 'due' => (string) $task->propertyValue($dueProperty)])
            ->filter(fn (array $row) => $row['due'] !== '' && $row['due'] <= $today);

        $dueToday = $open->where('due', $today)->sortBy(fn (array $row) => $row['task']->due_time ?? '99:99');
        $overdue = $open->count() - $dueToday->count();
        $lines = $dueToday->take(8)->map(fn (array $row) => ($row['task']->due_time ? $row['task']->due_time.' ' : '').$row['task']->title)->values()->all();

        if ($dueToday->count() > 8) {
            $lines[] = __('and :count more', ['count' => $dueToday->count() - 8]);
        }

        if ($overdue > 0) {
            $lines[] = '⚠ '.trans_choice(':count task is overdue|:count tasks are overdue', $overdue);
        }

        return ['icon' => '✅', 'title' => __('Tasks today'), 'lines' => $lines, 'url' => route('tasks.index')];
    }

    /**
     * Entries of the member's collections with a date property on today (appointments,
     * birthdays, deadlines …).
     *
     * @return array{icon: string, title: string, lines: list<string>, url: string}
     */
    private static function dates(User $user, int $householdId): array
    {
        $today = today()->toDateString();
        $lines = [];

        Collection::query()->visibleTo($user, $householdId)->whereNull('system_key')->with('properties')->get()
            ->each(function (Collection $collection) use ($user, $today, &$lines) {
                $properties = $collection->properties->where('type', CollectionProperty::TYPE_DATE);

                if ($properties->isEmpty()) {
                    return;
                }

                Item::query()->forHousehold($collection->household_id)->visibleTo($user)->where('collection_id', $collection->id)->where('done', false)->get()
                    ->each(function (Item $entry) use ($properties, $today, $collection, &$lines) {
                        foreach ($properties as $property) {
                            if ((string) $entry->propertyValue($property) === $today) {
                                $lines[] = $entry->title.' – '.$property->name.' ('.$collection->name.')';
                            }
                        }
                    });
            });

        return ['icon' => '📅', 'title' => __('Today in your collections'), 'lines' => array_slice($lines, 0, 8), 'url' => route('collections.index')];
    }

    /**
     * @return array{icon: string, title: string, lines: list<string>, url: string}
     */
    private static function meals(User $user, int $householdId): array
    {
        $mealOrder = array_flip(MealPlan::MEAL_TYPES);
        $meals = MealPlan::query()->visibleTo($user, $householdId)->whereDate('date', today())->with('recipe')->get();
        $lines = MealPlan::withVisibleRecipes($meals, $user, $householdId)
            ->sortBy(fn (MealPlan $meal) => [$mealOrder[$meal->meal_type] ?? 9, $meal->id])
            ->map(fn (MealPlan $meal) => MealPlan::mealTypeLabel($meal->meal_type).': '.($meal->recipe?->title ?? $meal->title))
            ->values()->all();

        return ['icon' => '🍳', 'title' => __('Today on the meal plan'), 'lines' => $lines, 'url' => route('recipes.plan')];
    }
}
