<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\Item;
use App\Models\Space;
use App\Support\PageTree;
use App\Support\QuickCapture;
use App\Support\TaskLists;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * One space: its pages (as a tree) and its tasks, with quick add for both.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Locked]
    public int $spaceId;

    public string $newTask = '';

    public bool $showDone = false;

    public function mount(Space $space): void
    {
        $this->resolveHousehold();
        $this->spaceId = $this->findSpace($space->id)->id;
    }

    private function findSpace(?int $id = null): Space
    {
        return Space::query()->visibleTo(Auth::user(), (int) $this->householdId)->find($id ?? $this->spaceId) ?? abort(404);
    }

    private function tasksCollection(): Collection
    {
        return Collection::tasksFor((int) $this->householdId);
    }

    public function addTask(): void
    {
        $this->validate(['newTask' => ['required', 'string', 'max:500']]);
        $space = $this->findSpace();
        $task = QuickCapture::task(Auth::user(), (int) $this->householdId, $this->newTask);
        $task?->forceFill(['space_id' => $space->id])->save();
        $this->reset('newTask');
    }

    public function toggleTask(int $taskId): void
    {
        $task = Item::query()->forHousehold((int) $this->householdId)->visibleTo(Auth::user())
            ->where('collection_id', $this->tasksCollection()->id)->where('space_id', $this->spaceId)->find($taskId) ?? abort(404);
        $task->update(['done' => ! $task->done]);
    }

    public function with(): array
    {
        $space = $this->findSpace();
        $collection = $this->tasksCollection();
        $visible = fn () => Item::query()->forHousehold((int) $this->householdId)->visibleTo(Auth::user())->where('space_id', $space->id);
        $pages = $visible()->where('type', Item::TYPE_NOTE)->get(['id', 'parent_id', 'title', 'icon', 'visibility', 'created_by', 'position', 'updated_at']);
        // Subpages whose parent is outside the space show up at the top level.
        $pageIds = $pages->pluck('id')->all();
        $pages->each(fn (Item $page) => in_array($page->parent_id, $pageIds, true) ? null : $page->parent_id = null);
        $tree = new PageTree($pages);
        $tasks = $visible()->where('collection_id', $collection->id)->when(! $this->showDone, fn ($query) => $query->where('done', false))
            ->orderBy('done')->orderBy('position')->orderBy('id')->limit(200)->get();

        return [
            'space' => $space,
            'pageRows' => $tree->flatten(),
            'tasks' => $tasks,
            'lists' => new TaskLists($collection->load('properties'), Auth::user()),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">{{ $space->icon }} {{ $space->name }}</h2>
            <a href="{{ route('spaces.index') }}" wire:navigate class="text-sm text-gray-500 underline dark:text-gray-400">{{ __('All spaces') }}</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto grid max-w-5xl gap-5 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="space-pages-heading">
                <div class="flex items-center justify-between gap-3">
                    <h3 id="space-pages-heading" class="font-semibold text-gray-900 dark:text-gray-100">📄 {{ __('Pages') }}</h3>
                    <a href="{{ route('pages.index', ['create' => 1, 'space' => $space->id]) }}" wire:navigate class="text-sm text-indigo-600 underline dark:text-indigo-400">+ {{ __('New page') }}</a>
                </div>
                @if ($pageRows !== [])
                    <ul class="mt-3 space-y-0.5">
                        @foreach ($pageRows as $row)
                            <li wire:key="space-page-{{ $row['page']->id }}">
                                <a href="{{ route('pages.index', ['item' => $row['page']->id]) }}" wire:navigate class="flex items-center gap-2 rounded-md py-1.5 pe-2 text-gray-900 hover:bg-gray-50 dark:text-gray-100 dark:hover:bg-gray-700/50" style="padding-inline-start: {{ 0.5 + $row['depth'] * 1.25 }}rem">
                                    <span aria-hidden="true">{{ $row['page']->displayIcon() }}</span>
                                    <span class="min-w-0 flex-1 truncate">{{ $row['page']->title }}</span>
                                    @if ($row['page']->isPrivate())
                                        <x-private-badge />
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No pages in this space yet. Choose the space when you create or edit a page.') }}</p>
                @endif
            </section>

            <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-800" aria-labelledby="space-tasks-heading">
                <div class="flex items-center justify-between gap-3">
                    <h3 id="space-tasks-heading" class="font-semibold text-gray-900 dark:text-gray-100">✅ {{ __('Tasks') }}</h3>
                    <span class="flex items-center gap-3 text-sm">
                        <label class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model.live="showDone" class="rounded border-gray-300 text-indigo-600"> {{ __('Show done') }}</label>
                        <a href="{{ route('tasks.index', ['space' => $space->id]) }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-400">{{ __('In task list') }}</a>
                    </span>
                </div>
                <form wire:submit="addTask" class="mt-3 flex gap-2">
                    <x-text-input wire:model="newTask" class="block w-full text-sm" type="text" placeholder="{{ __('New task, e.g. Book the room on Friday') }}" aria-label="{{ __('New task') }}" />
                    <x-secondary-button type="submit" class="shrink-0">+</x-secondary-button>
                </form>
                <x-input-error :messages="$errors->get('newTask')" class="mt-1" />
                @if ($tasks->isNotEmpty())
                    <ul class="mt-3 space-y-0.5">
                        @foreach ($tasks as $task)
                            @php($due = $lists->due($task))
                            <li wire:key="space-task-{{ $task->id }}-{{ $task->done ? 1 : 0 }}" class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <input type="checkbox" wire:click="toggleTask({{ $task->id }})" @checked($task->done) class="rounded border-gray-300 text-indigo-600" aria-label="{{ __('Done') }}: {{ $task->title }}">
                                <a href="{{ route('tasks.index', ['task' => $task->id]) }}" wire:navigate @class(['min-w-0 flex-1 truncate text-sm hover:underline', 'text-gray-900 dark:text-gray-100' => ! $task->done, 'text-gray-400 line-through' => $task->done])>{{ $task->title }}</a>
                                @if ($due !== '')
                                    <span @class(['shrink-0 text-xs', 'text-red-600 dark:text-red-400' => $lists->isOverdue($task), 'text-gray-500 dark:text-gray-400' => ! $lists->isOverdue($task)])>{{ \App\Support\TaskInput::dueLabel($due) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('No open tasks in this space.') }}</p>
                @endif
            </section>
        </div>
    </div>
</div>
