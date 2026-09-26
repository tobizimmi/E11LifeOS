<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\ShoppingListEntry;
use App\Support\ProductLookup;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public string $label = '';

    public string $quantity = '';

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

    /**
     * Fills the add form with the scanned product (the user still confirms with "Add").
     */
    public function lookupBarcode(string $code, ProductLookup $lookup): void
    {
        $code = preg_replace('/\D/', '', $code);
        $product = $lookup->byBarcode($code);

        if (! $product) {
            $this->dispatch('toast', message: ProductLookup::isValidBarcode($code)
                ? __('Product :code not found – please enter it yourself.', ['code' => $code])
                : __('That is not a valid barcode.'));

            return;
        }

        $this->label = $product['name'];
        $this->quantity = $product['quantity'];
        $this->dispatch('toast', message: __(':name found.', ['name' => $product['name']]));
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
                    <x-text-input id="shopping-label" wire:model="label" class="block w-full" type="text" placeholder="{{ __('Item...') }}" />
                    <x-text-input wire:model="quantity" class="block w-32" type="text" placeholder="{{ __('Qty') }}" />
                    <x-voice-button for="shopping-label" />
                    <button type="button" x-data x-on:click="$dispatch('open-barcode-scanner')" class="shrink-0 rounded-md border border-gray-300 dark:border-gray-600 px-3 text-lg" aria-label="{{ __('Scan barcode') }}" title="{{ __('Scan barcode') }}">📷</button>
                    <x-primary-button type="submit">{{ __('Add') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('label')" class="mt-2" />

                {{-- Barcode scanner: camera via the browser's BarcodeDetector, typing the number as fallback --}}
                <div
                    x-data="barcodeScanner"
                    x-on:open-barcode-scanner.window="open()"
                    x-on:keydown.escape.window="close()"
                    x-show="visible"
                    x-cloak
                    class="fixed inset-0 z-[60] flex flex-col bg-black text-white"
                    role="dialog"
                    aria-modal="true"
                    aria-label="{{ __('Scan barcode') }}"
                >
                    <div class="flex items-center justify-between px-4 py-3">
                        <p class="font-medium">{{ __('Scan barcode') }}</p>
                        <button type="button" x-on:click="close()" class="text-2xl" aria-label="{{ __('Close') }}">✕</button>
                    </div>
                    <div class="relative flex-1 overflow-hidden">
                        <video x-ref="video" x-show="supported && ! failed" playsinline muted class="h-full w-full object-cover"></video>
                        <div x-show="supported && ! failed" class="pointer-events-none absolute inset-0 flex items-center justify-center">
                            <div class="h-28 w-64 rounded-lg border-4 border-white/90 shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]"></div>
                        </div>
                        <p x-show="! supported || failed" class="p-6 text-center text-gray-300" x-text="failed ? @js(__('The camera could not be started. Please allow camera access or type the number.')) : @js(__('This browser cannot open the camera here. Type the number below the barcode instead.'))"></p>
                    </div>
                    <form x-on:submit.prevent="submitManual()" class="flex gap-2 bg-gray-900 p-4">
                        <input x-model="manualCode" inputmode="numeric" pattern="[0-9]*" class="block flex-1 rounded-md border-gray-700 bg-gray-800 text-white" placeholder="{{ __('Barcode number, e.g. 4000417025005') }}" aria-label="{{ __('Barcode number') }}" />
                        <button type="submit" class="rounded-md bg-indigo-600 px-4 font-medium">{{ __('Find product') }}</button>
                    </form>
                </div>
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
