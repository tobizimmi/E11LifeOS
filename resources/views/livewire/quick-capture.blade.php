<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Support\QuickCapture;
use Livewire\Volt\Component;

new class extends Component
{
    use InteractsWithHousehold;

    public string $title = '';

    public bool $isPrivate = false;

    public function capture(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        QuickCapture::inbox($this->currentUser(), (int) $this->householdId, $validated['title'], $this->isPrivate);

        $this->reset('title');
        $this->dispatch('item-captured');
    }
}; ?>

<form
    x-data
    x-on:submit.prevent="
        const input = document.getElementById('quick-capture-title');
        if (navigator.onLine) { $wire.capture() }
        else if (input.value.trim()) {
            window.E11Offline.add('inbox', input.value.trim(), { private: document.getElementById('quick-capture-private')?.checked ?? false });
            input.value = ''; $wire.set('title', '', false);
            $dispatch('toast', { message: @js(__('Saved offline – it will be sent once you are online.')) });
        }
    "
    class="space-y-2"
>
    <div class="flex gap-3">
        <x-text-input
            id="quick-capture-title"
            wire:model="title"
            class="block w-full"
            type="text"
            placeholder="{{ __('What is on your mind? (#tags optional)') }}"
            aria-label="{{ __('Quick capture') }}"
        />
        <x-voice-button for="quick-capture-title" :submit="true" />
        <x-primary-button type="submit">{{ __('Capture') }}</x-primary-button>
    </div>
    <x-input-error :messages="$errors->get('title')" />
    <div class="flex items-center justify-between gap-3">
        <x-private-checkbox id="quick-capture-private" wire:model="isPrivate" />
        <x-action-message on="item-captured" class="text-green-600 dark:text-green-400">
            {{ __('Saved to inbox.') }}
        </x-action-message>
    </div>
</form>
