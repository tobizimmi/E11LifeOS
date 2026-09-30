<?php

namespace App\Support;

use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Smart lists of the tasks page: Today (incl. overdue), Upcoming, All, Mine and Done,
 * each grouped by date (Overdue, Today, Tomorrow, This week, Later, No date).
 */
class TaskLists
{
    public const TODAY = 'today';

    public const UPCOMING = 'upcoming';

    public const ALL = 'all';

    public const MINE = 'mine';

    public const DONE = 'done';

    public const LISTS = [self::TODAY, self::UPCOMING, self::ALL, self::MINE, self::DONE];

    private const PRIORITY_ORDER = [Collection::TASK_PRIORITY_HIGH => 0, Collection::TASK_PRIORITY_MEDIUM => 1, Collection::TASK_PRIORITY_LOW => 2];

    private const DONE_LIMIT = 100;

    public readonly ?CollectionProperty $dueProperty;

    public readonly ?CollectionProperty $assigneeProperty;

    public readonly ?CollectionProperty $priorityProperty;

    public readonly ?CollectionProperty $repeatProperty;

    public readonly ?CollectionProperty $categoryProperty;

    private readonly string $today;

    /**
     * @var EloquentCollection<int, Item>|null
     */
    private ?EloquentCollection $openTasks = null;

    public function __construct(public readonly Collection $collection, public readonly User $viewer, ?Carbon $today = null)
    {
        $this->dueProperty = $collection->systemProperty('due');
        $this->assigneeProperty = $collection->systemProperty('assignee');
        $this->priorityProperty = $collection->systemProperty('priority');
        $this->repeatProperty = $collection->systemProperty('repeat');
        $this->categoryProperty = $collection->systemProperty('category');
        $this->today = ($today ?? now())->toDateString();
    }

    public static function label(string $list): string
    {
        return match ($list) {
            self::TODAY => __('Today'),
            self::UPCOMING => __('Upcoming'),
            self::MINE => __('Mine'),
            self::DONE => __('Done'),
            default => __('All'),
        };
    }

    public static function icon(string $list): string
    {
        return match ($list) {
            self::TODAY => '☀️',
            self::UPCOMING => '📅',
            self::MINE => '👤',
            self::DONE => '✔️',
            default => '🗂️',
        };
    }

    /**
     * Number of open tasks per list (not for "done").
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $open = $this->openTasks();

        return [
            self::TODAY => $open->filter(fn (Item $task) => $this->isDueTodayOrOverdue($task))->count(),
            self::UPCOMING => $open->filter(fn (Item $task) => $this->due($task) > $this->today)->count(),
            self::ALL => $open->count(),
            self::MINE => $open->filter(fn (Item $task) => $this->isMine($task))->count(),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, tone: string, tasks: array<int, Item>}>
     */
    public function groups(string $list, string $tag = '', string $search = '', string $category = '', string $space = ''): array
    {
        $tasks = $list === self::DONE ? $this->doneTasks() : $this->openTasks();
        $term = mb_strtolower(trim($search));

        $tasks = $tasks
            ->when($tag !== '', fn ($tasks) => $tasks->filter(fn (Item $task) => $task->tags->contains('name', $tag)))
            ->when($category !== '', fn ($tasks) => $tasks->filter(fn (Item $task) => $this->category($task) === $category))
            ->when($space !== '', fn ($tasks) => $tasks->filter(fn (Item $task) => (string) $task->space_id === $space))
            ->when($term !== '', fn ($tasks) => $tasks->filter(fn (Item $task) => str_contains(mb_strtolower($task->title.' '.$task->body()), $term)))
            ->filter(fn (Item $task) => match ($list) {
                self::TODAY => $this->isDueTodayOrOverdue($task),
                self::UPCOMING => $this->due($task) > $this->today,
                self::MINE => $this->isMine($task),
                default => true,
            });

        if ($list === self::DONE) {
            return $tasks->groupBy(fn (Item $task) => $this->completedGroup($task)[0])
                ->map(fn ($group, string $key) => ['key' => $key, 'label' => $this->completedGroup($group->first())[1], 'tone' => 'muted', 'tasks' => $group->values()->all()])
                ->values()
                ->all();
        }

        $groups = [];

        foreach ($this->sorted($tasks) as $task) {
            [$key, $label, $tone] = $list === self::UPCOMING ? $this->upcomingGroup($task) : $this->dateGroup($task);
            $groups[$key] ??= ['key' => $key, 'label' => $label, 'tone' => $tone, 'tasks' => []];
            $groups[$key]['tasks'][] = $task;
        }

        return array_values($groups);
    }

    /**
     * Weekly review: tasks completed this week (since Monday) and last week, and what is overdue.
     *
     * @return array{thisWeek: int, lastWeek: int, overdue: int}
     */
    public function review(): array
    {
        $monday = Carbon::parse($this->today)->startOfWeek(Carbon::MONDAY);
        $completed = fn (Carbon $from, Carbon $until) => Item::query()
            ->forHousehold($this->collection->household_id)
            ->visibleTo($this->viewer)
            ->where('collection_id', $this->collection->id)
            ->where('done', true)
            ->whereBetween('completed_at', [$from, $until])
            ->count();

        return [
            'thisWeek' => $completed($monday, $monday->copy()->endOfWeek(Carbon::SUNDAY)),
            'lastWeek' => $completed($monday->copy()->subWeek(), $monday->copy()->subSecond()),
            'overdue' => $this->openTasks()->filter(fn (Item $task) => $this->isOverdue($task))->count(),
        ];
    }

