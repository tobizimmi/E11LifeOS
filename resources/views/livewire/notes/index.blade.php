<?php

use App\Models\Item;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public int $householdId;

    public string $search = '';

    public string $title = '';

    public string $body = '';

    public string $tagsInput = '';

    public ?int $editingId = null;

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

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
        ]);

        $tags = $this->parseTags();

        if ($this->editingId) {
            $item = Item::where('household_id', $this->householdId)
                ->ofType(Item::TYPE_NOTE)
                ->findOrFail($this->editingId);

            $item->update([
                'title' => $validated['title'],
                'content' => ['body' => $validated['body']],
                'tags' => $tags,
            ]);
        } else {
            Item::create([
                'household_id' => $this->householdId,
                'type' => Item::TYPE_NOTE,
                'title' => $validated['title'],
                'content' => ['body' => $validated['body']],
                'tags' => $tags,
                'created_by' => Auth::id(),
            ]);
        }

        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $item = Item::where('household_id', $this->householdId)
            ->ofType(Item::TYPE_NOTE)
            ->findOrFail($id);

        $this->editingId = $item->id;
        $this->title = $item->title;
        $this->body = $item->content['body'] ?? '';
        $this->tagsInput = implode(', ', $item->tags ?? []);
    }

    public function delete(int $id): void
    {
        Item::where('household_id', $this->householdId)
            ->ofType(Item::TYPE_NOTE)
            ->findOrFail($id)
            ->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['title', 'body', 'tagsInput', 'editingId']);
    }

    private function parseTags(): array
    {
        return collect(explode(',', $this->tagsInput))
            ->map(fn ($tag) => trim($tag))
            ->filter()
            ->values()
            ->all();
    }

    public function with(): array
    {
        $notes = Item::where('household_id', $this->householdId)
            ->ofType(Item::TYPE_NOTE)
            ->when($this->search !== '', function ($query) {
                $term = '%'.$this->search.'%';
                $query->where(function ($query) use ($term) {
                    $query->where('title', 'like', $term)
                        ->orWhereRaw('LOWER(JSON_EXTRACT(content, "$.body")) LIKE ?', [strtolower($term)])
                        ->orWhereRaw('LOWER(tags) LIKE ?', [strtolower($term)]);
                });
            })
            ->latest('updated_at')
            ->get();

        return ['notes' => $notes];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Notes') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                    {{ $editingId ? __('Edit note') : __('New note') }}
                </h3>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <x-input-label for="title" :value="__('Title')" />
                        <x-text-input wire:model="title" id="title" class="block mt-1 w-full" type="text" required />
                        <x-input-error :messages="$errors->get('title')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="body" :value="__('Content')" />
                        <textarea wire:model="body" id="body" rows="4" class="block mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"></textarea>
                        <x-input-error :messages="$errors->get('body')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="tagsInput" :value="__('Tags (comma-separated)')" />
                        <x-text-input wire:model="tagsInput" id="tagsInput" class="block mt-1 w-full" type="text" placeholder="idea, gift, travel" />
                    </div>

                    <div class="flex items-center gap-3">
                        <x-primary-button type="submit">
                            {{ $editingId ? __('Save changes') : __('Add note') }}
                        </x-primary-button>

                        @if ($editingId)
                            <button type="button" wire:click="cancelEdit" class="text-sm text-gray-500 dark:text-gray-400 underline">
                                {{ __('Cancel') }}
                            </button>
                        @endif
                    </div>
                </form>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                <x-text-input wire:model.live.debounce.300ms="search" class="block w-full mb-4" type="search" placeholder="{{ __('Search notes...') }}" />

                <ul class="space-y-3" wire:loading.class="opacity-50">
                    @forelse ($notes as $note)
                        <li class="border border-gray-200 dark:border-gray-700 rounded-md p-4" wire:key="note-{{ $note->id }}">
                            <div class="flex justify-between items-start gap-4">
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $note->title }}</h4>
                                    @if ($note->content['body'] ?? null)
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 whitespace-pre-line">{{ $note->content['body'] }}</p>
                                    @endif
                                    @if (! empty($note->tags))
                                        <div class="mt-2 flex flex-wrap gap-1">
                                            @foreach ($note->tags as $tag)
                                                <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-full px-2 py-0.5">{{ $tag }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <div class="flex gap-2 shrink-0 text-sm">
                                    <button wire:click="edit({{ $note->id }})" class="text-indigo-600 dark:text-indigo-400 underline">{{ __('Edit') }}</button>
                                    <button wire:click="delete({{ $note->id }})" wire:confirm="{{ __('Delete this note?') }}" class="text-red-600 dark:text-red-400 underline">{{ __('Delete') }}</button>
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="text-gray-500 dark:text-gray-400 text-sm">{{ __('No notes yet.') }}</li>
                    @endforelse
                </ul>
            </div>

        </div>
    </div>
</div>
