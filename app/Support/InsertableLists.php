<?php

namespace App\Support;

use App\Models\ChecklistTemplate;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\Life\TripPlanner;

/**
 * Lists that can be put into a page, a task's notes or a task's subtasks without a trip: packing
 * lists, checklists (built-in and own templates) and the household's shopping lists.
 */
class InsertableLists
{
    /**
     * @return list<array{key: string, group: string, icon: string, name: string, count: int, live: bool}>
     */
    public static function options(User $user, int $householdId): array
    {
        $options = [];

        foreach (TripPlanner::templates() as $key => $template) {
            $options[] = ['key' => 'packing:'.$key, 'group' => __('Packing lists'), 'icon' => $template['icon'], 'name' => $template['label'], 'count' => count($template['items']), 'live' => false];
        }

        foreach (ChecklistTemplate::query()->visibleTo($user, $householdId)->orderBy('name')->get() as $template) {
            $options[] = ['key' => 'template:'.$template->id, 'group' => __('My templates'), 'icon' => $template->icon ?: '📋', 'name' => $template->name, 'count' => count(self::templateItems($template)), 'live' => false];
        }

        foreach (ChecklistTemplates::builtIn() as $key => $template) {
            $options[] = ['key' => 'checklist:'.$key, 'group' => __('Checklists'), 'icon' => $template['icon'], 'name' => $template['name'], 'count' => array_sum(array_map(fn (array $task) => count($task['subtasks']), $template['tasks'])), 'live' => false];
        }

        foreach (ShoppingList::where('household_id', $householdId)->withCount(['entries' => fn ($query) => $query->where('checked', false)])->orderBy('position')->orderBy('id')->get() as $list) {
            $options[] = ['key' => 'shopping:'.$list->id, 'group' => __('Shopping lists'), 'icon' => $list->icon ?: '🛒', 'name' => $list->name, 'count' => (int) $list->entries_count, 'live' => true];
        }

        return $options;
    }

    /**
     * The items of a list in sections: [section title => items]. Null for unknown or foreign lists.
     *
     * @return array<string, list<string>>|null
     */
    public static function sections(User $user, int $householdId, string $key): ?array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');

        return match ($kind) {
            'packing' => isset(TripPlanner::templates()[$id]) ? self::byCategory(TripPlanner::templates()[$id]['items']) : null,
            'checklist' => isset(ChecklistTemplates::builtIn()[$id]) ? self::byTask(ChecklistTemplates::builtIn()[$id]['tasks']) : null,
            'template' => ($template = ChecklistTemplate::query()->visibleTo($user, $householdId)->find((int) $id)) ? ($template->packing ? self::byCategory($template->items()) : self::byTask($template->tasks)) : null,
            'shopping' => ($list = ShoppingList::where('household_id', $householdId)->find((int) $id))
                ? ['' => $list->entries()->where('checked', false)->orderBy('position')->get()->map(fn ($entry) => trim($entry->quantity.' '.$entry->label))->all()]
                : null,
            default => null,
        };
    }

    /**
     * The list as a Markdown checklist (with a heading), or the live block of a shopping list.
     */
    public static function markdown(User $user, int $householdId, string $key, bool $live = false): ?string
    {
        $option = collect(self::options($user, $householdId))->firstWhere('key', $key);
        $sections = self::sections($user, $householdId, $key);

        if (! $option || $sections === null) {
            return null;
        }

        if ($live && $option['live']) {
            return ':::embed {type="shopping" list="'.(int) substr($key, 9).'"} :::';
        }

        $blocks = ['### '.$option['icon'].' '.$option['name']];

        foreach ($sections as $title => $items) {
            if ($items === []) {
                continue;
            }

            $lines = $title !== '' && count($sections) > 1 ? ['**'.$title.'**', ''] : [];

            foreach ($items as $item) {
                $lines[] = '- [ ] '.str_replace(["\n", '[', ']'], [' ', '(', ')'], $item);
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Every item of a list in one flat list (for subtasks).
     *
     * @return list<string>
     */
    public static function items(User $user, int $householdId, string $key): array
    {
        return array_merge(...array_values(self::sections($user, $householdId, $key) ?? [[]]));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $items
     * @return array<string, list<string>>
     */
    private static function byCategory(array $items): array
    {
        $categories = TripPlanner::categories();
        $sections = [];

        foreach ($items as $item) {
            $category = $categories[$item[1]] ?? $categories['other'];
            $sections[$category['icon'].' '.$category['label']][] = (string) $item[0];
        }

        // In the order of the categories.
        $order = array_map(fn (array $category) => $category['icon'].' '.$category['label'], array_values($categories));
        uksort($sections, fn (string $a, string $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $sections;
    }

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<string, list<string>>
     */
    private static function byTask(array $tasks): array
    {
        $sections = [];

        foreach ($tasks as $task) {
            $subtasks = array_values(array_map('strval', (array) ($task['subtasks'] ?? [])));

            if ($subtasks !== []) {
                $sections[(string) ($task['title'] ?? '')] = $subtasks;
            }
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    private static function templateItems(ChecklistTemplate $template): array
    {
        return array_map(fn (array $item) => $item[0], $template->items());
    }
}
