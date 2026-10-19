<?php

namespace App\Support;

use App\Models\ChecklistTemplate;
use App\Models\Collection;
use App\Models\CollectionView;
use App\Models\Item;
use App\Models\ShoppingList;
use App\Models\User;
use App\Support\Life\TripPlanner;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Lists that can be put into a page, a task's notes or a task's subtasks without a trip: packing
 * lists, checklists (built-in and own templates), the household's shopping lists, and — always
 * live, there is no static form of these — its collections (optionally one of their saved views,
 * so the embed keeps the same filter/sort the member already set up).
 */
class InsertableLists
{
    /**
     * With $withNewDatabase, the first group offers creating a brand-new collection (database)
     * right from the page – the page editor turns such a pick into a live collection embed
     * (see the pages component's createDatabase()). Only pages pass it; a task note or a
     * collection would not create a sibling database.
     *
     * @return list<array{key: string, group: string, icon: string, name: string, count: int, live: bool}>
     */
    public static function options(User $user, int $householdId, bool $withNewDatabase = false): array
    {
        $options = [];

        if ($withNewDatabase) {
            foreach (self::databaseTemplates() as $key => $template) {
                $options[] = ['key' => 'newdb:'.$key, 'group' => __('New database'), 'icon' => $template['icon'], 'name' => $template['label'], 'count' => 0, 'live' => false];
            }
        }

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

        foreach (self::embeddableCollections($user, $householdId) as $collection) {
            $count = Item::query()->forHousehold($householdId)->visibleTo($user)->where('collection_id', $collection->id)->count();
            $icon = $collection->icon ?: '🗂️';
            $options[] = ['key' => 'collection:'.$collection->id, 'group' => __('Collections'), 'icon' => $icon, 'name' => $collection->name, 'count' => $count, 'live' => true];

            // A direct query, not the $collection->views relation: Collection::views() has no
            // generic return-type annotation, so the relation would type as a bare Model here.
            foreach (CollectionView::where('collection_id', $collection->id)->orderBy('position')->orderBy('id')->get() as $view) {
                $options[] = ['key' => 'collection:'.$collection->id.'-'.$view->id, 'group' => __('Collections'), 'icon' => $icon, 'name' => $collection->name.' – '.$view->name, 'count' => $count, 'live' => true];
            }
        }

        return $options;
    }

    /**
     * Collection templates offered when creating a new database from a page. The pantry is left
     * out: it is a system-linked collection (recipes, shopping list) and is set up from its own
     * module, not inline.
     *
     * @return array<string, array{label: string, icon: string, description: string}>
     */
    public static function databaseTemplates(): array
    {
        return collect(CollectionTemplates::all())->except('pantry')->all();
    }

    /**
     * Collections the member can embed: their own collections, not the built-in system ones
     * (Tasks, Pantry) that already have dedicated embeds or pages of their own. Note: a plain
     * where('system_key', '!=', ...) would silently drop every row here, since system_key is
     * null for all of them and SQL's "!=" never matches against null.
     *
     * @return EloquentCollection<int, Collection>
     */
    private static function embeddableCollections(User $user, int $householdId): EloquentCollection
    {
        return Collection::query()->visibleTo($user, $householdId)->whereNull('system_key')->orderBy('name')->get();
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
            // Collections have no static checklist form — they are always embedded live (see
            // markdown() below), so an empty-but-non-null array is enough to pass validation.
            'collection' => Collection::query()->visibleTo($user, $householdId)->whereKey((int) explode('-', $id)[0])->exists() ? [] : null,
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
            if (str_starts_with($key, 'collection:')) {
                [$collectionId, $viewId] = array_pad(explode('-', substr($key, 11), 2), 2, null);

                return ':::embed {type="collection" collection="'.(int) $collectionId.'"'.($viewId ? ' view="'.(int) $viewId.'"' : '').'} :::';
            }

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
