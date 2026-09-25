<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Item;
use App\Models\Tag;
use App\Support\NoteMarkdown;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $tag = '';

    /**
     * The note shown in the detail panel (?note=ID, also the target of wiki links).
     */
    #[Url(as: 'note', except: null)]
    public ?int $selectedId = null;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $body = '';

    public string $tagsInput = '';

    public bool $isPrivate = false;

    /**
     * @var array<string, string>
     */
    protected $listeners = ['create-note' => 'create'];

    public function mount(): void
    {
        // Missing wiki links point here with ?new=Title to create that note.
        $newTitle = request()->query('new');
        $newTitle = is_string($newTitle) ? trim($newTitle) : '';

        if ($newTitle !== '') {
            $this->create();
            $this->title = mb_substr($newTitle, 0, 255);
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'tagsInput' => ['nullable', 'string', 'max:1000'],
        ]);

        $attributes = [
            'title' => $validated['title'],
            'content' => ['body' => $validated['body'] ?? ''],
        ];
        $visibility = $this->isPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED;

        if ($this->editingId) {
            $note = $this->findVisibleItem(Item::TYPE_NOTE, $this->editingId);

            if ($note->visibilityCanBeChangedBy(Auth::user())) {
                $attributes['visibility'] = $visibility;
            }

            $note->update($attributes);
        } else {
            $note = Item::create($attributes + [
                'household_id' => $this->householdId,
                'type' => Item::TYPE_NOTE,
                'visibility' => $visibility,
                'created_by' => Auth::id(),
            ]);
        }

        $note->syncTagNames(explode(',', $this->tagsInput));

        $this->resetForm();
        $this->selectedId = $note->id;
    }

    public function select(int $id): void
    {
        $this->resetForm();
        $this->selectedId = $this->findVisibleItem(Item::TYPE_NOTE, $id)->id;
    }

    public function edit(int $id): void
    {
        $note = $this->findVisibleItem(Item::TYPE_NOTE, $id);

        $this->editingId = $note->id;
        $this->selectedId = $note->id;
        $this->title = $note->title;
        $this->body = $note->body();
        $this->tagsInput = $note->tags->sortBy('name')->pluck('name')->implode(', ');
        $this->isPrivate = $note->isPrivate();
        $this->showForm = true;
    }

    public function delete(int $id): void
    {
        $this->findVisibleItem(Item::TYPE_NOTE, $id)->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        if ($this->selectedId === $id) {
            $this->selectedId = null;
        }
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function filterByTag(string $name): void
    {
        $this->tag = $this->tag === $name ? '' : $name;
    }

    private function resetForm(): void
    {
        $this->reset(['title', 'body', 'tagsInput', 'isPrivate', 'editingId', 'showForm']);
        $this->resetValidation();
    }

    public function with(): array
    {
        $notes = $this->visibleItems(Item::TYPE_NOTE)
            ->with('tags')
            ->when($this->search !== '', fn ($query) => $query->matching($this->search))
            ->when($this->tag !== '', fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->where('name', $this->tag)))
            ->latest('updated_at')
            ->get();

        $selectedNote = $this->selectedId
            ? $this->visibleItems(Item::TYPE_NOTE)->with('tags', 'creator')->find($this->selectedId)
            : null;

        return [
            'notes' => $notes,
            'tags' => Tag::usedOnItemsVisibleTo(Auth::user(), $this->householdId, Item::TYPE_NOTE)
                ->orderBy('name')
                ->pluck('name'),
            'selectedNote' => $selectedNote,
            'selectedNoteHtml' => $selectedNote ? NoteMarkdown::toHtml($selectedNote, Auth::user()) : null,
            'backlinks' => $selectedNote ? $selectedNote->backlinksVisibleTo(Auth::user()) : collect(),
            'canChangeVisibility' => ! $this->editingId
                || (bool) $this->visibleItems(Item::TYPE_NOTE)->find($this->editingId)?->visibilityCanBeChangedBy(Auth::user()),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('Notes') }}
            </h2>
            {{-- The header slot is rendered by the layout, outside this component, so wire:click would not reach it. --}}
            <x-primary-button type="button" x-data x-on:click="Livewire.dispatch('create-note')">{{ __('New note') }}</x-primary-button>
        </div>
    </x-slot>

    <div class="py-8 sm:py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 grid gap-6 lg:grid-cols-3">

            {{-- List --}}
            <div class="space-y-4 lg:col-span-1 {{ $showForm || $selectedNote ? 'hidden lg:block' : '' }}">
                <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 space-y-3">
                    <x-text-input wire:model.live.debounce.300ms="search" class="block w-full" type="search" placeholder="{{ __('Search notes...') }}" />

                    @if ($tags->isNotEmpty())
                        <div class="flex flex-wrap gap-1">
                            @foreach ($tags as $tagName)
                                <button
                                    type="button"
                                    wire:click="filterByTag(@js($tagName))"
                                    wire:key="tag-filter-{{ $tagName }}"
                                    @class([
                                        'text-xs rounded-full px-2 py-0.5',
                                        'bg-indigo-600 text-white' => $tag === $tagName,
                                        'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' => $tag !== $tagName,
                                    ])
                                >#{{ $tagName }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <ul class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg divide-y divide-gray-100 dark:divide-gray-700" wire:loading.class="opacity-50">
                    @forelse ($notes as $note)
                        <li wire:key="note-{{ $note->id }}">
                            <button
                                type="button"
                                wire:click="select({{ $note->id }})"
                                @class([
                                    'w-full text-start p-4 hover:bg-gray-50 dark:hover:bg-gray-700/50',
                                    'bg-indigo-50 dark:bg-gray-700' => $selectedNote?->id === $note->id,
                                ])
                            >
                                <div class="flex items-center gap-2">
                                    <h4 class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $note->title }}</h4>
                                    @if ($note->isPrivate())
                                        <x-private-badge />
                                    @endif
                                </div>
                                @if ($note->body() !== '')
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1 line-clamp-2">{{ \App\Support\NoteMarkdown::excerpt($note->body()) }}</p>
                                @endif
                                @if ($note->tags->isNotEmpty())
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        @foreach ($note->tags->sortBy('name') as $noteTag)
                                            <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-full px-2 py-0.5">#{{ $noteTag->name }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </button>
                        </li>
                    @empty
                        <li class="p-4 text-gray-500 dark:text-gray-400 text-sm">
                            {{ $search !== '' || $tag !== '' ? __('No notes match your filter.') : __('No notes yet.') }}
                        </li>
                    @endforelse
                </ul>
            </div>

            {{-- Detail / form --}}
            <div class="lg:col-span-2">
                @if ($showForm)
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            {{ $editingId ? __('Edit note') : __('New note') }}
                        </h3>

                        <form wire:submit="save" class="space-y-4">
                            <div>
                                <x-input-label for="title" :value="__('Title')" />
                                <x-text-input wire:model="title" id="title" class="block mt-1 w-full" type="text" required autofocus />
                                <x-input-error :messages="$errors->get('title')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="body" :value="__('Content')" />
                                <textarea wire:model="body" id="body" rows="12" class="block mt-1 w-full font-mono text-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 rounded-md shadow-sm"></textarea>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ __('Markdown is supported: **bold**, *italic*, # headings, - lists, [ ] checklists. Link other notes with [[Note title]].') }}
                                </p>
                                <x-input-error :messages="$errors->get('body')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="tagsInput" :value="__('Tags (comma-separated)')" />
                                <x-text-input wire:model="tagsInput" id="tagsInput" class="block mt-1 w-full" type="text" placeholder="{{ __('idea, gift, travel') }}" />
                                <x-input-error :messages="$errors->get('tagsInput')" class="mt-2" />
                            </div>

                            @if ($canChangeVisibility)
                                <x-private-checkbox id="isPrivate" wire:model="isPrivate" />
                            @endif

                            <div class="flex items-center gap-3">
                                <x-primary-button type="submit">
                                    {{ $editingId ? __('Save changes') : __('Add note') }}
                                </x-primary-button>

                                <button type="button" wire:click="cancelEdit" class="text-sm text-gray-500 dark:text-gray-400 underline">
                                    {{ __('Cancel') }}
                                </button>
                            </div>
                        </form>
                    </div>
                @elseif ($selectedNote)
                    <article class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-6">
                        <div class="flex flex-wrap justify-between items-start gap-4">
                            <div>
                                <button type="button" wire:click="$set('selectedId', null)" class="lg:hidden mb-2 text-sm text-gray-500 dark:text-gray-400 underline">
                                    &larr; {{ __('All notes') }}
                                </button>
                                <h3 class="text-2xl font-semibold text-gray-900 dark:text-gray-100 flex items-center gap-2">
                                    {{ $selectedNote->title }}
                                    @if ($selectedNote->isPrivate())
                                        <x-private-badge />
                                    @endif
                                </h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    {{ __('Updated :time', ['time' => $selectedNote->updated_at->diffForHumans()]) }}
                                    @if ($selectedNote->creator)
                                        · {{ __('by :name', ['name' => $selectedNote->creator->name]) }}
                                    @endif
                                </p>
                            </div>
                            <div class="flex gap-3 shrink-0 text-sm">
                                <button type="button" wire:click="edit({{ $selectedNote->id }})" class="text-indigo-600 dark:text-indigo-400 underline">{{ __('Edit') }}</button>
                                <button type="button" wire:click="delete({{ $selectedNote->id }})" wire:confirm="{{ __('Delete this note?') }}" class="text-red-600 dark:text-red-400 underline">{{ __('Delete') }}</button>
                            </div>
                        </div>

                        @if ($selectedNote->tags->isNotEmpty())
                            <div class="flex flex-wrap gap-1">
                                @foreach ($selectedNote->tags->sortBy('name') as $noteTag)
                                    <button type="button" wire:click="filterByTag(@js($noteTag->name))" class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-full px-2 py-0.5">#{{ $noteTag->name }}</button>
                                @endforeach
                            </div>
                        @endif

                        @if ($selectedNote->body() !== '')
                            <div class="note-content">{!! $selectedNoteHtml !!}</div>
                        @else
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This note has no content yet.') }}</p>
                        @endif

                        <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Linked from') }}</h4>
                            @forelse ($backlinks as $backlink)
                                <button type="button" wire:key="backlink-{{ $backlink->id }}" wire:click="select({{ $backlink->id }})" class="block mt-1 text-sm text-indigo-600 dark:text-indigo-400 underline">
                                    {{ $backlink->title }}
                                </button>
                            @empty
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('No other note links here yet.') }}</p>
                            @endforelse
                        </div>
                    </article>
                @else
                    <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 text-sm text-gray-600 dark:text-gray-400 space-y-3 hidden lg:block">
                        <p>{{ __('Select a note on the left or create a new one.') }}</p>
                        <p>{{ __('Tip: write [[Note title]] to link notes. Links to notes that do not exist yet let you create them with one click.') }}</p>
                    </div>
                @endif
            </div>

        </div>
    </div>
</div>