    public function due(Item $task): string
    {
        return $this->dueProperty ? (string) $task->propertyValue($this->dueProperty) : '';
    }

    public function priority(Item $task): ?string
    {
        $value = $this->priorityProperty ? $task->propertyValue($this->priorityProperty) : null;

        return isset(self::PRIORITY_ORDER[$value]) ? $value : null;
    }

    /**
     * The id of the task's category option, or null.
     */
    public function category(Item $task): ?string
    {
        $value = $this->categoryProperty ? $task->propertyValue($this->categoryProperty) : null;

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * @return array<string, array{id: string, name: string, color: string}>
     */
    public function categories(): array
    {
        return collect($this->categoryProperty?->options ?? [])->keyBy('id')->all();
    }

    /**
     * Open tasks per category id (for the filter chips).
     *
     * @return array<string, int>
     */
    public function categoryCounts(): array
    {
        return $this->openTasks()->map(fn (Item $task) => $this->category($task))->filter()->countBy()->all();
    }

    public function repeat(Item $task): ?string
    {
        return $this->repeatProperty ? ($task->propertyValue($this->repeatProperty) ?: null) : null;
    }

    public function assigneeId(Item $task): ?int
    {
        $value = $this->assigneeProperty ? $task->propertyValue($this->assigneeProperty) : null;

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    public function isOverdue(Item $task): bool
    {
        $due = $this->due($task);

        return ! $task->done && $due !== '' && $due < $this->today;
    }

    /**
     * Assigned to the viewer, or unassigned and created by them.
     */
    public function isMine(Item $task): bool
    {
        $assignee = $this->assigneeId($task);

        return $assignee !== null ? $assignee === $this->viewer->id : $task->created_by === $this->viewer->id;
    }

    /**
     * @return EloquentCollection<int, Item>
     */
    private function openTasks(): EloquentCollection
    {
        return $this->openTasks ??= $this->query()->where('done', false)->get();
    }

    /**
     * @return EloquentCollection<int, Item>
     */
    private function doneTasks(): EloquentCollection
    {
        return $this->query()->where('done', true)->reorder()->orderByRaw('COALESCE(items.completed_at, items.updated_at) DESC')->limit(self::DONE_LIMIT)->get();
    }

    private function query()
    {
        return Item::query()
            ->forHousehold($this->collection->household_id)
            ->visibleTo($this->viewer)
            ->where('collection_id', $this->collection->id)
            ->with(['tags', 'collection.properties'])
            ->orderBy('position');
    }

    private function isDueTodayOrOverdue(Item $task): bool
    {
        $due = $this->due($task);

        return $due !== '' && $due <= $this->today;
    }

    /**
     * Dated tasks first (earliest on top), then priority, then manual order.
     *
     * @param  \Illuminate\Support\Collection<int, Item>  $tasks
     * @return \Illuminate\Support\Collection<int, Item>
     */
    private function sorted(\Illuminate\Support\Collection $tasks): \Illuminate\Support\Collection
    {
        return $tasks->sortBy(fn (Item $task) => [
            $this->due($task) === '' ? '9999-99-99' : $this->due($task),
            $task->due_time ?? '99:99',
            self::PRIORITY_ORDER[$this->priority($task)] ?? 3,
            $task->position,
            $task->id,
        ])->values();
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function dateGroup(Item $task): array
    {
        $due = $this->due($task);
        $today = Carbon::parse($this->today);

        return match (true) {
            $due === '' => ['none', __('No date'), 'muted'],
            $due < $this->today => ['overdue', __('Overdue'), 'danger'],
            $due === $this->today => ['today', __('Today'), 'accent'],
            $due === $today->copy()->addDay()->toDateString() => ['tomorrow', __('Tomorrow'), 'normal'],
            $due <= $today->copy()->addDays(7)->toDateString() => ['week', __('Next 7 days'), 'normal'],
            default => ['later', __('Later'), 'muted'],
        };
    }

    /**
     * Done list: today, yesterday, the last days by weekday, then per month.
     *
     * @return array{0: string, 1: string}
     */
    private function completedGroup(Item $task): array
    {
        $at = ($task->completed_at ?? $task->updated_at)->copy()->startOfDay();
        $days = (int) $at->diffInDays(Carbon::parse($this->today), false);

        return match (true) {
            $days <= 0 => ['done-today', __('Today')],
            $days === 1 => ['done-yesterday', __('Yesterday')],
            $days < 7 => ['done-'.$at->toDateString(), $at->translatedFormat('l, j. F')],
            default => ['done-'.$at->format('Y-m'), $at->translatedFormat('F Y')],
        };
    }

    /**
     * Upcoming: one group per day for the next two weeks, then per month.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function upcomingGroup(Item $task): array
    {
        $due = Carbon::createFromFormat('!Y-m-d', $this->due($task));
        $today = Carbon::parse($this->today);

        if ($due->lte($today->copy()->addDays(14))) {
            $label = $due->isSameDay($today->copy()->addDay()) ? __('Tomorrow').' · '.$due->translatedFormat('l, j. F') : $due->translatedFormat('l, j. F');

            return ['day-'.$due->toDateString(), $label, 'normal'];
        }

        return ['month-'.$due->format('Y-m'), $due->translatedFormat('F Y'), 'muted'];
    }
}
