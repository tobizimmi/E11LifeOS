<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Livewire\Concerns\SuggestsTags;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\HouseholdMember;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use App\Models\ChecklistTemplate;
use App\Support\BrainAi;
use App\Support\ChecklistTemplates;
use App\Support\QuickCapture;
use App\Support\Recurrence;
use App\Support\TaskInput;
use App\Support\TaskLists;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;
    use SuggestsTags;

    private const MAX_SUBTASKS = 100;

    #[Url(except: TaskLists::TODAY)]
    public string $list = TaskLists::TODAY;

    #[Url(except: '')]
    public string $tag = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Task shown in the detail panel (id as string, so a malformed URL can't break the page).
     */
    #[Url(as: 'task', except: '')]
    public string $openTaskId = '';

    /**
     * Current text of the quick entry (only for the live preview; the input itself is not bound,
     * so a late preview request can never bring back text that was already added).
     */
    public string $draft = '';

    /**
     * Recognised phrases of the quick entry the user wants to keep in the title.
     *
     * @var array<int, string>
     */
    public array $ignoredPhrases = [];

    public bool $selecting = false;

    public bool $templatesOpen = false;

    /**
     * Chosen checklist: a built-in key or "own-{id}".
     */
    public string $templateKey = '';

    public string $templateDate = '';

    /**
     * Ids of the tasks picked in selection mode.
     *
     * @var array<int, int>
     */
    public array $selected = [];

    /**
     * AI tag suggestions for the open task.
     *
     * @var array<int, string>
     */
    public array $tagSuggestions = [];

    public function mount(): void
    {
        if (! in_array($this->list, TaskLists::LISTS, true)) {
            $this->list = TaskLists::TODAY;
        }
    }

    private function collection(): Collection
    {
        return Collection::tasksFor((int) $this->householdId)->load('properties');
    }

    private function findTask(int $id): Item
    {
        return Item::query()
            ->forHousehold((int) $this->householdId)
            ->visibleTo($this->currentUser())
            ->where('collection_id', $this->collection()->id)
            ->find($id) ?? abort(404);
    }

    /**
     * @return array<int, string>
     */
    private function members(): array
    {
        return QuickCapture::members((int) $this->householdId);
    }

    private function systemProperty(string $key): CollectionProperty
    {
        return $this->collection()->systemProperty($key) ?? abort(422);
    }

    private function setValue(Item $task, string $key, mixed $value): void
    {
        $property = $this->systemProperty($key);
        $task->setPropertyValue($property, $property->normalizeValue($value, array_keys($this->members())));
    }

    /**
     * "today", "tomorrow", "next_week", "weekend", "none" or a date (Y-m-d).
     */
    private function resolveDate(string $preset): ?string
    {
        $today = now()->startOfDay();

        return match ($preset) {
            'today' => $today->toDateString(),
            'tomorrow' => $today->addDay()->toDateString(),
            'next_week' => $today->next(Carbon::MONDAY)->toDateString(),
            'weekend' => ($today->isSaturday() ? $today : $today->next(Carbon::SATURDAY))->toDateString(),
            'none', '' => null,
            default => preg_match('/^\d{4}-\d{2}-\d{2}$/', $preset) && strtotime($preset) ? $preset : abort(422),
        };
    }

    // ── Lists ────────────────────────────────────────────────────────────

    public function showList(string $list): void
    {
        abort_unless(in_array($list, TaskLists::LISTS, true), 404);

        $this->list = $list;
        $this->selected = [];
    }

    public function filterByTag(string $name): void
    {
        $this->tag = $this->tag === $name ? '' : $name;
    }

    // ── Quick entry ──────────────────────────────────────────────────────

    public function ignorePhrase(string $phrase): void
    {
        $this->ignoredPhrases[] = mb_substr($phrase, 0, 100);
    }

    public function previewTask(string $text): void
    {
        $this->draft = mb_substr($text, 0, 500);
    }

    // ── Checklist templates ──────────────────────────────────────────────

    /**
     * @return array<string, array{name: string, icon: string, anchor: string, default_offset: int, tasks: list<array{title: string, offset: int, subtasks: list<string>}>, own: bool}>
     */
    private function checklistTemplates(): array
    {
        $own = ChecklistTemplate::where('household_id', $this->householdId)->orderBy('name')->get()
            ->mapWithKeys(fn (ChecklistTemplate $template) => ['own-'.$template->id => ['name' => $template->name, 'icon' => $template->icon ?: '📋', 'anchor' => __('Due'), 'default_offset' => 0, 'tasks' => $template->tasks, 'own' => true]])
            ->all();

        return array_map(fn (array $template) => $template + ['own' => false], ChecklistTemplates::builtIn()) + $own;
    }

    public function openTemplates(): void
    {
        $this->templatesOpen = true;
        $this->reset('templateKey', 'templateDate');
    }

    public function closeTemplates(): void
    {
        $this->reset('templatesOpen', 'templateKey', 'templateDate');
    }

    public function chooseTemplate(string $key): void
    {
        $template = $this->checklistTemplates()[$key] ?? abort(404);
        $this->templateKey = $key;
        $this->templateDate = today()->addDays($template['default_offset'])->toDateString();
    }

    public function applyTemplate(): void
    {
        $template = $this->checklistTemplates()[$this->templateKey] ?? abort(404);
        $this->validate(['templateDate' => ['required', 'date']]);

        $count = ChecklistTemplates::apply($this->currentUser(), (int) $this->householdId, $template, Carbon::parse($this->templateDate));

        $this->closeTemplates();
        $this->list = TaskLists::ALL;
        $this->dispatch('toast', message: trans_choice(':count task from “:name” added|:count tasks from “:name” added', $count, ['name' => $template['name']]));
    }

    public function deleteTemplate(int $templateId): void
    {
        ChecklistTemplate::where('household_id', $this->householdId)->findOrFail($templateId)->delete();
        $this->reset('templateKey');
    }

    public function saveAsTemplate(int $taskId): void
    {
        $task = $this->findTask($taskId);
        abort_if($task->subtasks() === [], 422);

        ChecklistTemplates::saveFromTask($task, $this->currentUser());
        $this->dispatch('toast', message: __('Saved as checklist template'));
    }

    /**
     * "⤢": creates the task and opens it right away to fill in the details.
     */
    public function addTaskWithDetails(string $text): bool
    {
        $before = (int) Item::where('household_id', $this->householdId)->max('id');

        if (! $this->addTask($text)) {
            return false;
        }

        $task = Item::where('household_id', $this->householdId)->where('id', '>', $before)->where('created_by', Auth::id())->latest('id')->first();

        if ($task) {
            $this->openTask($task->id);
        }

        return true;
    }

    /**
     * Returns whether a task was created (the input is cleared then).
     */
    public function addTask(string $text): bool
    {
        // Added in "Today"/"Upcoming" without a date: it should show up where it was added.
        $defaultDue = match ($this->list) {
            TaskLists::TODAY => $this->resolveDate('today'),
            TaskLists::UPCOMING => $this->resolveDate('tomorrow'),
            default => null,
        };

        $task = QuickCapture::task($this->currentUser(), (int) $this->householdId, $text, $defaultDue, $this->ignoredPhrases, $this->tag !== '' ? [$this->tag] : []);

        if (! $task) {
            $this->addError('newTask', __('Please enter a title.'));

            return false;
        }

        $this->reset('draft', 'ignoredPhrases');
        $this->resetErrorBag('newTask');
        $this->dispatch('toast', message: __('Task added'));

        return true;
    }

    // ── One task ─────────────────────────────────────────────────────────

    public function toggleDone(int $taskId): void
    {
        $task = $this->findTask($taskId);
        $task->update(['done' => ! $task->done]);

        if ($task->rescheduledTo) {
            $this->announceReschedule($task);
        } elseif ($task->done) {
            $this->dispatch('toast', message: __('Done: :title', ['title' => Str::limit($task->title, 40)]), action: ['label' => __('Undo'), 'event' => 'task-undo', 'params' => ['taskId' => $task->id]]);
        }
    }

    #[On('task-undo')]
    public function undoDone(int $taskId): void
    {
        $this->findTask($taskId)->update(['done' => false]);
    }

    public function openTask(int $taskId): void
    {
        $this->openTaskId = (string) $this->findTask($taskId)->id;
        $this->tagSuggestions = [];
    }

    public function closeTask(): void
    {
        $this->reset('openTaskId', 'tagSuggestions');
    }

    public function updateTitle(int $taskId, string $title): void
    {
        $title = trim($title);

        if ($title !== '') {
            $this->findTask($taskId)->update(['title' => mb_substr($title, 0, 255)]);
        }
    }

    public function setDue(int $taskId, string $preset): void
    {
        $task = $this->findTask($taskId);
        $date = $this->resolveDate($preset);
        $this->setValue($task, 'due', $date);

        if ($date === null) {
            $task->due_time = null;
        }

        $task->save();
    }

    /**
     * Time of day ("HH:MM", "" removes it); a reminder goes out then. Without a date the
     * task becomes due today, or tomorrow if that time has passed.
     */
    public function setTime(int $taskId, string $time): void
    {
        abort_unless($time === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time), 422);

        $task = $this->findTask($taskId);
        $task->due_time = $time !== '' ? $time : null;

        if ($time !== '' && ! $task->dueDate()) {
            $this->setValue($task, 'due', $this->resolveDate(now()->format('H:i') < $time ? 'today' : 'tomorrow'));
        }

        $task->save();
    }

    /**
     * Swipe left: move to tomorrow.
     */
    public function postpone(int $taskId): void
    {
        $this->setDue($taskId, 'tomorrow');
        $this->dispatch('toast', message: __('Moved to tomorrow'));
    }

    /**
     * Drag & drop inside the task list: dropping into another date group sets that date,
     * the position inside the group becomes the manual order.
     */
    public function moveTask(int $taskId, string $groupKey, int $position): void
    {
        $task = $this->findTask($taskId);
        $lists = new TaskLists(Collection::tasksFor((int) $this->householdId)->load('properties'), $this->currentUser());
        $current = $lists->due($task);
        $today = now()->startOfDay();

        $date = match (true) {
            $groupKey === 'none' => '',
            $groupKey === 'today' => $today->toDateString(),
            $groupKey === 'tomorrow' => $today->copy()->addDay()->toDateString(),
            $groupKey === 'overdue' => $current !== '' && $current < $today->toDateString() ? $current : $today->copy()->subDay()->toDateString(),
            $groupKey === 'week' => $current > $today->copy()->addDay()->toDateString() && $current <= $today->copy()->addDays(7)->toDateString() ? $current : $today->copy()->addDays(2)->toDateString(),
            $groupKey === 'later' => $current > $today->copy()->addDays(7)->toDateString() ? $current : $today->copy()->addDays(8)->toDateString(),
            (bool) preg_match('/^day-(\d{4}-\d{2}-\d{2})$/', $groupKey, $day) => $day[1],
            (bool) preg_match('/^month-(\d{4}-\d{2})$/', $groupKey, $month) => str_starts_with($current, $month[1]) ? $current : $month[1].'-01',
            default => abort(404),
        };

        if ($date !== $current) {
            $this->setValue($task, 'due', $date !== '' ? $date : null);

            if ($date === '') {
                $task->due_time = null;
            }

            $task->save();
        }

        // Manual order among the tasks of the same group.
        $siblings = collect($lists->groups($this->list))->firstWhere('key', $groupKey)['tasks'] ?? [];
        $ordered = collect($siblings)->reject(fn (Item $item) => $item->id === $task->id)->values();
        $ordered->splice(max(0, min($position, $ordered->count())), 0, [$task]);

        foreach ($ordered as $index => $item) {
            if ($item->position !== $index + 1) {
                $item->forceFill(['position' => $index + 1])->saveQuietly();
            }
        }
    }

    /**
     * Drag & drop onto a list in the sidebar: "Today" / "Upcoming" set the date, "Mine"
     * assigns the task to me, "Done" completes it.
     */
    public function dropOnList(int $taskId, string $list): void
    {
        match ($list) {
            TaskLists::TODAY => $this->setDue($taskId, 'today'),
            TaskLists::UPCOMING => $this->setDue($taskId, 'tomorrow'),
            TaskLists::MINE => $this->setAssignee($taskId, (string) $this->currentUser()->id),
            TaskLists::DONE => $this->toggleDone($taskId),
            default => null,
        };
    }

    public function setPriority(int $taskId, string $priority): void
    {
        $task = $this->findTask($taskId);
        $this->setValue($task, 'priority', $priority !== '' ? $priority : null);
        $task->save();
    }

    public function setRepeat(int $taskId, string $rule): void
    {
        $task = $this->findTask($taskId);
        $this->setValue($task, 'repeat', $rule !== '' ? $rule : null);
        $task->save();
    }

    public function setAssignee(int $taskId, string $userId): void
    {
        $task = $this->findTask($taskId);
        $this->setValue($task, 'assignee', $userId !== '' ? (int) $userId : null);
        $task->save();
    }

    /**
     * Adds or removes a member from the task's rotation (cleaning plan). The first member in the
     * rotation gets the task assigned right away if nobody has it yet.
     */
    public function toggleRotation(int $taskId, int $userId): void
    {
        $task = $this->findTask($taskId);
        abort_unless(array_key_exists($userId, QuickCapture::members((int) $this->householdId)), 404);

        $rotation = $task->rotation();
        $rotation = in_array($userId, $rotation, true) ? array_values(array_diff($rotation, [$userId])) : [...$rotation, $userId];
        $task->setRotation($rotation);
        $assignee = $this->collection()->systemProperty('assignee');

        if ($assignee && count($task->rotation()) > 1 && ! in_array((int) $task->propertyValue($assignee), $task->rotation(), true)) {
            $task->setPropertyValue($assignee, $task->rotation()[0]);
        }

        $task->save();
    }

    public function updateTags(int $taskId, string $tags): void
    {
        $this->findTask($taskId)->syncTagNames(explode(',', $tags));
    }

    public function suggestTags(int $taskId): void
    {
        $task = $this->findTask($taskId);
        $this->tagSuggestions = $this->tagSuggestionsFor($task->title, $task->body(), $task->tags->pluck('name')->all());
    }

    public function addSuggestedTag(int $taskId, string $name): void
    {
        $task = $this->findTask($taskId);
        $task->syncTagNames([...$task->tags->pluck('name')->all(), $name]);
        $this->tagSuggestions = array_values(array_diff($this->tagSuggestions, [$name]));
    }

    public function saveNotes(int $taskId, string $notes): void
    {
        $task = $this->findTask($taskId);
        $task->update(['content' => ['body' => mb_substr($notes, 0, 100000)] + ($task->content ?? [])]);
    }

    public function addSubtask(int $taskId, string $text): void
    {
        $text = trim($text);
        $task = $this->findTask($taskId);
        $subtasks = $task->subtasks();

        if ($text === '' || count($subtasks) >= self::MAX_SUBTASKS) {
            return;
        }

        $subtasks[] = ['id' => Str::random(8), 'text' => mb_substr($text, 0, 255), 'done' => false];
        $task->setSubtasks($subtasks);
        $task->save();
    }

    public function toggleSubtask(int $taskId, string $subtaskId): void
    {
        $task = $this->findTask($taskId);
        $task->setSubtasks(array_map(fn (array $subtask) => $subtask['id'] === $subtaskId ? array_merge($subtask, ['done' => ! ($subtask['done'] ?? false)]) : $subtask, $task->subtasks()));
        $task->save();
    }

    public function removeSubtask(int $taskId, string $subtaskId): void
    {
        $task = $this->findTask($taskId);
        $task->setSubtasks(array_filter($task->subtasks(), fn (array $subtask) => $subtask['id'] !== $subtaskId));
        $task->save();
    }

    public function deleteTask(int $taskId): void
    {
        $this->findTask($taskId)->delete();

        if ((int) $this->openTaskId === $taskId) {
            $this->closeTask();
        }
    }

    // ── Selection (several tasks at once) ────────────────────────────────

    public function toggleSelecting(): void
    {
        $this->selecting = ! $this->selecting;
        $this->selected = [];
    }

    /**
     * @param  array<int, int>  $taskIds
     */
    public function selectTasks(array $taskIds): void
    {
        $this->selected = array_values(array_unique(array_map('intval', $taskIds)));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Item>
     */
    private function selectedTasks()
    {
        return Item::query()
            ->forHousehold((int) $this->householdId)
            ->visibleTo($this->currentUser())
            ->where('collection_id', $this->collection()->id)
            ->whereIn('id', array_map('intval', $this->selected))
            ->get();
    }

    /**
     * "done", "open", "delete", "due:<preset>", "priority:<id>", "assignee:<user id>".
     */
    public function applyToSelected(string $action): void
    {
        [$verb, $argument] = array_pad(explode(':', $action, 2), 2, '');
        $tasks = $this->selectedTasks();

        foreach ($tasks as $task) {
            match ($verb) {
                'done' => $task->update(['done' => true]),
                'open' => $task->update(['done' => false]),
                'delete' => $task->delete(),
                'due', 'priority', 'assignee' => (function () use ($task, $verb, $argument) {
                    $value = match ($verb) {
                        'due' => $this->resolveDate($argument),
                        'assignee' => $argument !== '' ? (int) $argument : null,
                        default => $argument !== '' ? $argument : null,
                    };
                    $this->setValue($task, $verb, $value);
                    $task->save();
                })(),
                default => abort(422),
            };
        }

        $this->dispatch('toast', message: trans_choice(':count task updated|:count tasks updated', $tasks->count()));
        $this->selected = [];
        $this->selecting = false;
    }

    public function with(): array
    {
        $collection = $this->collection();
        $lists = new TaskLists($collection, $this->currentUser());
        $members = $this->members();
        $openTask = $this->openTaskId !== '' && ctype_digit($this->openTaskId)
            ? Item::query()->forHousehold((int) $this->householdId)->visibleTo($this->currentUser())
                ->where('collection_id', $collection->id)->with('tags')->find((int) $this->openTaskId)
            : null;

        return [
            'checklistTemplates' => $this->templatesOpen ? $this->checklistTemplates() : [],
            'collection' => $collection,
            'lists' => $lists,
            'counts' => $lists->counts(),
            'groups' => $lists->groups($this->list, $this->tag, $this->search),
            'review' => $this->list === TaskLists::DONE ? $lists->review() : null,
            'members' => $members,
            'preview' => trim($this->draft) !== '' ? TaskInput::parse($this->draft, $members, Auth::id(), $this->ignoredPhrases) : null,
            'openTask' => $openTask,
            'tags' => Tag::usedOnItemsVisibleTo($this->currentUser(), $this->householdId, Item::TYPE_TODO)->orderBy('name')->pluck('name'),
            'repeatRules' => [Recurrence::DAILY, Recurrence::WEEKLY, Recurrence::MONTHLY, Recurrence::YEARLY],
            'priorities' => [Collection::TASK_PRIORITY_HIGH, Collection::TASK_PRIORITY_MEDIUM, Collection::TASK_PRIORITY_LOW],
            'aiAvailable' => BrainAi::isConfigured(),
        ];
    }
}; ?>

