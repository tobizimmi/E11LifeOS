<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\Collection;
use App\Models\Item;
use App\Support\CollectionTemplates;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public bool $showForm = false;

    public string $name = '';

    public string $icon = '';

    public string $template = 'blank';

    public bool $isPrivate = false;

    /**
     * ?create=1 (sidebar "+") opens the form right away.
     */
    public function mount(): void
    {
        if (request()->query('create') === '1') {
            $this->showForm = true;
        }
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:16'],
            'template' => ['required', Rule::in(array_keys(CollectionTemplates::all()))],
        ]);

        $collection = Collection::create([
            'household_id' => $this->householdId,
            'name' => trim($validated['name']),
            'icon' => trim((string) ($validated['icon'] ?? '')) ?: CollectionTemplates::all()[$validated['template']]['icon'],
            'visibility' => $this->isPrivate ? Item::VISIBILITY_PRIVATE : Item::VISIBILITY_SHARED,
            'created_by' => Auth::id(),
        ]);

        CollectionTemplates::apply($collection, $validated['template']);

        $this->redirectRoute('collections.show', ['collection' => $collection->id], navigate: true);
    }

    public function with(): array
    {
        // Make sure the built-in tasks collection exists for this household.
        Collection::tasksFor($this->householdId);

        return [
            'collections' => Collection::query()
                ->visibleTo(Auth::user(), $this->householdId)
                ->withCount(['items' => fn ($items) => $items->forHousehold($this->householdId)->visibleTo(Auth::user())])
                ->orderByRaw('system_key IS NULL')
                ->orderBy('name')
                ->get(),
            'templates' => CollectionTemplates::all(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Collections') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-10">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="flex items-center justify-between gap-3">
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Collections are lists with your own properties – as a table or a board.') }}</p>
                @unless ($showForm)
                    <x-primary-button type="button" wire:click="$set('showForm', true)">{{ __('New collection') }}</x-primary-button>
                @endunless
            </div>

            @if ($showForm)
                <form wire:submit="create" class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ __('New collection') }}</h3>

                    <div class="flex gap-3">
                        <div class="w-20 shrink-0">
                            <x-input-label for="icon" :value="__('Icon')" />
                            <x-text-input wire:model="icon" id="icon" class="mt-1 block w-full text-center text-xl" maxlength="16" placeholder="{{ $templates[$template]['icon'] ?? '🗂️' }}" />
                        </div>
                        <div class="flex-1">
                            <x-input-label for="name" :value="__('Name')" />
                            <x-text-input wire:model="name" id="name" class="mt-1 block w-full" required autofocus placeholder="{{ __('e.g. Contracts, Projects, Books') }}" />
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('name')" />

                    <fieldset>
                        <legend class="block font-medium text-sm text-gray-700 dark:text-gray-300">{{ __('Template') }}</legend>
                        <div class="mt-2 grid gap-3 sm:grid-cols-2">
                            @foreach ($templates as $key => $templateInfo)
                                <label wire:key="template-{{ $key }}" @class([
                                    'flex cursor-pointer gap-3 rounded-lg border p-3',
                                    'border-indigo-500 ring-1 ring-indigo-500 bg-indigo-50/50 dark:bg-gray-700' => $template === $key,
                                    'border-gray-200 dark:border-gray-700' => $template !== $key,
                                ])>
                                    <input type="radio" wire:model.live="template" value="{{ $key }}" class="mt-1 text-indigo-600" />
                                    <span>
                                        <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $templateInfo['icon'] }} {{ $templateInfo['label'] }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $templateInfo['description'] }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <x-private-checkbox id="isPrivate" wire:model="isPrivate" />

                    <div class="flex items-center gap-3">
                        <x-primary-button>{{ __('Create collection') }}</x-primary-button>
                        <button type="button" wire:click="$set('showForm', false)" class="text-sm text-gray-500 dark:text-gray-400 underline">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endif

            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($collections as $collection)
                    <li wire:key="collection-{{ $collection->id }}">
                        <a href="{{ route('collections.show', $collection) }}" wire:navigate class="block rounded-lg bg-white dark:bg-gray-800 p-5 shadow-sm hover:ring-2 hover:ring-indigo-300">
                            <div class="flex items-center gap-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                <span aria-hidden="true">{{ $collection->displayIcon() }}</span>
                                <span class="truncate">{{ $collection->name }}</span>
                                @if ($collection->isPrivate())
                                    <x-private-badge />
                                @endif
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                {{ trans_choice(':count entry|:count entries', $collection->items_count) }}
                                @if ($collection->isTasks())
                                    · {{ __('built-in') }}
                                @endif
                            </p>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
