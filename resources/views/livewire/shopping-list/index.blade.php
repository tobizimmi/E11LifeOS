<?php

use App\Models\ShoppingListEntry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public int $householdId;

    public string $label = '';

    public string $quantity = '';

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
            'label' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:255'],
        ]);

        ShoppingListEntry::create([
            'household_id' => $this->householdId,
            'label' => $validated['label'],
            'quantity' => $validated['quantity'] ?: null,
            'created_by' => Auth::id(),
        ]);

        $this->reset('label', 'quantity');
    }

    public function toggle(int $id): void
    {
        $entry = ShoppingListEntry::where('household_id', $this->householdId)->findOrFail($id);

        $entry->update(['checked' => ! $entry->checked]);
    }

    public function delete(int $id): void
    {
        ShoppingListEntry::where('household_id', $this->householdId)->findOrFail($id)->delete();
    }

    public function with(): array
    {
        return [
            'entries' => ShoppingListEntry::where('household_id', $this->householdId)
                ->orderBy('checked')
                ->latest()
                ->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Shopping List') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                <form wire:submit="add" class="flex gap-3">
                    <x-text-input wire:model="label" class="block w-full" type="text" placeholder="{{ __('Item...') }}" />
                    <x-text-input wire:model="quantity" class="block w-32" type="text" placeholder="{{ __('Qty') }}" />
                    <x-primary-button type="submit">{{ __('Add') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('label')" class="mt-2" />
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                <p class="text-xs text-gray-400 dark:text-gray-500 mb-3 sm:hidden">{{ __('Swipe an item left to delete it.') }}</p>

                <ul class="space-y-2">
                    @forelse ($entries as $entry)
                        <li
                            wire:key="entry-{{ $entry->id }}"
                            x-data="{
                                dragX: 0,
                                startX: 0,
                                dragging: false,
                                start(e) { this.startX = e.touches[0].clientX; this.dragging = true },
                                move(e) {
                                    if (! this.dragging) return;
                                    this.dragX = Math.min(0, Math.max(-96, e.touches[0].clientX - this.startX));
                                },
                                end() {
                                    this.dragging = false;
                                    if (this.dragX < -64) { $wire.delete({{ $entry->id }}) }
                                    this.dragX = 0;
                                },
                            }"
                            class="relative overflow-hidden rounded-md"
                        >
                            <div class="absolute inset-y-0 right-0 w-24 bg-red-600 flex items-center justify-center text-white text-sm">
                                {{ __('Delete') }}
                            </div>

                            <div
                                @touchstart="start($event)"
                                @touchmove="move($event)"
                                @touchend="end()"
                                :style="`transform: translateX(${dragX}px)`"
                                class="relative bg-white dark:bg-gray-800 flex items-center gap-3 border border-gray-200 dark:border-gray-700 rounded-md p-3 transition-transform"
                            >
                                <input
                                    type="checkbox"
                                    wire:click="toggle({{ $entry->id }})"
                                    @checked($entry->checked)
                                    class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                />

                                <span class="flex-1 {{ $entry->checked ? 'line-through text-gray-400' : 'text-gray-900 dark:text-gray-100' }}">
                                    {{ $entry->label }}
                                    @if ($entry->quantity)
                                        <span class="text-sm text-gray-500 dark:text-gray-400">({{ $entry->quantity }})</span>
                                    @endif
                                </span>

                                <button wire:click="delete({{ $entry->id }})" class="hidden sm:inline text-sm text-red-600 dark:text-red-400 underline">
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </li>
                    @empty
                        <li class="text-gray-500 dark:text-gray-400 text-sm">{{ __('Shopping list is empty.') }}</li>
                    @endforelse
                </ul>
            </div>

        </div>
    </div>
</div>
