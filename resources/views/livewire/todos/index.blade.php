<?php

use App\Models\Item;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public int $householdId;

    public string $title = '';

    public function mount(): void
    {
        $household = Auth::user()->currentHousehold();
        abort_if(! $household, 403);

        $this->householdId = $household->id;
    }

    #[On('echo-private:household.{householdId},.items.updated')]
    public function refreshFromBroadcast(): void
    {
        //
    }

    public function add(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $maxPosition = (int) Item::where('household_id', $this->householdId)
            ->ofType(Item::TYPE_TODO)
            ->max('position');

        Item::create([
            'household_id' => $this->householdId,
            'type' => Item::TYPE_TODO,
            'title' => $validated['title'],
            'created_by' => Auth::id(),
            'position' => $maxPosition + 1,
        ]);

        $this->reset('title');
    }

    public function toggle(int $id): void
    {
        $todo = Item::where('household_id', $this->householdId)
            ->ofType(Item::TYPE_TODO)
            ->findOrFail($id);

        $todo->update(['done' => ! $todo->done]);
    }

    public function delete(int $id): void
    {
        Item::where('household_id', $this->householdId)
            ->ofType(Item::TYPE_TODO)
            ->findOrFail($id)
            ->delete();
    }

    public function updateOrder(array $orderedIds): void
    {
        $ownedIds = Item::where('household_id', $this->householdId)
            ->ofType(Item::TYPE_TODO)
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
            'todos' => Item::where('household_id', $this->householdId)
                ->ofType(Item::TYPE_TODO)
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
                <form wire:submit="add" class="flex gap-3">
                    <x-text-input wire:model="title" class="block w-full" type="text" placeholder="{{ __('New task...') }}" />
                    <x-primary-button type="submit">{{ __('Add') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('title')" class="mt-2" />
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