@php
    $priorityRing = [
        'high' => 'border-red-500 dark:border-red-400',
        'medium' => 'border-orange-400',
        'low' => 'border-blue-400',
    ];
    $priorityText = [
        'high' => 'text-red-600 dark:text-red-400',
        'medium' => 'text-orange-500',
        'low' => 'text-blue-500',
    ];
    $toneClass = [
        'danger' => 'text-red-600 dark:text-red-400',
        'accent' => 'text-indigo-600 dark:text-indigo-400',
        'normal' => 'text-gray-700 dark:text-gray-300',
        'muted' => 'text-gray-500 dark:text-gray-400',
    ];
    $initials = fn (string $name) => mb_strtoupper(collect(preg_split('/\s+/u', trim($name)))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode(''));
@endphp

<div
    x-data
    x-on:keydown.window="if ($event.key === 'n' && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes($event.target.tagName) && ! $event.target.isContentEditable && ! $event.metaKey && ! $event.ctrlKey) { $event.preventDefault(); document.getElementById('new-task').focus() }"
>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">✅ {{ __('Tasks') }}</h2>
            <div class="inline-flex overflow-hidden rounded-md border border-gray-300 text-sm dark:border-gray-600" role="group" aria-label="{{ __('View') }}">
                <span class="bg-indigo-600 px-3 py-1.5 text-white" aria-current="page">✅ {{ __('Task list') }}</span>
                <a href="{{ route('collections.show', ['collection' => $collection, 'view' => 'board']) }}" wire:navigate class="bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">▥ {{ __('Board') }}</a>
                <a href="{{ route('collections.show', ['collection' => $collection, 'view' => 'table']) }}" wire:navigate class="bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">▤ {{ __('Table') }}</a>
                <a href="{{ route('collections.show', ['collection' => $collection, 'view' => 'calendar']) }}" wire:navigate class="bg-white px-3 py-1.5 text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">📅 {{ __('Calendar') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-4 sm:py-6">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 lg:flex lg:gap-6">
            {{-- Smart lists --}}
            <nav class="lg:w-52 shrink-0 mb-4 lg:mb-0" aria-label="{{ __('Lists') }}">
                <div class="flex lg:flex-col gap-1.5 overflow-x-auto pb-1 -mx-4 px-4 lg:mx-0 lg:px-0">
                    @foreach (\App\Support\TaskLists::LISTS as $key)
                        <div class="shrink-0 rounded-lg" x-sort="$wire.dropOnList($item, '{{ $key }}')" x-sort:group="tasks" x-sort:config="{ sort: false, ghostClass: 'hidden' }">
                        <button
                            type="button"
                            wire:click="showList('{{ $key }}')"
                            aria-current="{{ $list === $key ? 'page' : 'false' }}"
                            @class([
                                'shrink-0 flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-left',
                                'bg-indigo-600 text-white shadow-sm' => $list === $key,
                                'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700 lg:ring-0 lg:bg-transparent lg:dark:bg-transparent hover:bg-gray-100 dark:hover:bg-gray-700' => $list !== $key,
                            ])
                        >
                            <span aria-hidden="true">{{ \App\Support\TaskLists::icon($key) }}</span>
                            <span class="lg:flex-1">{{ \App\Support\TaskLists::label($key) }}</span>
                            @if (($counts[$key] ?? 0) > 0)
                                <span @class(['text-xs tabular-nums', 'text-indigo-100' => $list === $key, 'text-gray-400' => $list !== $key])>{{ $counts[$key] }}</span>
                            @endif
                        </button>
                        </div>
                    @endforeach
                </div>

                @if ($tags->isNotEmpty())
                    <div class="mt-3 flex lg:flex-wrap gap-1.5 overflow-x-auto -mx-4 px-4 lg:mx-0 lg:px-0">
                        @foreach ($tags as $tagName)
                            <button type="button" wire:key="task-tag-{{ $tagName }}" wire:click="filterByTag(@js($tagName))" @class([
                                'shrink-0 rounded-full px-2.5 py-0.5 text-xs',
                                'bg-indigo-600 text-white' => $tag === $tagName,
                                'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200' => $tag !== $tagName,
                            ])>#{{ $tagName }}</button>
                        @endforeach
                    </div>
                @endif
            </nav>

            <div class="min-w-0 flex-1 space-y-4">
                {{-- Quick entry --}}
                @if ($list !== \App\Support\TaskLists::DONE)
                    <form
                        x-data
                        x-on:submit.prevent="
                            const input = $refs.input;
                            if (! navigator.onLine) {
                                if (input.value.trim()) {
                                    window.E11Offline.add('task', input.value.trim());
                                    input.value = '';
                                    $dispatch('toast', { message: @js(__('Saved offline – it will be sent once you are online.')) });
                                }
                                return;
                            }
                            $wire.addTask(input.value).then((added) => { if (added) { input.value = ''; input.focus() } })
                        "
                        class="rounded-xl bg-white dark:bg-gray-800 p-3 shadow-sm"
                    >
                        <div class="flex gap-2">
                            <label for="new-task" class="sr-only">{{ __('New task') }}</label>
                            <input
                                id="new-task"
                                type="text"
                                x-ref="input"
                                x-on:input.debounce.250ms="$wire.previewTask($event.target.value)"
                                autocomplete="off"
                                enterkeyhint="done"
                                placeholder="{{ __('New task … e.g. “Call dentist tomorrow !high #health”') }}"
                                class="min-w-0 flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 focus:border-indigo-500 focus:ring-indigo-500"
                            />
                            <x-voice-button for="new-task" submit />
                            <button type="button" x-on:click="const input = $refs.input; if (! input.value.trim()) { input.focus(); return } $wire.addTaskWithDetails(input.value).then((added) => { if (added) { input.value = '' } })" class="shrink-0 rounded-lg px-3 text-sm text-gray-600 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700" title="{{ __('Add and fill in details') }}" aria-label="{{ __('Add and fill in details') }}">⤢</button>
                            <button type="button" wire:click="openTemplates" class="shrink-0 rounded-lg px-3 text-sm text-gray-600 ring-1 ring-gray-300 hover:bg-gray-50 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700" title="{{ __('From a checklist template') }}" aria-label="{{ __('From a checklist template') }}">📋</button>
                            <button type="submit" class="shrink-0 rounded-lg bg-indigo-600 px-4 text-white text-lg leading-none hover:bg-indigo-700" aria-label="{{ __('Add task') }}">＋</button>
                        </div>
                        <x-input-error :messages="$errors->get('newTask')" class="mt-1" />
                        @if ($preview && $preview['tokens'] !== [])
                            <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs" aria-live="polite">
                                <span class="text-gray-500 dark:text-gray-400">„{{ $preview['title'] }}“</span>
                                @foreach ($preview['tokens'] as $token)
                                    <span wire:key="token-{{ $loop->index }}-{{ $token['text'] }}" class="inline-flex items-center gap-1 rounded-full bg-indigo-50 dark:bg-indigo-900/40 pl-2 pr-1 py-0.5 text-indigo-700 dark:text-indigo-300">
                                        {{ ['due' => '📅', 'time' => '⏰', 'repeat' => '🔁', 'priority' => '⚑', 'assignee' => '', 'tag' => ''][$token['kind']] ?? '' }} {{ $token['label'] }}
                                        @if ($token['kind'] !== 'tag')
                                            <button type="button" wire:click="ignorePhrase(@js($token['text']))" class="rounded-full px-1 hover:bg-indigo-100 dark:hover:bg-indigo-800" title="{{ __('Keep “:text” in the title', ['text' => $token['text']]) }}" aria-label="{{ __('Keep “:text” in the title', ['text' => $token['text']]) }}">×</button>
                                        @endif
                                    </span>
                                @endforeach
                            </div>
                        @else
                            <p class="mt-1.5 text-xs text-gray-400">{{ __('Understands dates and times (tomorrow 6 pm, Friday, 24.12., in 3 days), “every Monday”, !high, @name and #tags. Press N to start typing.') }}</p>
                        @endif
                    </form>
                @endif

                {{-- Weekly review --}}
                @if ($review)
                    <div class="grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-xl bg-white dark:bg-gray-800 p-3 shadow-sm">
                            <p class="text-2xl font-semibold text-green-600 dark:text-green-400">{{ $review['thisWeek'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Done this week') }}</p>
                        </div>
                        <div class="rounded-xl bg-white dark:bg-gray-800 p-3 shadow-sm">
                            <p class="text-2xl font-semibold text-gray-700 dark:text-gray-200">{{ $review['lastWeek'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Last week') }}</p>
                        </div>
                        <button type="button" wire:click="showList('{{ \App\Support\TaskLists::TODAY }}')" class="rounded-xl bg-white dark:bg-gray-800 p-3 shadow-sm hover:ring-1 hover:ring-red-300">
                            <p @class(['text-2xl font-semibold', 'text-red-600 dark:text-red-400' => $review['overdue'] > 0, 'text-gray-700 dark:text-gray-200' => $review['overdue'] === 0])>{{ $review['overdue'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Still overdue') }}</p>
                        </button>
                    </div>
                @endif

                {{-- Toolbar --}}
                <div class="flex flex-wrap items-center gap-2">
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search tasks …') }}" aria-label="{{ __('Search tasks') }}" class="min-w-0 flex-1 rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 py-1.5 text-sm" />
                    <button type="button" wire:click="toggleSelecting" @class(['rounded-lg px-3 py-1.5 text-sm ring-1', 'bg-indigo-600 text-white ring-indigo-600' => $selecting, 'ring-gray-300 dark:ring-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700' => ! $selecting])>
                        {{ $selecting ? __('Cancel') : __('Select tasks') }}
                    </button>
                </div>

                {{-- Groups --}}
                @forelse ($groups as $group)
                    <section wire:key="group-{{ $group['key'] }}" class="space-y-1.5">
                        <div class="flex items-center justify-between px-1">
                            <h3 class="text-sm font-semibold {{ $toneClass[$group['tone']] }}">{{ $group['label'] }} <span class="font-normal text-gray-400">{{ count($group['tasks']) }}</span></h3>
                            @if ($selecting)
                                <button type="button" wire:click="selectTasks(@js(array_values(array_unique([...$selected, ...array_map(fn ($task) => $task->id, $group['tasks'])]))))" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('Select all') }}</button>
                            @endif
                        </div>

                        <ul class="min-h-[2.5rem] space-y-1.5"
                            @if ($list !== \App\Support\TaskLists::DONE && ! $selecting)
                                x-sort="$wire.moveTask($item, @js($group['key']), $position)"
                                x-sort:group="tasks"
                                x-sort:config="{ handle: '.task-handle', animation: 150, ghostClass: 'opacity-40' }"
                            @endif
                        >
                            @foreach ($group['tasks'] as $task)
                                @php
                                    $due = $lists->due($task);
                                    $priority = $lists->priority($task);
                                    $subtasks = $task->subtasks();
                                    $subtasksDone = count(array_filter($subtasks, fn ($subtask) => $subtask['done'] ?? false));
                                    $assigneeId = $lists->assigneeId($task);
                                    $isSelected = in_array($task->id, $selected, true);
                                @endphp
                                <li
                                    wire:key="task-{{ $task->id }}-{{ $task->updated_at?->timestamp }}"
                                    x-sort:item="{{ $task->id }}"
                                    x-data="{
                                        startX: 0, startY: 0, dx: 0, swiping: false, leaving: false,
                                        start(e) { this.startX = e.touches[0].clientX; this.startY = e.touches[0].clientY; this.swiping = true },
                                        move(e) {
                                            if (! this.swiping) return;
                                            const dx = e.touches[0].clientX - this.startX, dy = e.touches[0].clientY - this.startY;
                                            if (Math.abs(dy) > Math.abs(dx) && Math.abs(this.dx) < 10) { this.swiping = false; this.dx = 0; return }
                                            this.dx = Math.max(-110, Math.min(110, dx));
                                        },
                                        end() {
                                            this.swiping = false;
                                            if (this.dx > 80) { this.complete() }
                                            else if (this.dx < -80) { $wire.postpone({{ $task->id }}) }
                                            this.dx = 0;
                                        },
                                        complete() {
                                            @if (! $task->done && $list !== \App\Support\TaskLists::DONE)
                                                this.leaving = true;
                                                setTimeout(() => $wire.toggleDone({{ $task->id }}), 350);
                                            @else
                                                $wire.toggleDone({{ $task->id }});
                                            @endif
                                        },
                                    }"
                                    class="relative overflow-hidden rounded-xl"
                                >
                                    <div class="absolute inset-0 flex items-center justify-between px-4 text-sm text-white" aria-hidden="true" :class="dx > 0 ? 'bg-green-600' : (dx < 0 ? 'bg-amber-500' : '')">
                                        <span x-show="dx > 0">✓ {{ __('Done') }}</span>
                                        <span x-show="dx < 0" class="ml-auto">{{ __('Tomorrow') }} →</span>
                                    </div>
                                    <div
                                        x-on:touchstart.passive="start($event)"
                                        x-on:touchmove.passive="move($event)"
                                        x-on:touchend="end()"
                                        :style="`transform: translateX(${dx}px)`"
                                        :class="{ 'opacity-40 scale-[0.98]': leaving, 'transition-transform': ! swiping }"
                                        @class([
                                            'relative flex items-start gap-3 bg-white dark:bg-gray-800 px-3 py-2.5 shadow-sm ring-1 duration-300',
                                            'ring-indigo-500' => $isSelected || (int) $openTaskId === $task->id,
                                            'ring-gray-200 dark:ring-gray-700' => ! $isSelected && (int) $openTaskId !== $task->id,
                                        ])
                                    >
                                        @if (! $selecting && $list !== \App\Support\TaskLists::DONE)
                                            <span class="task-handle -ms-1 mt-1 hidden cursor-grab select-none text-gray-300 hover:text-gray-500 sm:inline dark:text-gray-600" title="{{ __('Drag to another day or list') }}" aria-hidden="true">⋮⋮</span>
                                        @endif
                                        @if ($selecting)
                                            <input type="checkbox" wire:model.live="selected" value="{{ $task->id }}" class="mt-1 h-5 w-5 rounded border-gray-300 dark:border-gray-600 text-indigo-600" aria-label="{{ __('Select :title', ['title' => $task->title]) }}" />
                                        @else
                                            <button
                                                type="button"
                                                x-on:click="complete()"
                                                role="checkbox"
                                                aria-checked="{{ $task->done ? 'true' : 'false' }}"
                                                aria-label="{{ $task->done ? __('Mark as open') : __('Mark as done') }}"
                                                @class([
                                                    'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 text-xs transition',
                                                    'bg-green-600 border-green-600 text-white' => $task->done,
                                                    ($priorityRing[$priority] ?? 'border-gray-300 dark:border-gray-500').' hover:bg-green-50 dark:hover:bg-green-900/30' => ! $task->done,
                                                ])
                                                :class="leaving && 'bg-green-600 border-green-600 text-white'"
                                            ><span x-show="leaving || {{ $task->done ? 'true' : 'false' }}">✓</span></button>
                                        @endif

                                        <button type="button" wire:click="{{ $selecting ? 'selectTasks('.json_encode($isSelected ? array_values(array_diff($selected, [$task->id])) : [...$selected, $task->id]).')' : 'openTask('.$task->id.')' }}" class="min-w-0 flex-1 text-left">
                                            <span @class(['block break-words', 'line-through text-gray-400' => $task->done, 'text-gray-900 dark:text-gray-100' => ! $task->done])>{{ $task->title }}</span>
                                            <span class="mt-0.5 flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                                @if ($due !== '')
                                                    <span @class(['font-medium text-red-600 dark:text-red-400' => $lists->isOverdue($task), 'text-indigo-600 dark:text-indigo-400' => ! $lists->isOverdue($task) && $due === now()->toDateString()])>📅 {{ \App\Support\TaskInput::dueLabel($due) }}@if ($task->due_time) · {{ $task->due_time }}@endif</span>
                                                @endif
                                                @if ($lists->repeat($task))
                                                    <span title="{{ \App\Support\TaskInput::repeatLabel($lists->repeat($task)) }}">🔁 {{ \App\Support\TaskInput::repeatLabel($lists->repeat($task)) }}</span>
                                                @endif
                                                @if ($priority)
                                                    <span class="{{ $priorityText[$priority] }}">⚑ {{ \App\Support\TaskInput::priorityLabel($priority) }}</span>
                                                @endif
                                                @if ($subtasks !== [])
                                                    <span>☑ {{ $subtasksDone }}/{{ count($subtasks) }}</span>
                                                @endif
                                                @if (trim($task->body()) !== '')
                                                    <span title="{{ __('Notes') }}">📝</span>
                                                @endif
                                                @foreach ($task->tags as $taskTag)
                                                    <span class="text-indigo-600 dark:text-indigo-400">#{{ $taskTag->name }}</span>
                                                @endforeach
                                                @if ($task->isPrivate())
                                                    <x-private-badge />
                                                @endif
                                            </span>
                                        </button>

                                        @if ($assigneeId && isset($members[$assigneeId]))
                                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900 text-[10px] font-semibold text-indigo-700 dark:text-indigo-200" title="{{ $members[$assigneeId] }}">{{ $initials($members[$assigneeId]) }}</span>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @empty
                    <div class="rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
                        <p class="text-3xl" aria-hidden="true">{{ $list === \App\Support\TaskLists::TODAY ? '🎉' : '🗒️' }}</p>
                        <p class="mt-2 text-gray-600 dark:text-gray-300">
                            @if ($search !== '' || $tag !== '')
                                {{ __('No tasks match the filter.') }}
                            @elseif ($list === \App\Support\TaskLists::TODAY)
                                {{ __('Nothing due today. Enjoy your day!') }}
                            @elseif ($list === \App\Support\TaskLists::DONE)
                                {{ __('No completed tasks yet.') }}
                            @else
                                {{ __('No tasks here yet.') }}
                            @endif
                        </p>
                    </div>
                @endforelse

                <p class="hidden sm:block text-xs text-gray-400">{{ __('Tip: on the phone, swipe a task right to complete it or left to move it to tomorrow.') }}</p>
                <p class="sm:hidden text-xs text-gray-400">{{ __('Swipe right: done · Swipe left: tomorrow') }}</p>
            </div>
        </div>
    </div>

    {{-- Actions for several tasks --}}
    @if ($selecting && $selected !== [])
        <div class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 dark:border-gray-700 bg-white/95 dark:bg-gray-800/95 backdrop-blur p-3 shadow-lg" role="toolbar" aria-label="{{ __('Actions for the selected tasks') }}">
            <div class="max-w-5xl mx-auto flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ trans_choice(':count selected|:count selected', count($selected)) }}</span>
                @if ($list === \App\Support\TaskLists::DONE)
                    <button type="button" wire:click="applyToSelected('open')" class="rounded-lg bg-gray-100 dark:bg-gray-700 px-3 py-1.5 hover:bg-gray-200">↺ {{ __('Reopen') }}</button>
                @else
                    <button type="button" wire:click="applyToSelected('done')" class="rounded-lg bg-green-600 px-3 py-1.5 text-white hover:bg-green-700">✓ {{ __('Done') }}</button>
                @endif
                <select x-data x-on:change="if ($event.target.value) { $wire.applyToSelected($event.target.value); $event.target.value = '' }" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 py-1.5 text-sm" aria-label="{{ __('Due') }}">
                    <option value="">📅 {{ __('Date …') }}</option>
                    <option value="due:today">{{ __('Today') }}</option>
                    <option value="due:tomorrow">{{ __('Tomorrow') }}</option>
                    <option value="due:weekend">{{ __('This weekend') }}</option>
                    <option value="due:next_week">{{ __('Next week') }}</option>
                    <option value="due:none">{{ __('No date') }}</option>
                </select>
                <select x-data x-on:change="if ($event.target.value) { $wire.applyToSelected($event.target.value); $event.target.value = '' }" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 py-1.5 text-sm" aria-label="{{ __('Priority') }}">
                    <option value="">⚑ {{ __('Priority …') }}</option>
                    @foreach ($priorities as $priorityOption)
                        <option value="priority:{{ $priorityOption }}">{{ \App\Support\TaskInput::priorityLabel($priorityOption) }}</option>
                    @endforeach
                    <option value="priority:">{{ __('None') }}</option>
                </select>
                <select x-data x-on:change="if ($event.target.value) { $wire.applyToSelected($event.target.value); $event.target.value = '' }" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 py-1.5 text-sm" aria-label="{{ __('Assignee') }}">
                    <option value="">👤 {{ __('Assign …') }}</option>
                    @foreach ($members as $memberId => $memberName)
                        <option value="assignee:{{ $memberId }}">{{ $memberName }}</option>
                    @endforeach
                    <option value="assignee:">{{ __('Nobody') }}</option>
                </select>
                <button type="button" wire:click="applyToSelected('delete')" wire:confirm="{{ __('Delete the selected tasks?') }}" class="ml-auto rounded-lg px-3 py-1.5 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/30">🗑 {{ __('Delete') }}</button>
            </div>
        </div>
    @endif

    {{-- Detail panel --}}
    @if ($openTask)
        @php
            $due = $lists->due($openTask);
            $priority = $lists->priority($openTask);
            $repeat = $lists->repeat($openTask);
            $assigneeId = $lists->assigneeId($openTask);
            $subtasks = $openTask->subtasks();
        @endphp
        <x-sheet close="closeTask" :label="$openTask->title" wire:key="task-panel-{{ $openTask->id }}">
            <x-slot:header>
                <span aria-hidden="true">✅</span>
                <span class="truncate">{{ __('Tasks') }}</span>
                @if ($openTask->isPrivate())
                    <x-private-badge />
                @endif
            </x-slot:header>

                <div class="space-y-5">
                    <textarea
                        rows="2"
                        wire:key="task-title-{{ $openTask->id }}-{{ md5($openTask->title) }}"
                        x-on:change="$wire.updateTitle({{ $openTask->id }}, $event.target.value)"
                        x-on:keydown.enter.prevent="$event.target.blur()"
                        class="w-full resize-none border-0 bg-transparent p-0 text-lg font-semibold text-gray-900 dark:text-gray-100 focus:ring-0"
                        aria-label="{{ __('Title') }}"
                    >{{ $openTask->title }}</textarea>

                    {{-- Date --}}
                    <div>
                        <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">📅 {{ __('Due') }} @if ($due !== '') · <span class="normal-case {{ $lists->isOverdue($openTask) ? 'text-red-600' : 'text-gray-600 dark:text-gray-300' }}">{{ \Illuminate\Support\Carbon::parse($due)->translatedFormat('l, j. F Y') }}@if ($openTask->due_time), {{ $openTask->due_time }}@endif</span>@endif</h4>
                        @if ($openTask->due_time)
                            <p class="-mt-0.5 mb-1.5 text-xs text-gray-500 dark:text-gray-400">⏰ {{ __('Reminder at :time (push or e-mail)', ['time' => $openTask->due_time]) }}</p>
                        @endif
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (['today' => __('Today'), 'tomorrow' => __('Tomorrow'), 'weekend' => __('This weekend'), 'next_week' => __('Next week'), 'none' => __('No date')] as $preset => $label)
                                <button type="button" wire:click="setDue({{ $openTask->id }}, '{{ $preset }}')" class="rounded-full bg-gray-100 dark:bg-gray-700 px-3 py-1 text-sm text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-gray-600">{{ $label }}</button>
                            @endforeach
                            <input type="date" value="{{ $due }}" wire:change="setDue({{ $openTask->id }}, $event.target.value || 'none')" class="rounded-full border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-3 py-0.5 text-sm" aria-label="{{ __('Pick a date') }}" />
                            <input type="time" value="{{ $openTask->due_time }}" wire:change="setTime({{ $openTask->id }}, $event.target.value)" class="rounded-full border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-3 py-0.5 text-sm" aria-label="{{ __('Time') }}" />
                        </div>
                    </div>

                    {{-- Priority --}}
                    <div>
                        <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">⚑ {{ __('Priority') }}</h4>
                        <div class="inline-flex rounded-lg ring-1 ring-gray-200 dark:ring-gray-600 overflow-hidden text-sm" role="radiogroup" aria-label="{{ __('Priority') }}">
                            @foreach (['' => __('None'), 'low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High')] as $value => $label)
                                <button type="button" role="radio" aria-checked="{{ ($priority ?? '') === $value ? 'true' : 'false' }}" wire:click="setPriority({{ $openTask->id }}, '{{ $value }}')" @class([
                                    'px-3 py-1.5 border-l first:border-l-0 border-gray-200 dark:border-gray-600',
                                    'bg-indigo-600 text-white' => ($priority ?? '') === $value,
                                    'text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700' => ($priority ?? '') !== $value,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400">🔁 {{ __('Repeat') }}</span>
                            <select wire:change="setRepeat({{ $openTask->id }}, $event.target.value)" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('Never') }}</option>
                                @foreach ($repeatRules as $rule)
                                    <option value="{{ $rule }}" @selected($repeat === $rule)>{{ \App\Support\TaskInput::repeatLabel($rule) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm">
                            <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-400">👤 {{ __('Assignee') }}</span>
                            <select wire:change="setAssignee({{ $openTask->id }}, $event.target.value)" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                                <option value="">{{ __('Nobody') }}</option>
                                @foreach ($members as $memberId => $memberName)
                                    <option value="{{ $memberId }}" @selected($assigneeId === $memberId)>{{ $memberName }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    @if ($repeat && count($members) > 1)
                        @php
                            $rotation = $openTask->rotation();
                        @endphp
                        <div>
                            <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">🔄 {{ __('Take turns') }}</h4>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($members as $memberId => $memberName)
                                    <button type="button" wire:click="toggleRotation({{ $openTask->id }}, {{ $memberId }})" aria-pressed="{{ in_array($memberId, $rotation, true) ? 'true' : 'false' }}" @class(['rounded-full px-2.5 py-1 text-xs ring-1', 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-900/40 dark:text-indigo-200 dark:ring-indigo-800' => in_array($memberId, $rotation, true), 'text-gray-500 ring-gray-200 dark:text-gray-400 dark:ring-gray-700' => ! in_array($memberId, $rotation, true)])>
                                        @if (in_array($memberId, $rotation, true))<span class="font-semibold">{{ array_search($memberId, $rotation, true) + 1 }}.</span>@endif {{ $memberName }}
                                    </button>
                                @endforeach
                            </div>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ count($rotation) > 1 ? __('After each completion the next person in this order gets the task.') : __('Choose at least two people who do the task in turns (e.g. cleaning plan).') }}</p>
                        </div>
                    @endif

                    {{-- Subtasks --}}
                    <div>
                        <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">☑ {{ __('Subtasks') }}</h4>
                        <ul class="space-y-1">
                            @foreach ($subtasks as $subtask)
                                <li wire:key="subtask-{{ $subtask['id'] }}" class="group flex items-center gap-2">
                                    <input type="checkbox" wire:click="toggleSubtask({{ $openTask->id }}, '{{ $subtask['id'] }}')" @checked($subtask['done'] ?? false) class="h-4 w-4 rounded border-gray-300 dark:border-gray-600 text-green-600" aria-label="{{ $subtask['text'] }}" />
                                    <span @class(['flex-1 text-sm', 'line-through text-gray-400' => $subtask['done'] ?? false, 'text-gray-800 dark:text-gray-200' => ! ($subtask['done'] ?? false)])>{{ $subtask['text'] }}</span>
                                    <button type="button" wire:click="removeSubtask({{ $openTask->id }}, '{{ $subtask['id'] }}')" class="text-gray-400 hover:text-red-600 sm:opacity-0 sm:group-hover:opacity-100" aria-label="{{ __('Remove') }}">×</button>
                                </li>
                            @endforeach
                        </ul>
                        <form x-data x-on:submit.prevent="const input = $refs.subtask; if (input.value.trim()) { $wire.addSubtask({{ $openTask->id }}, input.value); input.value = '' } input.focus()" class="mt-1.5 flex items-center gap-2">
                            <span class="text-gray-300" aria-hidden="true">＋</span>
                            <input x-ref="subtask" id="new-subtask" type="text" enterkeyhint="done" placeholder="{{ __('Add a subtask') }}" class="flex-1 border-0 border-b border-gray-200 dark:border-gray-700 bg-transparent px-0 py-1 text-sm focus:border-indigo-500 focus:ring-0 dark:text-gray-100" aria-label="{{ __('Add a subtask') }}" />
                            <x-voice-button for="new-subtask" class="py-0.5" />
                        </form>
                        @if ($subtasks !== [])
                            <button type="button" wire:click="saveAsTemplate({{ $openTask->id }})" class="mt-2 text-xs text-indigo-600 hover:underline dark:text-indigo-400">📋 {{ __('Save as checklist template') }}</button>
                        @endif
                    </div>

                    {{-- Notes --}}
                    <label class="block">
                        <span class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-400">📝 {{ __('Notes') }}</span>
                        <textarea
                            rows="4"
                            wire:key="task-notes-{{ $openTask->id }}-{{ md5($openTask->body()) }}"
                            x-on:change="$wire.saveNotes({{ $openTask->id }}, $event.target.value)"
                            placeholder="{{ __('Add notes …') }}"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"
                        >{{ $openTask->body() }}</textarea>
                    </label>

                    {{-- Tags --}}
                    <div>
                        <label for="task-tags" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-400"># {{ __('Tags') }}</label>
                        <div class="flex gap-1.5">
                            <input id="task-tags" type="text" value="{{ $openTask->tags->sortBy('name')->pluck('name')->implode(', ') }}" wire:key="task-tags-{{ $openTask->id }}-{{ md5($openTask->tags->sortBy('name')->pluck('name')->implode(',')) }}" wire:change="updateTags({{ $openTask->id }}, $event.target.value)" placeholder="{{ __('e.g. garden, urgent') }}" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" />
                            @if ($aiAvailable)
                                <button type="button" wire:click="suggestTags({{ $openTask->id }})" wire:loading.attr="disabled" wire:target="suggestTags" class="shrink-0 rounded-lg px-2.5 text-sm ring-1 ring-gray-300 dark:ring-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700" title="{{ __('Suggest tags') }}" aria-label="{{ __('Suggest tags') }}">✨</button>
                            @endif
                        </div>
                        @if ($tagSuggestions !== [])
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @foreach ($tagSuggestions as $suggestion)
                                    <button type="button" wire:key="task-tag-suggestion-{{ $suggestion }}" wire:click="addSuggestedTag({{ $openTask->id }}, @js($suggestion))" class="rounded-full bg-indigo-50 dark:bg-indigo-900/40 px-2.5 py-0.5 text-xs text-indigo-700 dark:text-indigo-300">+ #{{ $suggestion }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <livewire:documents-panel type="item" :id="$openTask->id" :key="'task-documents-'.$openTask->id" />
                    <livewire:links-panel type="item" :id="$openTask->id" :key="'task-links-'.$openTask->id" />

                    <livewire:entries.activity :item-id="$openTask->id" :key="'task-activity-'.$openTask->id" />

                    <a href="{{ route('collections.show', ['collection' => $collection, 'entry' => $openTask->id]) }}" wire:navigate class="inline-block text-sm text-gray-500 dark:text-gray-400 hover:underline">{{ __('Formatting & files') }} →</a>
                </div>

            <x-slot:footer>
                <button type="button" wire:click="toggleDone({{ $openTask->id }})" @class(['rounded-lg px-4 py-2 text-sm font-medium', 'bg-green-600 text-white' => $openTask->done, 'bg-indigo-600 text-white hover:bg-indigo-700' => ! $openTask->done])>
                    {{ $openTask->done ? '✓ '.__('Done') : '✓ '.__('Mark as done') }}
                </button>
                <x-secondary-button type="button" wire:click="closeTask">{{ __('Close') }}</x-secondary-button>
                <button type="button" wire:click="deleteTask({{ $openTask->id }})" wire:confirm="{{ __('Delete this task?') }}" class="ms-auto rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">🗑 {{ __('Delete') }}</button>
            </x-slot:footer>
        </x-sheet>
    @endif
    {{-- Checklist templates: pick one, choose the day, the tasks are created --}}
    @if ($templatesOpen)
        <x-sheet close="closeTemplates" :label="__('Checklist templates')" size="md">
            <x-slot:header>📋 {{ __('Checklist templates') }}</x-slot:header>
            @php
                $chosen = $checklistTemplates[$templateKey] ?? null;
            @endphp
            @if (! $chosen)
                <ul class="space-y-2">
                    @foreach ($checklistTemplates as $key => $template)
                        <li wire:key="checklist-{{ $key }}" class="flex items-center gap-2">
                            <button type="button" wire:click="chooseTemplate('{{ $key }}')" class="flex min-w-0 flex-1 items-center gap-3 rounded-xl px-3 py-2.5 text-start ring-1 ring-gray-200 hover:bg-gray-50 dark:ring-gray-700 dark:hover:bg-gray-700/50">
                                <span class="text-2xl" aria-hidden="true">{{ $template['icon'] }}</span>
                                <span class="min-w-0">
                                    <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $template['name'] }}</span>
                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count task|:count tasks', count($template['tasks'])) }} · {{ trans_choice(':count item|:count items', collect($template['tasks'])->sum(fn ($task) => count($task['subtasks']))) }}</span>
                                </span>
                            </button>
                            @if ($template['own'])
                                <button type="button" wire:click="deleteTemplate({{ (int) substr($key, 4) }})" wire:confirm="{{ __('Delete this template?') }}" class="rounded-lg p-2 text-gray-400 hover:text-red-600" aria-label="{{ __('Delete') }}">🗑</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Your own templates: open a task with sub-items and choose “Save as checklist template”.') }}</p>
            @else
                <form wire:submit="applyTemplate" id="checklist-form" class="space-y-4">
                    <p class="font-medium text-gray-900 dark:text-gray-100">{{ $chosen['icon'] }} {{ $chosen['name'] }}</p>
                    <label class="block text-sm text-gray-700 dark:text-gray-300">{{ $chosen['anchor'] }}
                        <input type="date" wire:model.live="templateDate" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    </label>
                    <x-input-error :messages="$errors->get('templateDate')" />
                    <ul class="space-y-2 text-sm">
                        @foreach ($chosen['tasks'] as $task)
                            <li class="rounded-xl bg-gray-50 p-3 dark:bg-gray-900/50">
                                <span class="font-medium text-gray-900 dark:text-gray-100">{{ $task['title'] }}</span>
                                @if ($templateDate)
                                    <span class="text-xs text-gray-500">· {{ \Illuminate\Support\Carbon::parse($templateDate)->addDays($task['offset'])->translatedFormat('D, j. M Y') }}</span>
                                @endif
                                <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ implode(' · ', $task['subtasks']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </form>
            @endif
            <x-slot:footer>
                @if ($chosen)
                    <x-primary-button type="submit" form="checklist-form">{{ __('Create tasks') }}</x-primary-button>
                    <x-secondary-button type="button" wire:click="$set('templateKey', '')">{{ __('Back') }}</x-secondary-button>
                @else
                    <x-secondary-button type="button" wire:click="closeTemplates">{{ __('Close') }}</x-secondary-button>
                @endif
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
