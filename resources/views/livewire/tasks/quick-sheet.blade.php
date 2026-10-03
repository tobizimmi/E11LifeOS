<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\CollectionProperty;
use App\Models\Item;
use App\Support\QuickCapture;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * A task opened in place from anywhere (overview, calendar, search): the essentials to edit it
 * without leaving the page. Changes save as you go; "All details" leads to the task list.
 * Opened by links with data-open-task="5" (see app.js) or the browser event open-task, e.g. $dispatch('open-task', { id: 5 }).
 * Confirms with task-sheet-shown; without that confirmation the link falls back to the task list.
 */
new class extends Component
{
    use InteractsWithHousehold;

    private const MAX_SUBTASKS = 100;

    public ?int $taskId = null;

    protected function requiresHousehold(): bool
    {
        return false;
    }

    private function collection(): Collection
    {
        return Collection::tasksFor((int) $this->householdId)->load('properties');
    }

    private function task(): Item
    {
        abort_if($this->householdId === null || $this->taskId === null, 404);

        return Item::query()->forHousehold((int) $this->householdId)->visibleTo($this->currentUser())
            ->where('collection_id', $this->collection()->id)->find($this->taskId) ?? abort(404);
    }

    private function setValue(Item $task, string $key, mixed $value): void
    {
        $property = $this->collection()->systemProperty($key) ?? abort(422);
        $task->setPropertyValue($property, $property->normalizeValue($value, array_keys(QuickCapture::members((int) $this->householdId))));
    }

    #[On('open-task')]
    public function open(int $id): void
    {
        $this->taskId = $id;
        $this->task();
        $this->dispatch('task-sheet-shown', id: $id);
    }

    public function close(): void
    {
        $this->taskId = null;
    }

    public function updateTitle(string $title): void
    {
        $title = trim($title);

        if ($title !== '') {
            $this->task()->update(['title' => mb_substr($title, 0, 255)]);
            $this->dispatch('task-updated');
        }
    }

    public function setDue(string $preset): void
    {
        $today = now()->startOfDay();
        $date = match ($preset) {
            'today' => $today->toDateString(),
            'tomorrow' => $today->addDay()->toDateString(),
            'next_week' => $today->next(Carbon::MONDAY)->toDateString(),
            'none', '' => null,
            default => preg_match('/^\d{4}-\d{2}-\d{2}$/', $preset) && strtotime($preset) ? $preset : abort(422),
        };
        $task = $this->task();
        $this->setValue($task, 'due', $date);

        if ($date === null) {
            $task->due_time = null;
        }

        $task->save();
        $this->dispatch('task-updated');
    }

    public function setPriority(string $priority): void
    {
        abort_unless(in_array($priority, ['', 'low', 'medium', 'high'], true), 422);
        $task = $this->task();
        $this->setValue($task, 'priority', $priority !== '' ? $priority : null);
        $task->save();
        $this->dispatch('task-updated');
    }

    /**
     * Called while typing (debounced) – notes save themselves.
     */
    public function saveNotes(string $notes): void
    {
        $task = $this->task();
        $task->update(['content' => ['body' => mb_substr($notes, 0, 100000)] + ($task->content ?? [])]);
    }

    public function addSubtask(string $text): void
    {
        $text = trim($text);
        $task = $this->task();
        $subtasks = $task->subtasks();

        if ($text === '' || count($subtasks) >= self::MAX_SUBTASKS) {
            return;
        }

        $subtasks[] = ['id' => Str::random(8), 'text' => mb_substr($text, 0, 255), 'done' => false];
        $task->setSubtasks($subtasks);
        $task->save();
    }

    public function toggleSubtask(string $subtaskId): void
    {
        $task = $this->task();
        $task->setSubtasks(array_map(fn (array $subtask) => $subtask['id'] === $subtaskId ? array_merge($subtask, ['done' => ! ($subtask['done'] ?? false)]) : $subtask, $task->subtasks()));
        $task->save();
    }

    public function toggleDone(): void
    {
        $task = $this->task();
        $task->update(['done' => ! $task->done]);

        if ($task->done) {
            $this->announceReschedule($task);
            $this->dispatch('toast', message: __('Done: :title', ['title' => Str::limit($task->title, 40)]));
            $this->taskId = null;
        }

        $this->dispatch('task-updated');
    }

    public function with(): array
    {
        if ($this->taskId === null || $this->householdId === null) {
            return ['task' => null];
        }

        $task = $this->task();
        $collection = $this->collection();
        $dueProperty = $collection->systemProperty('due');
        $priorityProperty = $collection->systemProperty('priority');

        return [
            'task' => $task,
            'due' => $dueProperty instanceof CollectionProperty ? (string) $task->propertyValue($dueProperty) : '',
            'priority' => $priorityProperty instanceof CollectionProperty ? (string) $task->propertyValue($priorityProperty) : '',
            'subtasks' => $task->subtasks(),
        ];
    }
}; ?>

