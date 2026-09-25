<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use Illuminate\Support\Facades\Auth;
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

        // "#tags" in the text become tags of the entry.
        [$title, $tags] = Item::splitHashtags($validated['title']);

        $item = Item::create([
            'household_id' => $this->householdId,
            'type' => Item::TYPE_INBOX,
            'visibility' => $this->isPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'title' => $title,
            'created_by' => Auth::id(),
        ]);
        $item->syncTagNames($tags);

        $this->reset('title');
        $this->dispatch('item-captured');
    }
}; ?>

<form wire:submit="capture" class="space-y-2">
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
