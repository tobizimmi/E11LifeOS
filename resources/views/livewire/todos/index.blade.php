<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public string $title = '';

    public bool $isPrivate = false;

    public function add(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $maxPosition = (int) $this->visibleItems(Item::TYPE_TODO)->max('position');

        Item::create([
            'household_id' => $this->householdId,
            'type' => Item::TYPE_TODO,
            'visibility' => $this->isPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => $validated['title'],
            'created_by' => Auth::id(),
            'position' => $maxPosition + 1,
        ]);

        $this->reset('title');
    }

    public function toggle(int $id): void
    {
        $todo = $this->findVisibleItem(Item::TYPE_TODO, $id);

        $todo->update(['done' => ! $todo->done]);
    }

    public function toggleVisibility(int $id): void
    {
        $todo = $this->findVisibleItem(Item::TYPE_TODO, $id);

        abort_unless($todo->visibilityCanBeChangedBy(Auth::user()), 403);

        $todo->update([
            'visibility' => $todo->isPrivate() ? Item::VISIBILITY_SHARED : Item::VISIBILITY_PRIVATE,
        ]);
    }

    public function delete(int $id): void
    {
        $this->findVisibleItem(Item::TYPE_TODO, $id)->delete();
    }

    public function updateOrder(array $orderedIds): void
    {
        $ownedIds = $this->visibleItems(Item::TYPE_TODO)
            ->whereIn('id', $orderedIds)
            ->pluck('id');

        foreach (array_values($orderedIds) as $position => $id) {
            if ($ownedIds->contains($id)) {
                Item::whereKey($id)->update(['position' => $position]);
            }
        }
    }

    public function with(): array
    {
        return [
            'todos' => $this->visibleItems(Item::TYPE_TODO)
                ->orderBy('position')
                ->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('To-Do') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form wire:submit="add" class="space-y-3">
                    <div class="flex gap-3">
                        <x-text-input wire:model="title" class="block w-full" type="text" placeholder="{{ __('New task...') }}" />
                        <x-primary-button type="submit">{{ __('Add') }}</x-primary-button>
                    </div>
                    <x-input-error :messages="$errors->get('title')" />
                    <x-private-checkbox id="isPrivate" wire:model="isPrivate" />
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                <ul
                    x-sort="$wire.updateOrder($event.target.sortable.toArray())"
                    x-sort:config="{ animation: 150, handle: '[x-sort\\:handle]' }"
                    class="space-y-2"
                >
                    @forelse ($todos as $todo)
                        <li
                            x-sort:item="{{ $todo->id }}"
                            wire:key="todo-{{ $todo->id }}"
                            class="flex items-center gap-3 border border-gray-200 dark:border-gray-700 rounded-md p-3"
                        >
                            <span x-sort:handle class="cursor-grab select-none text-gray-400" title="{{ __('Drag to reorder') }}">⠿</span>

                            <input
                                type="checkbox"
                                wire:click="toggle({{ $todo->id }})"
                                @checked($todo->done)
                                class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500"
                            />

                            <span class="flex-1 {{ $todo->done ? 'line-through text-gray-400' : 'text-gray-900 dark:text-gray-100' }}">
                                {{ $todo->title }}
                            </span>

                            @if ($todo->visibilityCanBeChangedBy(auth()->user()))
                                <button
                                    type="button"
                                    wire:click="toggleVisibility({{ $todo->id }})"
                                    title="{{ $todo->isPrivate() ? __('Share with household') : __('Make private') }}"
                                    class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                                >
                                    @if ($todo->isPrivate())
                                        <x-private-badge />
                                    @else
                                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M14.5 1A4.5 4.5 0 0010 5.5V9H3a2 2 0 00-2 2v6a2 2 0 002 2h10a2 2 0 002-2v-6a2 2 0 00-2-2h-1.5V5.5a3 3 0 116 0v2.75a.75.75 0 001.5 0V5.5A4.5 4.5 0 0014.5 1z" clip-rule="evenodd" />
                                        </svg>
                                        <span class="sr-only">{{ __('Make private') }}</span>
                                    @endif
                                </button>
                            @elseif ($todo->isPrivate())
                                <x-private-badge />
                            @endif

                            <button wire:click="delete({{ $todo->id }})" wire:confirm="{{ __('Delete this task?') }}" class="text-sm text-red-600 dark:text-red-400 underline">
                                {{ __('Delete') }}
                            </button>
                        </li>
                    @empty
                        <li class="text-gray-500 dark:text-gray-400 text-sm">{{ __('No tasks yet.') }}</li>
                    @endforelse
                </ul>
            </div>

        </div>
    </div>
</div>