<div>
    @if ($task)
        <x-sheet close="close" :label="$task->title" size="md" wire:key="quick-task-{{ $task->id }}">
            <x-slot:header>
                <span aria-hidden="true">✅</span>
                <span class="truncate">{{ __('Task') }}</span>
                <span class="ms-2 text-xs font-normal text-gray-400" x-data="{ saved: false }" x-on:task-autosaved.window="saved = true; setTimeout(() => saved = false, 1500)" x-show="saved" x-transition.opacity>✓ {{ __('Saved') }}</span>
            </x-slot:header>

            <div class="space-y-5">
                <textarea rows="2" wire:key="quick-title-{{ $task->id }}-{{ md5($task->title) }}"
                    x-on:change="$wire.updateTitle($event.target.value)"
                    x-on:keydown.enter.prevent="$event.target.blur()"
                    class="w-full resize-none border-0 bg-transparent p-0 text-lg font-semibold text-gray-900 focus:ring-0 dark:text-gray-100"
                    aria-label="{{ __('Title') }}">{{ $task->title }}</textarea>

                <div>
                    <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">📅 {{ __('Due') }}@if ($due !== '') · <span class="normal-case text-gray-600 dark:text-gray-300">{{ Carbon::parse($due)->translatedFormat('l, j. F') }}@if ($task->due_time), {{ $task->due_time }}@endif</span>@endif</h4>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (['today' => __('Today'), 'tomorrow' => __('Tomorrow'), 'next_week' => __('Next week'), 'none' => __('No date')] as $preset => $label)
                            <button type="button" wire:click="setDue('{{ $preset }}')" class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700 hover:bg-indigo-50 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">{{ $label }}</button>
                        @endforeach
                        <input type="date" value="{{ $due }}" wire:change="setDue($event.target.value || 'none')" class="rounded-full border-gray-300 px-3 py-0.5 text-sm dark:border-gray-600 dark:bg-gray-900" aria-label="{{ __('Pick a date') }}" />
                    </div>
                </div>

                <div>
                    <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">⚑ {{ __('Priority') }}</h4>
                    <div class="inline-flex overflow-hidden rounded-lg text-sm ring-1 ring-gray-200 dark:ring-gray-600" role="radiogroup" aria-label="{{ __('Priority') }}">
                        @foreach (['' => __('None'), 'low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High')] as $value => $label)
                            <button type="button" role="radio" aria-checked="{{ $priority === $value ? 'true' : 'false' }}" wire:click="setPriority('{{ $value }}')" @class(['border-l border-gray-200 px-3 py-1.5 first:border-l-0 dark:border-gray-600', 'bg-indigo-600 text-white' => $priority === $value, 'text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-700' => $priority !== $value])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <h4 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">☑ {{ __('Subtasks') }}</h4>
                    <ul class="space-y-1">
                        @foreach ($subtasks as $subtask)
                            <li wire:key="quick-subtask-{{ $subtask['id'] }}" class="flex items-center gap-2">
                                <input type="checkbox" wire:click="toggleSubtask('{{ $subtask['id'] }}')" @checked($subtask['done'] ?? false) class="h-4 w-4 rounded-sm border-gray-300 text-green-600 dark:border-gray-600" aria-label="{{ $subtask['text'] }}" />
                                <span @class(['flex-1 text-sm', 'text-gray-400 line-through' => $subtask['done'] ?? false, 'text-gray-800 dark:text-gray-200' => ! ($subtask['done'] ?? false)])>{{ $subtask['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <form x-data x-on:submit.prevent="const input = $refs.subtask; if (input.value.trim()) { $wire.addSubtask(input.value); input.value = '' } input.focus()" class="mt-1.5 flex items-center gap-2">
                        <span class="text-gray-300" aria-hidden="true">＋</span>
                        <input x-ref="subtask" type="text" enterkeyhint="done" placeholder="{{ __('Add a subtask') }}" class="flex-1 border-0 border-b border-gray-200 bg-transparent px-0 py-1 text-sm focus:border-indigo-500 focus:ring-0 dark:border-gray-700 dark:text-gray-100" aria-label="{{ __('Add a subtask') }}" />
                    </form>
                </div>

                <label class="block">
                    <span class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-400">📝 {{ __('Notes') }}</span>
                    <textarea rows="4" wire:key="quick-notes-{{ $task->id }}"
                        x-on:input.debounce.700ms="$wire.saveNotes($event.target.value).then(() => $dispatch('task-autosaved'))"
                        placeholder="{{ __('Add notes … (saves automatically)') }}"
                        class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ $task->body() }}</textarea>
                </label>

                <a href="{{ route('tasks.index', ['task' => $task->id]) }}" wire:navigate class="inline-block text-sm text-indigo-600 hover:underline dark:text-indigo-400">{{ __('All details') }} →</a>
            </div>

            <x-slot:footer>
                <button type="button" wire:click="toggleDone" @class(['rounded-lg px-4 py-2 text-sm font-medium', 'bg-green-600 text-white' => $task->done, 'bg-indigo-600 text-white hover:bg-indigo-700' => ! $task->done])>
                    {{ $task->done ? '✓ '.__('Done') : '✓ '.__('Mark as done') }}
                </button>
                <x-secondary-button type="button" wire:click="close">{{ __('Close') }}</x-secondary-button>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
