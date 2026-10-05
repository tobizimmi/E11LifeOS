<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Support\QuickCapture;
use App\Support\SmartCapture;
use Livewire\Volt\Component;

new class extends Component
{
    use InteractsWithHousehold;

    public string $title = '';

    public bool $isPrivate = false;

    /**
     * "auto" (recognised from the text: shopping, expense, device, task …), "inbox" (sort later)
     * or "task" (date, !priority, @name are read from the text).
     */
    public string $target = 'auto';

    public function capture(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'target' => ['required', 'in:auto,inbox,task'],
        ]);

        if ($validated['target'] === 'auto') {
            ['message' => $message, 'undo' => $undo] = SmartCapture::file($this->currentUser(), (int) $this->householdId, $validated['title'], $this->isPrivate);

            if ($message === null) {
                $this->addError('title', __('Please enter a title.'));

                return;
            }

            $this->reset('title');
            $this->dispatch('item-captured');
            $this->dispatch('toast', message: $message, action: $undo ? ['label' => __('Undo'), 'event' => 'capture-undo', 'params' => ['undo' => $undo]] : null);

            return;
        }

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
    /**
     * "Undo" in the toast after an automatic capture.
     *
     * @param  array{kind: string, ids: list<int>}  $undo
     */
    #[\Livewire\Attributes\On('capture-undo')]
    public function undoCapture(array $undo): void
    {
        SmartCapture::undo($this->currentUser(), (int) $this->householdId, $undo);
        $this->dispatch('item-captured');
    }

    public function with(): array
    {
        return [
            'detected' => $this->target === 'auto' && trim($this->title) !== '' && $this->householdId
                ? SmartCapture::preview($this->currentUser(), (int) $this->householdId, $this->title)
                : null,
        ];
    }
}; ?>

<form
    x-data
    x-on:submit.prevent="
        const input = document.getElementById('quick-capture-title');
        if (navigator.onLine) { $wire.capture() }
        else if (input.value.trim()) {
            window.E11Offline.add($wire.target === 'task' ? 'task' : 'inbox', input.value.trim(), { private: document.getElementById('quick-capture-private')?.checked ?? false });
            input.value = ''; $wire.set('title', '', false);
            $dispatch('toast', { message: @js(__('Saved offline – it will be sent once you are online.')) });
        }
    "
    class="space-y-2"
>
    <div class="flex gap-1.5 text-sm" role="radiogroup" aria-label="{{ __('Save as') }}">
        @foreach (['auto' => '✨ '.__('Automatic'), 'inbox' => '📥 '.__('Inbox'), 'task' => '✅ '.__('Task')] as $value => $label)
            <button type="button" role="radio" aria-checked="{{ $target === $value ? 'true' : 'false' }}" wire:click="$set('target', '{{ $value }}')" @class([
                'rounded-full px-3 py-1',
                'bg-indigo-600 text-white' => $target === $value,
                'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200' => $target !== $value,
            ])>{{ $label }}</button>
        @endforeach
    </div>
    {{-- Phones: the field gets the full width, the buttons go below it. --}}
    <div class="flex flex-wrap gap-2 sm:flex-nowrap sm:gap-3">
        <x-text-input
            id="quick-capture-title"
            wire:model.live.debounce.400ms="title"
            class="block w-full min-w-0 basis-full sm:basis-auto sm:flex-1"
            type="text"
            placeholder="{{ match ($target) { 'task' => __('New task … e.g. “Call dentist tomorrow 6 pm !high”'), 'auto' => __('e.g. “Buy: milk, bread” · “50 € fuel” · “Dentist Thu 10 am” · “Living room light on”'), default => __('What is on your mind? (#tags optional)') } }}"
            aria-label="{{ __('Quick capture') }}"
        />
        <x-voice-button for="quick-capture-title" :submit="true" />
        <x-primary-button type="submit" class="flex-1 justify-center sm:flex-none">{{ __('Capture') }}</x-primary-button>
    </div>
    <x-input-error :messages="$errors->get('title')" />
    <div class="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
        <x-private-checkbox id="quick-capture-private" wire:model="isPrivate" />
        <span class="text-xs text-gray-400">
            @if ($detected)
                {{ __('Becomes:') }} <span class="font-medium text-indigo-600 dark:text-indigo-400">{{ $detected['label'] }}</span>
            @elseif ($target === 'auto')
                {{ __('Shopping list, expense, appointment, device or inbox – recognised from the text.') }}
            @else
                {{ $target === 'task' ? __('Dates, times, !priority, @name and #tags are recognised.') : __('Sort it into tasks, pages or the shopping list later.') }}
            @endif
        </span>
    </div>
</form>
