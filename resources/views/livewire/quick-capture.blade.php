<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Support\QuickCapture;
use Livewire\Volt\Component;

new class extends Component
{
    use InteractsWithHousehold;

    public string $title = '';

    public bool $isPrivate = false;

    /**
     * "inbox" (sort later) or "task" (date, !priority, @name are read from the text).
     */
    public string $target = 'inbox';

    public function capture(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'target' => ['required', 'in:inbox,task'],
        ]);

        $item = $validated['target'] === 'task'
            ? QuickCapture::task($this->currentUser(), (int) $this->householdId, $validated['title'], private: $this->isPrivate)
            : QuickCapture::inbox($this->currentUser(), (int) $this->householdId, $validated['title'], $this->isPrivate);

        if (! $item) {
            $this->addError('title', __('Please enter a title.'));

            return;
        }

        $this->reset('title');
        $this->dispatch('item-captured');
        $this->dispatch('toast', message: $validated['target'] === 'task' ? __('Task added') : __('Saved to inbox.'));
    }
}; ?>

<form
    x-data
    x-on:submit.prevent="
        const input = document.getElementById('quick-capture-title');
        if (navigator.onLine) { $wire.capture() }
        else if (input.value.trim()) {
            window.E11Offline.add($wire.target, input.value.trim(), { private: document.getElementById('quick-capture-private')?.checked ?? false });
            input.value = ''; $wire.set('title', '', false);
            $dispatch('toast', { message: @js(__('Saved offline – it will be sent once you are online.')) });
        }
    "
    class="space-y-2"
>
    <div class="flex gap-1.5 text-sm" role="radiogroup" aria-label="{{ __('Save as') }}">
        @foreach (['inbox' => '📥 '.__('Inbox'), 'task' => '✅ '.__('Task')] as $value => $label)
            <button type="button" role="radio" aria-checked="{{ $target === $value ? 'true' : 'false' }}" wire:click="$set('target', '{{ $value }}')" @class([
                'rounded-full px-3 py-1',
                'bg-indigo-600 text-white' => $target === $value,
                'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200' => $target !== $value,
            ])>{{ $label }}</button>
        @endforeach
    </div>
    <div class="flex gap-3">
        <x-text-input
            id="quick-capture-title"
            wire:model="title"
            class="block w-full"
            type="text"
            placeholder="{{ $target === 'task' ? __('New task … e.g. “Call dentist tomorrow 6 pm !high”') : __('What is on your mind? (#tags optional)') }}"
            aria-label="{{ __('Quick capture') }}"
        />
        <x-voice-button for="quick-capture-title" :submit="true" />
        <x-primary-button type="submit">{{ __('Capture') }}</x-primary-button>
    </div>
    <x-input-error :messages="$errors->get('title')" />
    <div class="flex items-center justify-between gap-3">
        <x-private-checkbox id="quick-capture-private" wire:model="isPrivate" />
        <span class="text-xs text-gray-400">{{ $target === 'task' ? __('Dates, times, !priority, @name and #tags are recognised.') : __('Sort it into tasks, pages or the shopping list later.') }}</span>
    </div>
</form>
